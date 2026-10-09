<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\OsvAuditScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Exercises OSV's artifact and HTTP contracts without public network access.
 */
class OsvAuditScannerTest extends AuditTestCase
{
    private OsvAuditScanner $scanner;
    private GenericTask $task;
    private MockHandler $responses;
    private array $requests = [];

    /**
     * Initializes a real workbench and controlled OSV transport.
     * 
     * {@inheritDoc}
     * 
     * @see AuditTestCase::setUp()
     */
    protected function setUp() : void
    {
        parent::setUp();
        $this->responses = new MockHandler();
        $handler = HandlerStack::create($this->responses);
        $handler->push(Middleware::history($this->requests));
        $client = new Client(['handler' => $handler]);
        $this->scanner = new class($this->workbench, $client) extends OsvAuditScanner {
            private Client $client;

            /**
             * Supplies a transport while retaining real scanner behavior.
             * 
             * @param \exface\Core\Interfaces\WorkbenchInterface $workbench
             * @param Client $client
             */
            public function __construct($workbench, Client $client)
            {
                parent::__construct($workbench);
                $this->client = $client;
            }

            /**
             * {@inheritDoc}
             * 
             * @see OsvAuditScanner::createHttpClient()
             */
            protected function createHttpClient() : Client
            {
                return $this->client;
            }
        };
        $this->task = new GenericTask($this->workbench);
    }

    /**
     * Supplies structured artifacts through the task's bulk parameter API.
     * 
     * @param string $name
     * @param array<string, mixed> $value
     * @return void
     */
    private function setArtifact(string $name, array $value) : void
    {
        $this->task->importUxonObject(new UxonObject(['parameters' => [$name => $value]]));
    }

    /**
     * Both supplied artifacts contribute queries, while overlapping packages query once.
     * 
     * @return void
     */
    public function testBothArtifactsAreScannedAndNativeEvidenceIsRetained() : void
    {
        $this->setArtifact('composer_lock', ['packages' => [['name' => 'vendor/php', 'version' => 'v1.0.0']],
            'packages-dev' => [['name' => 'npm-asset/scope--library', 'version' => '2.0.0']]]);
        $this->setArtifact('sbom', ['bomFormat' => 'CycloneDX', 'components' => [
            ['name' => 'php', 'purl' => 'pkg:composer/vendor/php@1.0.0'],
            ['name' => 'bundled', 'purl' => 'pkg:npm/bundled@3.0.0']
        ]]);
        $this->task->setParameter('folder', 'nonexistent-folder');
        self::assertTrue($this->scanner->supports($this->task));
        $this->responses->append(new Response(200, [], json_encode(['results' => [[], [], ['vulns' => [['id' => 'GHSA-test']]]]])));
        $this->responses->append(new Response(200, [], json_encode([
            'id' => 'GHSA-test', 'summary' => 'Bundled vulnerability', 'details' => 'Full details',
            'aliases' => ['CVE-2026-1234'], 'database_specific' => ['severity' => 'HIGH'],
            'affected' => [
                ['package' => ['ecosystem' => 'npm', 'name' => 'other'], 'ranges' => [['type' => 'SEMVER', 'events' => [['fixed' => '99.0.0']]]]],
                ['package' => ['ecosystem' => 'npm', 'name' => 'bundled'], 'ranges' => [['type' => 'SEMVER', 'events' => [['introduced' => '0'], ['fixed' => '4.0.0']]]]]
            ]
        ])));
        $findings = $this->scanner->audit($this->task);
        self::assertCount(1, $findings);
        self::assertSame('npm-asset/bundled', $findings[0]->getPackage());
        self::assertSame('CVE-2026-1234', $findings[0]->getPublicId());
        self::assertSame('GHSA-test', $findings[0]->getSourceId());
        self::assertSame('osv', $findings[0]->getSource());
        self::assertSame('3.0.0', $findings[0]->getVersionInstalled());
        self::assertSame('4.0.0', $findings[0]->getVersionFixed());
        self::assertSame(300, $findings[0]->getLevel());
        self::assertSame('Full details', $findings[0]->getDescription());
        self::assertCount(2, $this->requests);
        self::assertSame(['queries' => [
            ['package' => ['ecosystem' => 'Packagist', 'name' => 'vendor/php'], 'version' => '1.0.0'],
            ['package' => ['ecosystem' => 'npm', 'name' => '@scope/library'], 'version' => '2.0.0'],
            ['package' => ['ecosystem' => 'npm', 'name' => 'bundled'], 'version' => '3.0.0']
        ]], json_decode((string) $this->requests[0]['request']->getBody(), true));
        self::assertSame('https://api.osv.dev/v1/querybatch', (string) $this->requests[0]['request']->getUri());
    }

