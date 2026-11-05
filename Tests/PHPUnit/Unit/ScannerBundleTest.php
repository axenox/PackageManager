<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\LicenseScanner;
use axenox\PackageManager\Common\Audit\Scanner\NpmOutdatedScanner;
use axenox\PackageManager\Common\Audit\Scanner\OsvAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\PackagistOutdatedScanner;
use axenox\PackageManager\Common\Audit\Scanner\TrivySBOMScanner;
use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\Common\LicenseBOM\Format\CycloneDxBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureComposerScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureNpmScanner;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\ActionFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies action-owned enrichment of grouped scanner results without changing their originals.
 */
class ScannerBundleTest extends AuditTestCase
{
    /**
     * Supplies structured archived inventories without inspecting the current installation.
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
     * Enriches real reporting groups and verifies that every scanner original stays unchanged.
     * 
     * @param \axenox\PackageManager\Interfaces\FindingInterface[] $findings
     * @param GenericTask $task
     * @return \axenox\PackageManager\Common\Audit\MergedFinding[]
     */
    private function enrich(array $findings, GenericTask $task) : array
    {
        $action = ActionFactory::createFromString($this->workbench, Audit::class);
        $before = array_map(static fn ($finding) => clone $finding, $findings);
        $groups = $this->invokeProtected($action, 'groupFindings', [$findings]);
        $this->invokeProtected($action, 'addBundleInformation', [$groups, $task]);
        foreach ($findings as $index => $finding) {
            self::assertSame('', $finding->getBundledInUrn());
            self::assertTrue($finding->is($before[$index]));
        }
        return $groups;
    }

    /**
     * Both missing-license finding types receive their owning bundle after grouping.
     * 
     * @return void
     */
    public function testLicenseFindingsCarryBundleOwnership() : void
    {
        $task = $this->task(['sbom' => ['packages' => [[
            'name' => 'library', 'ecosystem' => 'npm', 'version' => '1.0.0',
            'bundled_in' => 'composer:example/bundle:2.0.0'
        ]]]]);
        $findings = $this->enrich((new LicenseScanner($this->workbench))->audit($task), $task);
        self::assertCount(2, $findings);
        foreach ($findings as $finding) {
            self::assertSame('license', $finding->getType());
            self::assertSame('composer:example/bundle:2.0.0', $finding->getBundledInUrn());
        }
    }

