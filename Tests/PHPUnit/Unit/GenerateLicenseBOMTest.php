<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\GenerateLicenseBOM;
use axenox\PackageManager\Audit\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseGithubEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseSPDXEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseTextEnricher;
use axenox\PackageManager\Common\LicenseBOM\Format\CycloneDxBOM;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
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
            self::assertSame('Existing license text', $package->getLicenseText('MIT'));
        }
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
        $before = $bom->getPackages()['example/library']->toComposerArray();
        $uxon = new UxonObject(['class' => JsonBOM::class, 'format' => 'minimal']);
        $exporter = $this->invokeProtected($this->action, 'createExporter', [$bom, $uxon]);
        self::assertSame(['format' => 'minimal'], $exporter->exportUxonObject()->toArray());
        self::assertTrue($uxon->hasProperty('class'));
        $path = $this->temporaryFolder() . '/minimal.json';
        $exporter->saveToFile($path);
        $document = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['packages' => [
            ['name' => 'example/library', 'version' => 'v1.2.3'],
            ['name' => 'npm-asset/scope--package', 'version' => 'v2.0.0']
        ]], $document);
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        self::assertSame(['@scope/package' => ['2.0.0']], $this->invokeProtected($scanner, 'dependencies', [$document]));
        self::assertSame($before, $bom->getPackages()['example/library']->toComposerArray());
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
            return $package->toComposerArray();
        }, $bom->getPackages()))];
        $path = $this->temporaryFolder() . '/full.json';
        foreach ([[], ['format' => 'full']] as $configuration) {
            $exporter = new JsonBOM($bom);
            $exporter->importUxonObject(new UxonObject($configuration));
            $exporter->saveToFile($path);
            self::assertSame($expected, json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        }
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
        self::assertSame(['packages' => []], json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
        $bom->addPackage(new BOMPackage(['name' => 'example/unversioned', 'license' => ['MIT']]));
        $exporter->saveToFile($path);
        self::assertSame(['packages' => [['name' => 'example/unversioned']]], json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
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