    /**
     * Combined JSON remains a valid SBOM source without CycloneDX conversion.
     * 
     * @return void
     */
    public function testCombinedJsonAndCoverageGaps() : void
    {
        $this->setArtifact('sbom', ['packages' => [
            ['name' => 'npm-asset/mermaid', 'version' => '11.0.0'],
            ['name' => 'unknown/library', 'version' => '1.0.0'],
            ['name' => 'vendor/dev', 'version' => 'dev-main', 'type' => 'library',
                'source' => ['type' => 'git', 'reference' => str_repeat('a', 40)]]
        ]]);
        $this->responses->append(new Response(200, [], '{"results":[{},{}]}'));
        $findings = $this->scanner->audit($this->task);
        self::assertCount(1, $findings);
        self::assertSame('unscannable', $findings[0]->getType());
        self::assertSame(100, $findings[0]->getLevel());
        self::assertSame('unknown/library', $findings[0]->getPackage());
        self::assertSame('1.0.0', $findings[0]->getVersionInstalled());
        self::assertSame('', $findings[0]->getPublicId());
        $queries = json_decode((string) $this->requests[0]['request']->getBody(), true)['queries'];
        self::assertSame(['commit' => str_repeat('a', 40)], $queries[1]);
        self::assertCount(1, $this->scanner->getHints());
        self::assertStringContainsString('unknown/library', $this->scanner->getHints()[0]);
    }

    /**
     * Pagination follows only incomplete queries and downloads shared advisories once.
     * 
     * @return void
     */
    public function testPaginationAndWithdrawnRecords() : void
    {
        $this->setArtifact('composer_lock', ['packages' => [['name' => 'vendor/php', 'version' => '1.0.0']]]);
        $this->responses->append(
            new Response(200, [], '{"results":[{"vulns":[{"id":"OSV-1"}],"next_page_token":"next"}]}'),
            new Response(200, [], '{"id":"OSV-1","summary":"Withdrawn","withdrawn":"2026-01-01T00:00:00Z"}'),
            new Response(200, [], '{"results":[{"vulns":[{"id":"OSV-1"},{"id":"OSV-2"}]}]}'),
            new Response(200, [], '{"id":"OSV-2","summary":"Active"}')
        );
        $findings = $this->scanner->audit($this->task);
        self::assertCount(1, $findings);
        self::assertSame('OSV-2', $findings[0]->getSourceId());
        self::assertCount(4, $this->requests);
        self::assertSame('next', json_decode((string) $this->requests[2]['request']->getBody(), true)['queries'][0]['page_token']);
    }

    /**
     * Empty artifacts avoid HTTP and invalid explicit input never falls back.
     * 
     * @return void
     */
    public function testEmptyAndInvalidArtifacts() : void
    {
        $this->setArtifact('composer_lock', ['packages' => []]);
        self::assertFalse($this->scanner->supports($this->task));
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(0, $this->requests);
        $this->task->setParameter('sbom', null);
        $this->expectException(RuntimeException::class);
        $this->scanner->audit($this->task);
    }