    /**
     * npm ranges distinguish bundled and standalone copies and exclude unaffected installed versions.
     * 
     * @return void
     */
    public function testNpmFindingsMatchVersionsBeforeAssigningBundles() : void
    {
        $scanner = new FixtureNpmScanner($this->workbench);
        $scanner->client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '{"@scope/library":[{"id":123,"title":"Advisory","severity":"high","vulnerable_versions":"<2"}]}')
        ]))]);
        $task = $this->task(['sbom' => ['packages' => [
            ['name' => '@scope/library', 'ecosystem' => 'npm', 'version' => 'v1.0.0', 'bundled_in' => 'composer:example/bundle:2.0.0'],
            ['name' => '@scope/library', 'ecosystem' => 'npm', 'version' => '1.5.0'],
            ['name' => '@scope/library', 'ecosystem' => 'npm', 'version' => '2.5.0']
        ]]]);
        $findings = $scanner->audit($task);
        self::assertCount(2, $findings);
        self::assertSame(['v1.0.0', '1.5.0'], array_map(static fn ($finding) => $finding->getVersionInstalled(), $findings));
        $findings = $this->enrich($findings, $task);
        self::assertSame(['composer:example/bundle:2.0.0', ''],
            array_map(static fn ($finding) => $finding->getBundledInUrn(), $findings));
    }

    /**
     * Missing ranges cannot be guessed when attributing advisories to installed versions.
     * 
     * @return void
     */
    public function testNpmMissingAffectedRangeIsExplicitError() : void
    {
        $scanner = new FixtureNpmScanner($this->workbench);
        $scanner->client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '{"library":[{"id":123,"title":"Advisory"}]}')
        ]))]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an affected version range');
        $scanner->audit($this->task(['sbom' => ['packages' => [
            ['name' => 'library', 'ecosystem' => 'npm', 'version' => '1.0.0']
        ]]]));
    }

    /**
     * Invalid version ranges must not silently produce clean results or guessed ownership.
     * 
     * @return void
     */
    public function testNpmInvalidAffectedRangeIsExplicitError() : void
    {
        $scanner = new FixtureNpmScanner($this->workbench);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot match npm advisory versions');
        $this->invokeProtected($scanner, 'normalize', [
            ['library' => [['id' => 123, 'vulnerable_versions' => 'not-a-version-range']]],
            ['library' => ['1.0.0']]
        ]);
    }

    /**
     * Reads explicit metadata roots and nested components without inferring ownership from nesting.
     * 
     * @return void
     */
    public function testCycloneDxNestedAndRootBundleEvidence() : void
    {
        $properties = static fn (string $urn, string $owner) => [
            ['name' => 'axenox:packagemanager:urn', 'value' => $urn],
            ['name' => 'axenox:packagemanager:bundled_in', 'value' => $owner]
        ];
        $action = ActionFactory::createFromString($this->workbench, Audit::class);
        $document = [
            'bomFormat' => 'CycloneDX',
            'metadata' => ['component' => ['properties' => $properties('npm:library:1.0.0', 'composer:example/first:2.0.0')]],
            'components' => [['components' => [
                ['properties' => $properties('npm:library:v1.0.0', 'composer:example/second:v2.0.0')],
                ['name' => 'unowned', 'version' => '1.0.0']
            ]]]
        ];
        $bundles = $this->invokeProtected($action, 'getBundleUrnsFromInventory', [$document]);
        self::assertSame([
            'npm:library:v1.0.0' => 'composer:example/second:v2.0.0',
            'npm:library:1.0.0' => 'composer:example/first:2.0.0'
        ], $bundles);
        $original = new Finding('id', 'Advisory', 'vulnerability', 'npm-asset/library', 'npm', 'low', versionInstalled: '1.0.0');
        $findings = $this->enrich([$original], $this->task(['sbom' => $document]));
        self::assertSame('composer:example/first:2.0.0', $findings[0]->getBundledInUrn());
        self::assertSame('', $original->getBundledInUrn());
    }

    /**
     * Malformed component metadata fails explicitly instead of dropping ownership through PHP warnings.
     * 
     * @param array<string, mixed> $document
     * @return void
     */
    #[DataProvider('malformedBundleDocuments')]
    public function testMalformedCycloneDxBundleEvidenceIsExplicitError(array $document) : void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CycloneDX');
        $action = ActionFactory::createFromString($this->workbench, Audit::class);
        $this->invokeProtected($action, 'getBundleUrnsFromInventory', [$document]);
    }

    /**
     * Supplies invalid standard-inventory shapes and incomplete ownership pairs.
     * 
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedBundleDocuments() : array
    {
        return [
            'components not array' => [['bomFormat' => 'CycloneDX', 'components' => 'invalid']],
            'component not object' => [['bomFormat' => 'CycloneDX', 'components' => ['invalid']]],
            'properties not array' => [['bomFormat' => 'CycloneDX', 'components' => [['properties' => 'invalid']]]],
            'property not object' => [['bomFormat' => 'CycloneDX', 'components' => [['properties' => ['invalid']]]]],
            'owner without urn' => [['bomFormat' => 'CycloneDX', 'components' => [['properties' => [
                ['name' => 'axenox:packagemanager:bundled_in', 'value' => 'composer:example/bundle:1.0.0']
            ]]]]],
            'invalid nested components' => [['bomFormat' => 'CycloneDX', 'components' => [['components' => 'invalid']]]]
        ];
    }

    /**
     * Both registry outdated scanners leave bundle enrichment to the Audit action.
     * 
     * @return void
     */
    public function testRegistryReleaseFindingsCarryBundleOwnership() : void
    {
        $npm = new class($this->workbench) extends NpmOutdatedScanner {
            public Client $client;

            /** {@inheritDoc} @see NpmOutdatedScanner::createHttpClient() */
            protected function createHttpClient() : Client { return $this->client; }
        };
        $packagist = new class($this->workbench) extends PackagistOutdatedScanner {
            public Client $client;

            /** {@inheritDoc} @see PackagistOutdatedScanner::createHttpClient() */
            protected function createHttpClient() : Client { return $this->client; }
        };
        foreach ([
            [$npm, 'npm', 'library', '{"version":"2.0.0"}'],
            [$packagist, 'composer', 'example/library', '{"packages":{"example/library":[{"version":"2.0.0"}]}}']
        ] as [$scanner, $ecosystem, $name, $response]) {
            $scanner->client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $response)]))]);
            $scanner->setCacheTtlSeconds(0);
            $task = $this->task(['sbom' => ['packages' => [[
                'name' => $name, 'ecosystem' => $ecosystem, 'version' => '1.0.0',
                'bundled_in' => 'composer:example/bundle:3.0.0'
            ]]]]);
            $findings = $this->enrich($scanner->audit($task), $task);
            self::assertCount(1, $findings);
            self::assertSame('upgradable', $findings[0]->getType());
            self::assertSame('composer:example/bundle:3.0.0', $findings[0]->getBundledInUrn());
        }
    }

    /**
     * Grouped Composer vulnerability and abandoned-package findings receive archived bundle ownership.
     * 
     * @return void
     */
    public function testComposerFindingsCarryBundleOwnership() : void
    {
        $scanner = new FixtureComposerScanner($this->workbench);
        $scanner->response = ['advisories' => ['example/library' => [[
            'cve' => 'CVE-2026-0001', 'title' => 'Advisory', 'severity' => 'high'
        ]]], 'abandoned' => ['example/old' => 'example/new']];
        $packages = [
            ['name' => 'example/library', 'version' => '1.0.0', 'bundled_in' => 'composer:example/bundle:2.0.0'],
            ['name' => 'example/old', 'version' => '1.0.0', 'bundled_in' => 'composer:example/bundle:2.0.0']
        ];
        $task = $this->task([
            'composer_lock' => ['packages' => $packages], 'sbom' => ['packages' => $packages]
        ]);
        $findings = $this->enrich($scanner->audit($task), $task);
        self::assertSame(['vulnerability', 'EOL'], array_map(static fn ($finding) => $finding->getType(), $findings));
        foreach ($findings as $finding) {
            self::assertSame('composer:example/bundle:2.0.0', $finding->getBundledInUrn());
        }
    }

    /**
     * The action adds ownership even to OSV findings for unscannable ecosystems.
     * 
     * @return void
     */
    public function testOsvVulnerabilityAndUnscannableFindingsCarryBundleOwnership() : void
    {
        $scanner = new class($this->workbench) extends OsvAuditScanner {
            public Client $client;

            /** {@inheritDoc} @see OsvAuditScanner::createHttpClient() */
            protected function createHttpClient() : Client { return $this->client; }
        };
        $scanner->client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '{"results":[{"vulns":[{"id":"OSV-fixture"}]}]}'),
            new Response(200, [], '{"id":"OSV-fixture","summary":"Advisory"}')
        ]))]);
        $task = $this->task(['sbom' => ['packages' => [
            ['name' => 'library', 'ecosystem' => 'npm', 'version' => '1.0.0', 'bundled_in' => 'composer:example/bundle:2.0.0'],
            ['name' => 'unknown-library', 'ecosystem' => 'unknown', 'version' => '1.0.0', 'bundled_in' => 'composer:example/bundle:2.0.0']
        ]]]);
        $findings = $this->enrich($scanner->audit($task), $task);
        self::assertSame(['unscannable', 'vulnerability'], array_map(static fn ($finding) => $finding->getType(), $findings));
        foreach ($findings as $finding) {
            self::assertSame('composer:example/bundle:2.0.0', $finding->getBundledInUrn());
        }
    }

    /**
     * The action enriches grouped Trivy findings from generated CycloneDX inventories.
     * 
     * @return void
     */
    public function testTrivyFindingsCarryCycloneDxBundleOwnership() : void
    {
        $scanner = new class($this->workbench) extends TrivySBOMScanner {
            public array $response;

            /** {@inheritDoc} @see TrivySBOMScanner::executable() */
            protected function executable() : ?string { return 'fixture-trivy'; }

            /** {@inheritDoc} @see TrivySBOMScanner::scanFile() */
            protected function scanFile(string $executable, string $path) : array
            {
                return $this->normalize($this->response);
            }
        };
        $scanner->response = $this->trivyResponse();
        $bom = new LicenseBOM(['packages' => [[
            'name' => '@scope/package', 'ecosystem' => 'npm', 'version' => '1.0.0',
            'bundled_in' => 'composer:example/bundle:2.0.0'
        ]]]);
        $task = $this->task(['sbom' => (new CycloneDxBOM($bom))->toJSON()]);
        $findings = $this->enrich($scanner->audit($task), $task);
        self::assertSame('composer:example/bundle:2.0.0', $findings[0]->getBundledInUrn());
        self::assertSame('', $findings[1]->getBundledInUrn());
    }

    /**
     * The action reads bundle evidence from the audited folder's SBOM.
     * 
     * @return void
     */
    public function testActionReadsOwnershipFromTheAuditedFolderSbom() : void
    {
        $folder = $this->temporaryFolder();
        $bom = new LicenseBOM(['packages' => [[
            'name' => '@scope/package', 'ecosystem' => 'npm', 'version' => '1.0.0',
            'bundled_in' => 'composer:example/bundle:2.0.0'
        ]]]);
        $this->writeFixture($folder, 'sbom.cdx.json', (new CycloneDxBOM($bom))->toJSON());
        $scanner = new class($this->workbench) extends TrivySBOMScanner {
            public array $response;

            /** {@inheritDoc} @see TrivySBOMScanner::executable() */
            protected function executable() : ?string { return 'fixture-trivy'; }

            /** {@inheritDoc} @see TrivySBOMScanner::scanFile() */
            protected function scanFile(string $executable, string $path) : array { return $this->normalize($this->response); }
        };
        $scanner->response = $this->trivyResponse();
        $task = $this->task(['folder' => $folder]);
        $findings = $this->enrich($scanner->audit($task), $task);
        self::assertSame('composer:example/bundle:2.0.0', $findings[0]->getBundledInUrn());
    }
}
