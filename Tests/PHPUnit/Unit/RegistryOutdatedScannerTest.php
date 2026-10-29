<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\NpmOutdatedScanner;
use axenox\PackageManager\Common\Audit\Scanner\PackagistOutdatedScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use exface\Core\Exceptions\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies registry-specific release decisions at the HTTP boundary without network access.
 */
class RegistryOutdatedScannerTest extends AuditTestCase
{
    /**
    * npm latest must produce optional findings with the original locked version and no blocker claims.
     * 
     * @return void
     */
    public function testNpmLatestFromSavedBom() : void
    {
        $handler = new MockHandler([new Response(200, [], '{"version":"2.0.0"}')]);
        $scanner = new class($this->workbench) extends NpmOutdatedScanner {
            public Client $client;

            /**
             * {@inheritDoc}
             * 
             * @see NpmOutdatedScanner::createHttpClient()
             */
            protected function createHttpClient() : Client
            {
                return $this->client;
            }
        };
        $scanner->client = new Client(['handler' => HandlerStack::create($handler)]);
        $scanner->setCacheTtlSeconds(0);
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => [
            ['name' => '@example/library', 'ecosystem' => 'npm', 'version' => 'v1.0.0'],
            ['name' => 'example/private', 'ecosystem' => 'private', 'version' => '1.0.0']
        ]]]]));
        self::assertTrue($scanner->supports($task));
        self::assertCount(1, $handler);
        $findings = $scanner->audit($task);
        self::assertCount(1, $findings);
        self::assertSame('npm-asset/example--library', $findings[0]->getPackage());
        self::assertSame('npm', $findings[0]->getEcosystem());
        self::assertSame('outdated', $findings[0]->getType());
        self::assertSame(50, $findings[0]->getLevel());
        self::assertSame('v1.0.0', $findings[0]->getVersionInstalled());
        self::assertSame('2.0.0', $findings[0]->getVersionFixed());
        self::assertStringContainsString('were not evaluated', $findings[0]->getDescription());
        self::assertSame('https://registry.npmjs.org/%40example%2Flibrary/latest', (string) $handler->getLastRequest()->getUri());
        self::assertSame([], $scanner->getHints());
    }

    /**
     * Packagist sorting and prerelease exclusion must not depend on the metadata response order.
     * 
     * @return void
     */
    public function testPackagistSelectsHighestStableRelease() : void
    {
        $scanner = new PackagistOutdatedScanner($this->workbench);
        $data = ['packages' => ['example/library' => [
            ['version' => '1.9.0'], ['version' => '3.0.0-RC1'], ['version' => 'v2.10.0'],
            ['version' => '2.9.0'], ['version' => 'dev-main']
        ]]];
        self::assertSame('v2.10.0', $this->invokeProtected($scanner, 'getLatestVersion', ['example/library', $data]));
    }

    /**
     * Branch references and constraints must not be guessed into released versions.
     * 
     * @return void
     */
    public function testVersionNormalizationRejectsUnorderedIdentities() : void
    {
        $scanner = new NpmOutdatedScanner($this->workbench);
        foreach (['', 'dev-main', '^1.0', '1.x-dev', str_repeat('a', 40)] as $version) {
            self::assertNull($this->invokeProtected($scanner, 'normalizeVersion', [$version]));
        }
        self::assertSame('2.10.0.0', $this->invokeProtected($scanner, 'normalizeVersion', ['v2.10.0']));
    }

    /**
     * Distinguishes major upgrades from other updates while retaining original version spelling.
     * 
     * @param string $installed
     * @param string $latest
     * @param string $title
     * @return void
     */
    #[DataProvider('findingTitleVersions')]
    public function testFindingTitleIncludesInstalledAndUpstreamVersions(string $installed, string $latest, string $title) : void
    {
        $scanner = $this->npmScanner(new MockHandler([new Response(200, [], json_encode(['version' => $latest], JSON_THROW_ON_ERROR))]));
        $findings = $scanner->audit($this->task(['sbom' => ['packages' => [
            ['name' => 'library', 'ecosystem' => 'npm', 'version' => $installed]
        ]]]));
        self::assertCount(1, $findings);
        self::assertSame($title, $findings[0]->getName());
        self::assertSame(50, $findings[0]->getLevel());
    }

    /**
     * Covers numeric major comparisons, smaller updates and prerelease-to-stable transitions.
     * 
     * @return array<string, array{string, string, string}>
     */
    public static function findingTitleVersions() : array
    {
        return [
            'major' => ['v1.0.0', '2.0.0', 'New major version 2.0.0 available - currently on v1.0.0'],
            'numeric major' => ['9.0.0', 'v10.0.0', 'New major version v10.0.0 available - currently on 9.0.0'],
            'minor' => ['1.9.0', 'v1.10.0', 'New version v1.10.0 available - currently on 1.9.0'],
            'patch' => ['1.2.3', '1.2.4', 'New version 1.2.4 available - currently on 1.2.3'],
            'zero major' => ['0.1.0', '0.2.0', 'New version 0.2.0 available - currently on 0.1.0'],
            'stable' => ['2.0.0-beta.1', '2.0.0', 'New version 2.0.0 available - currently on 2.0.0-beta.1']
        ];
    }

    /**
     * Supplies a fresh HTTP boundary while retaining the real scanner and Workbench.
     * 
     * @param MockHandler $responses
     * @return NpmOutdatedScanner
     */
    private function npmScanner(MockHandler $responses) : NpmOutdatedScanner
    {
        $scanner = new class($this->workbench) extends NpmOutdatedScanner {
            public Client $client;

            /**
             * {@inheritDoc}
             * 
             * @see NpmOutdatedScanner::createHttpClient()
             */
            protected function createHttpClient() : Client
            {
                return $this->client;
            }
        };
        $scanner->client = new Client(['handler' => HandlerStack::create($responses)]);
        $scanner->setCacheTtlSeconds(0);
        return $scanner;
    }

    /**
     * Keeps archived artifacts structured without relying on setParameter's string-only PHPDoc.
     * 
     * @param array<string, mixed> $parameters
     * @return GenericTask
     */
    private function task(array $parameters) : GenericTask
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => $parameters]));
        return $task;
    }

    /**
     * Multiple installed versions share one lookup, but each retains its own release comparison.
     * 
     * @return void
     */
    public function testDuplicateAliasesQueryOnceAndDoNotFlagCurrentOrNewerVersions() : void
    {
        $responses = new MockHandler([new Response(200, [], '{"version":"2.0.0"}')]);
        $scanner = $this->npmScanner($responses);
        $findings = $scanner->audit($this->task([
            'composer_lock' => ['packages' => [['name' => 'npm-asset/library', 'version' => '1.0.0']],
                'packages-dev' => [['name' => 'npm-asset/library', 'version' => '3.0.0']]],
            'sbom' => ['packages' => [
                ['name' => 'library', 'ecosystem' => 'npm', 'version' => '1.0.0'],
                ['name' => 'library', 'ecosystem' => 'npm', 'version' => '2.0.0']
            ]]
        ]));
        self::assertCount(1, $findings);
        self::assertSame('1.0.0', $findings[0]->getVersionInstalled());
        self::assertCount(0, $responses);
    }

    /**
     * Private PURLs in one artifact must suppress matching public-looking aliases in another.
     * 
     * @return void
     */
    public function testNestedPrivatePurlExcludesMatchingComposerAlias() : void
    {
        $responses = new MockHandler();
        $scanner = $this->npmScanner($responses);
        $task = $this->task([
            'composer_lock' => ['packages' => [['name' => 'npm-asset/example--private', 'version' => '1.0.0']]],
            'sbom' => ['bomFormat' => 'CycloneDX', 'components' => [
                ['name' => 'parent', 'components' => [
                    ['name' => 'private', 'version' => '1.0.0', 'purl' => 'pkg:npm/%40example/private@1.0.0',
                        'properties' => [['name' => 'axenox:packagemanager:ecosystem', 'value' => 'private']]]
                ]]
            ]]
        ]);
        self::assertFalse($scanner->supports($task));
        self::assertSame([], $scanner->audit($task));
        self::assertNull($responses->getLastRequest());
    }

    /**
     * Standard BOMs identify ecosystems through PURLs, including npm scopes and metadata roots.
     * 
     * @return void
     */
    public function testCycloneDxAndSpdxIdentities() : void
    {
        $scanner = new NpmOutdatedScanner($this->workbench);
        $cyclone = $this->task(['sbom' => ['bomFormat' => 'CycloneDX',
            'metadata' => ['component' => ['name' => 'root', 'purl' => 'pkg:npm/@example/root@1.0.0']],
            'components' => [['name' => 'ignored', 'purl' => 'pkg:nuget/Other@1.0.0'],
                ['name' => 'parent', 'components' => [['name' => 'child', 'purl' => 'pkg:npm/%40example/child@2.0.0']]]]
        ]]);
        self::assertSame([
            '@example/child' => ['2.0.0' => '2.0.0.0'], '@example/root' => ['1.0.0' => '1.0.0.0']
        ], $this->invokeProtected($scanner, 'getDependencies', [$cyclone]));
        $spdx = $this->task(['sbom' => ['spdxVersion' => 'SPDX-2.3', 'packages' => [
            ['name' => 'display name', 'versionInfo' => 'v1.2.3', 'externalRefs' => [
                ['referenceType' => 'purl', 'referenceLocator' => 'pkg:npm/%40example/library@1.2.3?download_url=ignored']
            ]]
        ]]]);
        self::assertSame(['@example/library' => ['v1.2.3' => '1.2.3.0']], $this->invokeProtected($scanner, 'getDependencies', [$spdx]));
    }

    /**
    * Missing releases and versions stay visible, but development versions are intentionally excluded.
     * 
     * @return void
     */
    public function testMissingReleaseAndBranchVersionsProduceCoverageHints() : void
    {
        $responses = new MockHandler([new Response(404)]);
        $scanner = $this->npmScanner($responses);
        $task = $this->task(['sbom' => ['packages' => [
            ['name' => 'unpublished', 'ecosystem' => 'npm', 'version' => '1.0.0'],
            ['name' => 'branch', 'ecosystem' => 'npm', 'version' => 'dev-main'],
            ['name' => 'unversioned', 'ecosystem' => 'npm']
        ]]]);
        self::assertTrue($scanner->supports($task));
        self::assertSame([], $scanner->audit($task));
        self::assertCount(2, $scanner->getHints());
        self::assertCount(0, $responses);
        self::assertStringContainsString('unpublished', implode(' ', $scanner->getHints()));
        self::assertStringNotContainsString('dev-main', implode(' ', $scanner->getHints()));
    }

    /**
     * An explicit archived inventory must not be supplemented with the local folder's packages.
     * 
     * @return void
     */
    public function testExplicitArtifactsPreventFolderFallback() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'composer.lock', '{"packages":[{"name":"npm-asset/local","version":"1.0.0"}]}');
        $scanner = $this->npmScanner(new MockHandler());
        self::assertFalse($scanner->supports($this->task(['folder' => $folder, 'sbom' => ['packages' => []]])));
        self::assertTrue($scanner->supports($this->task(['folder' => $folder])));
        $this->expectException(RuntimeException::class);
        $scanner->supports($this->task(['folder' => $folder, 'composer_lock' => null]));
    }

    /**
     * Registry and JSON failures must never be presented as successful scans with no findings.
     * 
     * @param int $status
     * @param string $body
     * @param string $message
     * @return void
     */
    #[DataProvider('failedResponses')]
    public function testFailuresPropagate(int $status, string $body, string $message) : void
    {
        $scanner = $this->npmScanner(new MockHandler([new Response($status, [], $body)]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $scanner->audit($this->task(['sbom' => ['packages' => [['name' => 'library', 'ecosystem' => 'npm', 'version' => '1.0.0']]]]));
    }

    /**
     * Distinguishes outages, rate limits, unsafe redirects and malformed metadata.
     * 
     * @return array<string, array{int, string, string}>
     */
    public static function failedResponses() : array
    {
        return [
            'outage' => [503, '{}', 'HTTP 503'],
            'rate limit' => [429, '{}', 'HTTP 429'],
            'redirect' => [302, '{}', 'HTTP 302'],
            'invalid JSON' => [200, '{', 'Invalid scanner JSON'],
            'missing version' => [200, '{}', 'no comparable version']
        ];
    }

    /**
     * Connection failures must preserve diagnostics inside an ExFace exception.
     * 
     * @return void
     */
    public function testTransportFailuresRetainTheirCause() : void
    {
        $error = new ConnectException('Connection refused', new Request('GET', 'https://registry.npmjs.org/library/latest'));
        $scanner = $this->npmScanner(new MockHandler([$error]));
        try {
            $scanner->audit($this->task(['sbom' => ['packages' => [['name' => 'library', 'ecosystem' => 'npm', 'version' => '1.0.0']]]]));
            self::fail('A connection failure was swallowed.');
        } catch (RuntimeException $failure) {
            self::assertSame($error, $failure->getPrevious());
            self::assertStringContainsString('Connection refused', $failure->getMessage());
        }
    }

    /**
     * Shared release metadata must be reused across builds while comparisons use each build's version.
     * 
     * @return void
     */
    public function testWorkbenchCacheReusesOnlyRegistryMetadata() : void
    {
        $name = 'audit-cache-' . bin2hex(random_bytes(6));
        $key = 'registry-outdated-v1-' . hash('sha256', 'https://registry.npmjs.org/' . $name . '/latest');
        $scanner = $this->npmScanner(new MockHandler([new Response(200, [], '{"version":"2.0.0"}')]));
        $scanner->setCacheTtlSeconds(300);
        $parameters = ['sbom' => ['packages' => [['name' => $name, 'ecosystem' => 'npm', 'version' => '1.0.0']]]];
        try {
            self::assertCount(1, $scanner->audit($this->task($parameters)));
            $second = $this->npmScanner(new MockHandler());
            $second->setCacheTtlSeconds(300);
            $parameters['sbom']['packages'][0]['version'] = '2.0.0';
            self::assertSame([], $second->audit($this->task($parameters)));
            self::assertSame([], $second->getHints());
        } finally {
            $this->workbench->getCache()->delete($key);
        }
    }

    /**
     * Packagist must compare minimal Composer inventories without querying other ecosystems.
     * 
     * @return void
     */
    public function testPackagistHttpAuditFromMinimalInventory() : void
    {
        $responses = new MockHandler([new Response(200, [], json_encode(['packages' => ['example/library' => [
            ['version' => '1.9.0'], ['version' => '3.0.0-beta1'], ['version' => 'v2.10.0'], ['version' => '2.9.0']
        ]]], JSON_THROW_ON_ERROR))]);
        $scanner = new class($this->workbench) extends PackagistOutdatedScanner {
            public Client $client;

            /**
             * {@inheritDoc}
             * 
             * @see PackagistOutdatedScanner::createHttpClient()
             */
            protected function createHttpClient() : Client
            {
                return $this->client;
            }
        };
        $scanner->client = new Client(['handler' => HandlerStack::create($responses)]);
        $scanner->setCacheTtlSeconds(0);
        $task = $this->task(['sbom' => ['packages' => [
            ['name' => 'example/library', 'ecosystem' => 'composer', 'version' => '2.9.0'],
            ['name' => 'example/private', 'ecosystem' => 'private', 'version' => '1.0.0'],
            ['name' => 'jquery', 'ecosystem' => 'npm', 'version' => '1.0.0'],
            ['name' => 'bower-asset/library', 'version' => '1.0.0'],
            ['name' => 'Other', 'ecosystem' => 'nuget', 'version' => '1.0.0']
        ]]]);
        self::assertTrue($scanner->supports($task));
        $findings = $scanner->audit($task);
        self::assertCount(1, $findings);
        self::assertSame('packagist', $findings[0]->getSource());
        self::assertSame(50, $findings[0]->getLevel());
        self::assertSame('composer', $findings[0]->getEcosystem());
        self::assertSame('example/library', $findings[0]->getPackage());
        self::assertSame('v2.10.0', $findings[0]->getVersionFixed());
        self::assertSame('https://repo.packagist.org/p2/example/library.json', (string) $responses->getLastRequest()->getUri());
        self::assertSame([], $scanner->getHints());
    }

    /**
     * Unstable-only packages must not be promoted to stable or mistaken for malformed responses.
     * 
     * @return void
     */
    public function testNoStablePackagistReleaseIsACoverageGap() : void
    {
        $scanner = new PackagistOutdatedScanner($this->workbench);
        self::assertNull($this->invokeProtected($scanner, 'getLatestVersion', ['example/library', ['packages' => [
            'example/library' => [['version' => 'dev-main'], ['version' => '2.0.0-beta.1']]
        ]]]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no version list');
        $this->invokeProtected($scanner, 'getLatestVersion', ['example/library', ['packages' => []]]);
    }

    /**
    * Supported prereleases compare normally, unsupported labels stay visible and dev versions are excluded.
     * 
     * @return void
     */
    public function testPrereleaseAndBranchComparisons() : void
    {
        $scanner = $this->npmScanner(new MockHandler([new Response(200, [], '{"version":"2.0.0"}')]));
        $task = $this->task(['sbom' => ['packages' => [
            ['name' => 'library', 'ecosystem' => 'npm', 'version' => '2.0.0-beta.1.2'],
            ['name' => 'library', 'ecosystem' => 'npm', 'version' => '2.0.0-next.1'],
            ['name' => 'library', 'ecosystem' => 'npm', 'version' => '2.0-dev']
        ]]]);
        self::assertCount(1, $scanner->audit($task));
        self::assertCount(1, $scanner->getHints());
    }

    /**
     * Both registries must skip development versions without requests, findings or coverage hints.
     * 
     * @return void
     */
    public function testDevelopmentVersionsAreExcludedFromBothScanners() : void
    {
        $responses = new MockHandler();
        $scanners = ['npm' => $this->npmScanner($responses), 'composer' => new PackagistOutdatedScanner($this->workbench)];
        foreach ($scanners as $ecosystem => $scanner) {
            $scanner->setCacheTtlSeconds(0);
            foreach (['dev-master', 'dev-main', 'dev-1.x-dev', '1.x-dev', '2.0-dev', 'DEV-main'] as $version) {
                $task = $this->task(['sbom' => ['packages' => [
                    ['name' => $ecosystem === 'npm' ? '@example/library' : 'example/library', 'ecosystem' => $ecosystem, 'version' => $version]
                ]]]);
                self::assertFalse($scanner->supports($task), $ecosystem . ': ' . $version);
                self::assertSame([], $scanner->audit($task));
                self::assertSame([], $scanner->getHints());
            }
        }
        self::assertNull($responses->getLastRequest());
    }

    /**
     * An independently configured short cache lifetime must not accept an older shared entry.
     * 
     * @return void
     */
    public function testShorterTtlRefreshesSharedMetadata() : void
    {
        $name = 'audit-cache-' . bin2hex(random_bytes(6));
        $key = 'registry-outdated-v1-' . hash('sha256', 'https://registry.npmjs.org/' . $name . '/latest');
        $scanner = $this->npmScanner(new MockHandler([new Response(200, [], '{"version":"2.0.0"}')]));
        $scanner->setCacheTtlSeconds(300);
        $this->workbench->getCache()->set($key, ['version' => '1.0.0', 'fetched_at' => time() - 400], 86400);
        try {
            $findings = $scanner->audit($this->task(['sbom' => ['packages' => [['name' => $name, 'ecosystem' => 'npm', 'version' => '1.0.0']]]]));
            self::assertCount(1, $findings);
            self::assertSame('2.0.0', $findings[0]->getVersionFixed());
        } finally {
            $this->workbench->getCache()->delete($key);
        }
    }

    /**
     * UXON request limits must reach HTTP without weakening TLS or enabling redirects.
     * 
     * @return void
     */
    public function testUxonOptionsReachRequests() : void
    {
        $responses = new MockHandler([new Response(200, [], '{"version":"1.0.0"}')]);
        $scanner = $this->npmScanner($responses);
        $scanner->importUxonObject(new UxonObject(['timeout_seconds' => 2, 'concurrency' => 1, 'cache_ttl_seconds' => 0]));
        self::assertSame([], $scanner->audit($this->task(['sbom' => ['packages' => [['name' => 'library', 'ecosystem' => 'npm', 'version' => '1.0.0']]]])));
        $options = $responses->getLastOptions();
        self::assertSame(2, $options['timeout']);
        self::assertSame(2, $options['connect_timeout']);
        self::assertTrue($options['verify']);
        self::assertFalse($options['allow_redirects']);
    }

    /**
     * Only the registry scanners should be enabled by default; Composer remains an opt-in backup.
     * 
     * @return void
     */
    public function testDefaultScannerRegistration() : void
    {
        $config = json_decode(file_get_contents(dirname(__DIR__, 3) . '/Config/axenox.PackageManager.config.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(NpmOutdatedScanner::class, ltrim($config['AUDIT.SCANNERS']['npm_outdated']['class'], '\\'));
        self::assertSame(PackagistOutdatedScanner::class, ltrim($config['AUDIT.SCANNERS']['packagist_outdated']['class'], '\\'));
        self::assertArrayNotHasKey('composer_outdated', $config['AUDIT.SCANNERS']);
    }
}