    /**
     * Malformed responses cannot be mistaken for zero vulnerabilities.
     * 
     * @return void
     */
    public function testMissingBatchResultsFail() : void
    {
        $this->setArtifact('composer_lock', ['packages' => [['name' => 'vendor/php', 'version' => '1.0.0']]]);
        $this->responses->append(new Response(200, [], '{}'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('results must match');
        $this->scanner->audit($this->task);
    }

    /**
     * Folder scanning uses the lock plus combined JSON without modifying either.
     * 
     * @return void
     */
    public function testFolderReadsBothInventories() : void
    {
        $folder = $this->temporaryFolder();
        $vendor = $folder . '/vendor';
        self::assertTrue(mkdir($vendor));
        $lock = '{"packages":[{"name":"vendor/php","version":"1.0.0"}]}';
        $bom = '{"packages":[{"name":"npm-asset/bundled","version":"2.0.0"}]}';
        $this->writeFixture($folder, 'composer.lock', $lock);
        $this->writeFixture($vendor, 'licenses.json', $bom);
        try {
            $this->task->setParameter('folder', $folder);
            self::assertTrue($this->scanner->supports($this->task));
            $this->responses->append(new Response(200, [], '{"results":[{},{}]}'));
            self::assertSame([], $this->scanner->audit($this->task));
            $this->scanner->install($this->task);
            self::assertSame($lock, file_get_contents($folder . '/composer.lock'));
            self::assertSame($bom, file_get_contents($vendor . '/licenses.json'));
            self::assertFileDoesNotExist($folder . '/composer.json');
            self::assertCount(2, json_decode((string) $this->requests[0]['request']->getBody(), true)['queries']);
        } finally {
            unlink($vendor . '/licenses.json');
            rmdir($vendor);
        }
    }

    /**
     * Nested components preserve multiple installed versions and scoped npm identities.
     * 
     * @return void
     */
    public function testNestedComponentsAndScopedPackageUrls() : void
    {
        $this->setArtifact('sbom', ['bomFormat' => 'CycloneDX', 'components' => [
            ['name' => 'scope/library', 'purl' => 'pkg:npm/@scope/library@1.0.0', 'components' => [
                ['name' => 'scope/library', 'purl' => 'pkg:npm/%40scope/library@2.0.0']
            ]]
        ]]);
        $this->responses->append(new Response(200, [], '{"results":[{},{}]}'));
        self::assertSame([], $this->scanner->audit($this->task));
        $queries = json_decode((string) $this->requests[0]['request']->getBody(), true)['queries'];
        self::assertSame(['ecosystem' => 'npm', 'name' => '@scope/library'], $queries[0]['package']);
        self::assertSame($queries[0]['package'], $queries[1]['package']);
        self::assertSame('1.0.0', $queries[0]['version']);
        self::assertSame('2.0.0', $queries[1]['version']);
    }

    /**
     * SPDX package URLs use a separate version field, never duplicate PURL versions.
     * 
     * @return void
     */
    public function testSpdxPackageUrl() : void
    {
        $this->setArtifact('sbom', ['spdxVersion' => 'SPDX-2.3', 'packages' => [
            ['name' => 'requests', 'versionInfo' => '2.0.0', 'externalRefs' => [
                ['referenceType' => 'purl', 'referenceLocator' => 'pkg:pypi/requests@2.0.0']
            ]]
        ]]);
        $this->responses->append(new Response(200, [], '{"results":[{}]}'));
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertSame(['queries' => [['package' => ['purl' => 'pkg:pypi/requests'], 'version' => '2.0.0']]],
            json_decode((string) $this->requests[0]['request']->getBody(), true));
    }

    /**
     * Components lacking a registry identity remain visible through coverage hints.
     * 
     * @return void
     */
    public function testUnqueryableInventoryStillReportsHints() : void
    {
        $this->setArtifact('sbom', ['packages' => [['name' => 'unknown/library', 'version' => 'dev-main']]]);
        self::assertTrue($this->scanner->supports($this->task));
        $findings = $this->scanner->audit($this->task);
        self::assertCount(1, $findings);
        self::assertSame('unscannable', $findings[0]->getType());
        self::assertSame(100, $findings[0]->getLevel());
        self::assertSame('osv', $findings[0]->getSource());
        self::assertSame('', $findings[0]->getPublicId());
        self::assertSame('dev-main', $findings[0]->getVersionInstalled());
        self::assertStringContainsString('supported package identity', $findings[0]->getRemediation());
        self::assertCount(1, $this->scanner->audit($this->task));
        self::assertCount(1, $this->scanner->getHints());
        self::assertCount(0, $this->requests);
    }

    /**
     * Registry outages propagate instead of reporting a clean scan.
     * 
     * @return void
     */
    public function testHttpFailurePropagates() : void
    {
        $this->setArtifact('composer_lock', ['packages' => [['name' => 'vendor/php', 'version' => '1.0.0']]]);
        $this->responses->append(new Response(503, [], 'Unavailable'));
        $this->expectException(ServerException::class);
        $this->scanner->audit($this->task);
    }

    /**
     * Repeated tokens fail rather than causing an unbounded pagination loop.
     * 
     * @return void
     */
    public function testRepeatedPaginationTokenFails() : void
    {
        $this->setArtifact('composer_lock', ['packages' => [['name' => 'vendor/php', 'version' => '1.0.0']]]);
        $this->responses->append(
            new Response(200, [], '{"results":[{"next_page_token":"same"}]}'),
            new Response(200, [], '{"results":[{"next_page_token":"same"}]}')
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pagination token');
        $this->scanner->audit($this->task);
    }

    /**
     * Large inventories are split into bounded batches without losing packages.
     * 
     * @return void
     */
    public function testLargeInventoryIsBatched() : void
    {
        $packages = [];
        for ($index = 0; $index < 101; $index++) {
            $packages[] = ['name' => 'vendor/package-' . $index, 'version' => '1.0.0'];
        }
        $this->setArtifact('composer_lock', ['packages' => $packages]);
        $this->responses->append(
            new Response(200, [], json_encode(['results' => array_fill(0, 100, new \stdClass())])),
            new Response(200, [], '{"results":[{}]}')
        );
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(2, $this->requests);
        self::assertCount(100, json_decode((string) $this->requests[0]['request']->getBody(), true)['queries']);
        self::assertCount(1, json_decode((string) $this->requests[1]['request']->getBody(), true)['queries']);
    }

    /**
     * A shared record is fetched once, but each package keeps its own CVE findings.
     * 
     * @return void
     */
    public function testSharedAdvisoryIsFetchedOnceAndCveAliasesAreRetained() : void
    {
        $this->setArtifact('composer_lock', ['packages' => [
            ['name' => 'vendor/first', 'version' => '1.0.0'],
            ['name' => 'vendor/second', 'version' => '2.0.0']
        ]]);
        $this->responses->append(
            new Response(200, [], '{"results":[{"vulns":[{"id":"OSV-shared"}]},{"vulns":[{"id":"OSV-shared"}]}]}'),
            new Response(200, [], '{"id":"OSV-shared","aliases":["CVE-2026-1234","CVE-2026-5678"]}')
        );
        $findings = $this->scanner->audit($this->task);
        self::assertCount(2, $this->requests);
        self::assertCount(4, $findings);
        self::assertSame('vendor/first', $findings[0]->getPackage());
        self::assertSame('vendor/second', $findings[2]->getPackage());
        self::assertSame('CVE-2026-1234', $findings[0]->getCve());
        self::assertSame('CVE-2026-5678', $findings[1]->getCve());
    }
}
