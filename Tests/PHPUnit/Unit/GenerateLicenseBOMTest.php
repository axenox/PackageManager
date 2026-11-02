<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\GenerateLicenseBOM;
use axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseGithubEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseSPDXEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseTextEnricher;
use axenox\PackageManager\Common\LicenseBOM\Format\CycloneDxBOM;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Common\LicenseBOM\IncludesBOM;
use axenox\PackageManager\Common\LicenseBOM\Format\MarkdownBOM;
use axenox\PackageManager\Interfaces\LicenseBOMExporterInterface;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Exceptions\UxonParserError;

/**
 * Covers configurable exporters and file defaults without generation or network access.
 */
class GenerateLicenseBOMTest extends AuditTestCase
{
    private $action;

    /**
     * {@inheritDoc}
     * 
     * @see AuditTestCase::setUp()
     */
    protected function setUp() : void
    {
        parent::setUp();
        $this->action = new GenerateLicenseBOM($this->workbench->getApp('axenox.PackageManager'));
    }

    /**
     * Relocated enrichers accept the shared package type without requiring downloads.
     * 
     * @return void
     */
    public function testEnrichersPreserveExistingLicenseText() : void
    {
        foreach ([FindLicenseFileEnricher::class, FindLicenseGithubEnricher::class, FindLicenseSPDXEnricher::class, FindLicenseTextEnricher::class] as $class) {
            $package = new BOMPackage([
                'name' => 'example/library',
                'license' => ['MIT'],
                'license_text' => ['MIT' => 'Existing license text']
            ]);
            $enricher = new $class(sys_get_temp_dir());
            self::assertSame($package, $enricher->enrich($package));
            self::assertSame('Existing license text', $package->getLicenses()['MIT']->getText());
        }
    }

    /**
     * A package-wide file cannot prove that each independently required license was resolved.
     *
     * @return void
     */
    public function testDirectorySearchDoesNotAssignOneFileToConjunctionComponents() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'LICENSE', 'Unqualified package terms');
        $package = new BOMPackage(['name' => basename($folder), 'license_used' => 'MIT AND BSD-3-Clause']);
        (new FindLicenseTextEnricher(dirname($folder)))->enrich($package);
        foreach ($package->getLicenses() as $license) {
            self::assertNull($license->getText());
            self::assertNull($license->getSource());
        }
        self::assertFalse($package->hasLicenseText());
    }

    /**
     * Built-in exporters accept empty UXON and retain the supplied inventory.
     * 
     * @return void
     */
    public function testBuiltInExporterConfigurations() : void
    {
        $bom = new LicenseBOM();
        foreach ([MarkdownBOM::class, JsonBOM::class, CycloneDxBOM::class] as $class) {
            $uxon = new UxonObject(['class' => '\\' . $class]);
            $exporter = $this->invokeProtected($this->action, 'createExporter', [$bom, $uxon]);
            self::assertInstanceOf($class, $exporter);
            self::assertInstanceOf(LicenseBOMExporterInterface::class, $exporter);
            self::assertTrue($exporter->exportUxonObject()->isEmpty());
            self::assertTrue($uxon->hasProperty('class'));
            self::assertSame($bom->getPackages(), $exporter->getPackages());
        }
    }

    /**
     * All non-class properties reach exporter setters without changing the original UXON.
     * 
     * @return void
     */
    public function testExporterPropertiesAreImported() : void
    {
        $bom = $this->createLicenseBOM();
        $before = $bom->getPackages()['composer:example/library:v1.2.3']->toBOM();
        $uxon = new UxonObject(['class' => JsonBOM::class, 'format' => 'minimal']);
        $exporter = $this->invokeProtected($this->action, 'createExporter', [$bom, $uxon]);
        self::assertSame(['format' => 'minimal'], $exporter->exportUxonObject()->toArray());
        self::assertTrue($uxon->hasProperty('class'));
        $path = $this->temporaryFolder() . '/minimal.json';
        $exporter->saveToFile($path);
        self::assertSame(file_get_contents($path), $exporter->buildJson());
        $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertEquals(['packages' => [
            ['name' => 'example/library', 'version' => 'v1.2.3', 'licenses' => [['name' => 'MIT', 'source' => 'config', 'text_available' => true]], 'license_used' => 'MIT',
                'urn' => 'composer:example/library:v1.2.3', 'ecosystem' => 'composer'],
            ['name' => '@scope/package', 'version' => 'v2.0.0', 'licenses' => [['name' => 'MIT', 'source' => 'config', 'text_available' => true]], 'license_used' => 'MIT',
                'urn' => 'npm:@scope/package:v2.0.0', 'ecosystem' => 'npm']
        ]], $document);
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        self::assertSame(['@scope/package' => ['2.0.0']], $this->invokeProtected($scanner, 'dependencies', [$document]));
        self::assertSame($before, $bom->getPackages()['composer:example/library:v1.2.3']->toBOM());
    }

    /**
     * Default and explicit full exports preserve all supplied metadata.
     * 
     * @return void
     */
    public function testJsonFullFormatPreservesMetadata() : void
    {
        $bom = $this->createLicenseBOM();
        $expected = ['packages' => array_values(array_map(static function ($package) {
            $data = $package->toBOM();
            $data['license_used'] = $package->getLicenseUsed();
            $data['ecosystem'] = $data['name'] === 'example/library' ? 'composer' : 'npm';
            if ($data['ecosystem'] === 'npm') {
                $data['name'] = '@scope/package';
            }
            return $data;
        }, $bom->getPackages()))];
        $path = $this->temporaryFolder() . '/full.json';
        foreach ([[], ['format' => 'full']] as $configuration) {
            $exporter = new JsonBOM($bom);
            $exporter->importUxonObject(new UxonObject($configuration));
            $exporter->saveToFile($path);
            self::assertSame(file_get_contents($path), $exporter->buildJson());
            self::assertEquals($expected, json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Full inventories expose the effective license without changing declared package metadata.
     * 
     * @return void
     */
    public function testJsonFullFormatIncludesEffectiveLicense() : void
    {
        $bom = new LicenseBOM();
        foreach ([
            ['name' => 'example/selected', 'license' => ['MIT', 'Apache-2.0'], 'license_used' => 'Apache-2.0'],
            ['name' => 'example/default', 'license' => ['MIT', 'Apache-2.0']],
            ['name' => 'example/empty-selection', 'license' => ['BSD-3-Clause'], 'license_used' => ''],
            ['name' => 'example/unknown']
        ] as $data) {
            $bom->addPackage(new BOMPackage($data));
        }
        $before = array_map(static function ($package) {
            return $package->toBOM();
        }, $bom->getPackages());
        $path = $this->temporaryFolder() . '/effective-licenses.json';
        foreach (['full', 'minimal'] as $format) {
            $exporter = (new JsonBOM($bom))->setFormat($format);
            $exporter->saveToFile($path);
            self::assertSame($exporter->buildJson(), file_get_contents($path));
            $packages = json_decode($exporter->buildJson(), true, 512, JSON_THROW_ON_ERROR)['packages'];
            if ($format === 'full') {
                self::assertSame(['Apache-2.0', 'MIT', 'BSD-3-Clause', 'Other'], array_column($packages, 'license_used'));
                self::assertSame(['MIT', 'Apache-2.0'], array_column($packages[0]['licenses'], 'name'));
                self::assertSame(['MIT', 'Apache-2.0'], array_column($packages[1]['licenses'], 'name'));
            } else {
                self::assertSame(['Apache-2.0', 'MIT', 'BSD-3-Clause'], array_column($packages, 'license_used'));
                self::assertSame(['MIT', 'Apache-2.0'], array_column($packages[0]['licenses'], 'name'));
                self::assertArrayNotHasKey('license_used', $packages[3]);
            }
        }
        self::assertSame($before, array_map(static function ($package) {
            return $package->toBOM();
        }, $bom->getPackages()));
    }

    /**
     * Minimal inventories retain unversioned packages without inventing a release.
     * 
     * @return void
     */
    public function testJsonMinimalFormatHandlesEmptyAndUnversionedInventories() : void
    {
        $bom = new LicenseBOM();
        $exporter = (new JsonBOM($bom))->setFormat('minimal');
        $path = $this->temporaryFolder() . '/minimal.json';
        $exporter->saveToFile($path);
        self::assertSame(file_get_contents($path), $exporter->buildJson());
        self::assertSame(['packages' => []], json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        $bom->addPackage(new BOMPackage(['name' => 'example/unversioned', 'license' => ['MIT']]));
        $exporter->saveToFile($path);
        self::assertSame(file_get_contents($path), $exporter->buildJson());
        self::assertEquals(['packages' => [[
            'name' => 'example/unversioned', 'licenses' => [['name' => 'MIT', 'text_available' => false]], 'urn' => 'composer:example/unversioned:',
            'ecosystem' => 'composer', 'license_used' => 'MIT'
        ]]], json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Minimal snapshots retain Git query identities without leaking repository or transport metadata.
     * 
     * @return void
     */
    public function testJsonMinimalFormatPreservesGitReferences() : void
    {
        $commit = str_repeat('a', 40);
        $sources = [
            ['type' => 'git', 'reference' => $commit, 'url' => 'https://example.org/library.git', 'mirrors' => []],
            ['type' => 'git', 'reference' => $commit, 'url' => 'https://example.org/dev.git'],
            ['type' => 'svn', 'reference' => '123', 'url' => 'https://example.org/svn'],
            ['type' => 'git', 'url' => 'https://example.org/unlocked.git'],
            ['reference' => $commit],
            ['type' => 'git', 'reference' => ''],
            ['type' => 'git', 'reference' => null]
        ];
        $bom = new LicenseBOM();
        foreach ($sources as $index => $source) {
            $bom->addPackage(new BOMPackage([
                'name' => 'library-' . $index, 'ecosystem' => 'bower',
                'version' => $index === 1 ? 'dev-main' : '1.0.0', 'source' => $source,
                'license' => ['MIT']
            ]));
        }
        $before = array_map(static function ($package) {
            return $package->toBOM();
        }, $bom->getPackages());
        $exporter = (new JsonBOM($bom))->setFormat('minimal');
        $path = $this->temporaryFolder() . '/minimal-git.json';
        $exporter->saveToFile($path);
        self::assertSame($exporter->buildJson(), file_get_contents($path));
        $packages = json_decode($exporter->buildJson(), true)['packages'];
        foreach ($packages as $index => $package) {
            self::assertSame(['MIT'], array_column($package['licenses'], 'name'));
            self::assertArrayNotHasKey('license_text', $package);
            if ($index < 2) {
                self::assertSame(['type' => 'git', 'reference' => $commit], $package['source']);
            } else {
                self::assertArrayNotHasKey('source', $package);
            }
        }
        self::assertSame('dev-main', $packages[1]['version']);
        self::assertSame($before, array_map(static function ($package) {
            return $package->toBOM();
        }, $bom->getPackages()));
    }

    /**
     * JSON ecosystem properties must be lowercase in both metadata formats.
     * 
     * @return void
     */
    public function testJsonEcosystemsAreLowercase() : void
    {
        $bom = new LicenseBOM();
        foreach (['NuGet', 'PyPI', 'Maven', 'Go', 'RubyGems'] as $ecosystem) {
            $bom->addPackage(new BOMPackage(['name' => 'example-' . strtolower($ecosystem), 'ecosystem' => $ecosystem]));
        }
        foreach (['minimal', 'full'] as $format) {
            $packages = json_decode((new JsonBOM($bom))->setFormat($format)->buildJson(), true)['packages'];
            self::assertSame(['nuget', 'pypi', 'maven', 'go', 'rubygems'], array_column($packages, 'ecosystem'));
        }
    }

    /**
     * Unsupported format names fail during UXON import.
     * 
     * @return void
     */
    public function testInvalidJsonFormatIsRejected() : void
    {
        $this->expectException(UxonParserError::class);
        $this->expectExceptionMessage('Expected "minimal" or "full"');
        $this->invokeProtected($this->action, 'createExporter', [
            new LicenseBOM(), new UxonObject(['class' => JsonBOM::class, 'format' => 'compact'])
        ]);
    }

    /**
     * Supplies Composer and scoped npm identities with metadata excluded by minimal exports.
     * 
     * @return LicenseBOM
     */
    private function createLicenseBOM() : LicenseBOM
    {
        $bom = new LicenseBOM();
        foreach ([
            ['name' => 'example/library', 'version' => 'v1.2.3'],
            ['name' => 'npm-asset/scope--package', 'version' => 'v2.0.0']
        ] as $identity) {
            $bom->addPackage(new BOMPackage($identity + [
                'type' => 'library',
                'description' => 'Example package',
                'license' => ['MIT'],
                'license_used' => 'MIT',
                'license_text' => ['MIT' => 'Example license text'],
                'license_file' => 'example/library/LICENSE',
                'license_link' => ['MIT' => 'https://example.org/license'],
                'source' => ['url' => 'https://example.org/source', 'reference' => 'abc123'],
                'require' => ['php' => '^8.0']
            ]));
        }
        return $bom;
    }

    /**
     * Includes must supplement asset aliases and preserve same-named packages from other registries.
     * 
     * @return void
     */
    public function testIncludesNativeEcosystemsMergeAndExport() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'includes.json', json_encode([
            ['name' => '@scope/package', 'ecosystem' => 'npm', 'license_used' => 'Apache-2.0'],
            ['name' => 'Newtonsoft.Json', 'ecosystem' => 'nuget', 'version' => '12.0.1'],
            ['name' => 'same-name', 'ecosystem' => 'npm', 'version' => '1.0.0'],
            ['name' => 'same-name', 'ecosystem' => 'bower', 'version' => '2.0.0'],
            ['name' => 'same-name', 'ecosystem' => 'nuget', 'version' => '3.0.0'],
            ['name' => 'same-name', 'version' => '4.0.0']
        ], JSON_THROW_ON_ERROR));
        $bom = $this->createLicenseBOM();
        $bom->merge(new IncludesBOM($folder . '/includes.json', $folder, 'composer:example/host:1.0.0'));
        self::assertCount(7, $bom->getPackages());
        self::assertTrue($bom->hasPackage('Newtonsoft.Json'));
        self::assertSame('Apache-2.0', $bom->getPackages()['npm:@scope/package:v2.0.0']->getLicenseUsed());
        self::assertSame('v2.0.0', $bom->getPackages()['npm:@scope/package:v2.0.0']->getVersion());
        $packages = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true)['packages'];
        self::assertEquals(['name' => '@scope/package', 'version' => 'v2.0.0', 'licenses' => [
            ['name' => 'MIT', 'source' => 'config', 'text_available' => true],
            ['name' => 'Apache-2.0', 'text_available' => false]
        ], 'license_used' => 'Apache-2.0', 'ecosystem' => 'npm', 'urn' => 'npm:@scope/package:v2.0.0'], $packages[1]);
        self::assertEquals(['name' => 'Newtonsoft.Json', 'ecosystem' => 'nuget', 'version' => '12.0.1',
            'bundled_in' => 'composer:example/host:1.0.0', 'urn' => 'nuget:Newtonsoft.Json:12.0.1',
            'licenses' => []], $packages[2]);
        self::assertSame(['npm', 'bower', 'nuget', 'composer'], array_column(array_slice($packages, 3), 'ecosystem'));
    }

    /**
     * Unmatched supplements must not create packages, regardless of manifest ordering.
     * 
     * @return void
     */
    public function testInclusionsOnlySupplementPresentPackages() : void
    {
        foreach ([false, true] as $reverse) {
            $bom = new LicenseBOM();
            foreach (['0.5.0', '0.4.0'] as $version) {
                $bom->addPackage(new BOMPackage(['name' => 'npm-asset/union', 'version' => $version]));
            }
            $supplements = new LicenseBOM();
            $supplements->setLicenses(['MIT' => ['license_text' => 'Shared terms']]);
            foreach ([
                ['name' => 'union', 'ecosystem' => 'npm', 'license' => ['MIT']],
                ['name' => 'npm-asset/absent', 'license' => ['MIT']],
                ['name' => 'npm-asset/later', 'license' => ['MIT']],
                ['name' => 'later', 'ecosystem' => 'bower', 'license' => ['MIT']]
            ] as $data) {
                $supplements->addPackage(new BOMPackage($data));
            }
            $bundles = new LicenseBOM();
            $bundles->addPackage(new BOMPackage(['name' => 'later', 'ecosystem' => 'npm', 'version' => '1.0.0']));
            $inclusions = $reverse ? [$bundles, $supplements] : [$supplements, $bundles];
            self::assertSame(['npm-asset/absent', 'later'], $this->invokeProtected($this->action, 'mergeInclusions', [$bom, $inclusions]));
            self::assertCount(3, $bom->getPackages());
            foreach ($bom->getPackages() as $package) {
                self::assertSame(['MIT'], array_keys($package->getLicenses()));
                self::assertSame('Shared terms', $package->getLicenses()['MIT']->getText());
            }
            $packages = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true)['packages'];
            self::assertSame(['union', 'union', 'later'], array_column($packages, 'name'));
            self::assertSame(['0.5.0', '0.4.0', '1.0.0'], array_column($packages, 'version'));
        }
    }

    /**
     * A real installed package with an unknown version must not be discarded as a supplement.
     * 
     * @return void
     */
    public function testInclusionsPreserveUnversionedInstalledPackages() : void
    {
        $bom = new LicenseBOM();
        $bom->addPackage(new BOMPackage(['name' => 'npm-asset/installed']));
        $supplements = new LicenseBOM();
        $supplements->addPackage(new BOMPackage(['name' => 'npm-asset/installed', 'license' => ['MIT']]));
        $supplements->addPackage(new BOMPackage(['name' => 'npm-asset/union', 'license' => ['MIT']]));
        self::assertSame(['npm-asset/union'], $this->invokeProtected($this->action, 'mergeInclusions', [$bom, [$supplements]]));
        self::assertCount(1, $bom->getPackages());
        $package = $bom->getPackages()['npm:installed:'];
        self::assertNull($package->getVersion());
        self::assertSame(['MIT'], array_keys($package->getLicenses()));
    }

    /**
     * Unknown exporter properties remain strict UXON configuration errors.
     * 
     * @return void
     */
    public function testUnknownExporterPropertyIsRejected() : void
    {
        $this->expectException(UxonMapError::class);
        $this->invokeProtected($this->action, 'createExporter', [
            new LicenseBOM(), new UxonObject(['class' => CycloneDxBOM::class, 'unknown_option' => true])
        ]);
    }

    /**
     * Unrelated classes cannot be used as exporters.
     * 
     * @return void
     */
    public function testInvalidExporterClassIsRejected() : void
    {
        $this->expectException(ActionConfigurationError::class);
        $this->invokeProtected($this->action, 'createExporter', [new LicenseBOM(), new UxonObject(['class' => LicenseBOM::class])]);
    }

    /**
     * Every exporter configuration must select a class.
     * 
     * @return void
     */
    public function testMissingExporterClassIsRejected() : void
    {
        $this->expectException(ActionConfigurationError::class);
        $this->invokeProtected($this->action, 'createExporter', [new LicenseBOM(), new UxonObject()]);
    }

    /**
     * Installation configuration supplies defaults when no action override exists.
     * 
     * @return void
     */
    public function testInstallationDefaultsAreConfigurable() : void
    {
        $files = ['custom/sbom.json' => ['class' => CycloneDxBOM::class]];
        $this->action->getApp()->getConfig()->setOption('SBOM.files', new UxonObject($files));
        self::assertSame($files, $this->invokeProtected($this->action, 'getSaveTo'));
    }

    /**
     * Action maps replace installation defaults, including an explicitly empty map.
     * 
     * @return void
     */
    public function testActionOverridesReplaceDefaults() : void
    {
        $files = ['custom/licenses.json' => ['class' => JsonBOM::class]];
        $this->action->setSaveToFiles(new UxonObject($files));
        self::assertSame($files, $this->invokeProtected($this->action, 'getSaveTo'));
        $this->action->setSaveToFiles([]);
        self::assertSame([], $this->invokeProtected($this->action, 'getSaveTo'));
    }

    /**
     * The shared saving API preserves the existing JSON representations.
     * 
     * @return void
     */
    public function testJsonExportersSaveThroughSharedContract() : void
    {
        $path = $this->temporaryFolder() . '/bom.json';
        $json = new JsonBOM(new LicenseBOM());
        self::assertSame($json, $json->saveToFile($path));
        self::assertSame(['packages' => []], json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        $cycloneDx = new CycloneDxBOM(new LicenseBOM());
        self::assertSame($cycloneDx, $cycloneDx->saveToFile($path));
        self::assertSame('CycloneDX', json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['bomFormat']);
    }
}