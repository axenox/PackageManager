<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\OsvAuditScanner;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Format\CycloneDxBOM;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\IncludesBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;

/**
 * Protects installation-specific license choices and private inventory exclusions.
 */
class InstallationLicensesTest extends AuditTestCase
{
    /**
     * Shared definitions must work across files and override previously resolved license text.
     * 
     * @return void
     */
    public function testNamedLicensesAndPrivateOverridesPreserveInstalledMetadata() : void
    {
        $folder = $this->temporaryFolder();
        $bom = new LicenseBOM();
        $bom->addPackage(new BOMPackage([
            'name' => 'example/library', 'version' => '1.2.3',
            'license' => ['GPL-2.0-only', 'Client-License'],
            'source' => ['type' => 'git', 'reference' => str_repeat('a', 40)]
        ]));
        $this->writeFixture($folder, 'includes.json', json_encode([
            'packages' => [['name' => 'example/library', 'license_used' => 'Client-License']],
            'licenses' => ['Client-License' => ['license_text' => 'Default terms']]
        ]));
        $bom->merge(new IncludesBOM($folder . '/includes.json', $folder));
        $this->writeFixture($folder, 'Licenses.config.json', json_encode([
            'packages' => [['name' => 'example/library', 'ecosystem' => 'private']],
            'licenses' => ['Client-License' => [
                'license_link' => 'https://example.org/license', 'license_text' => 'Installation terms'
            ]]
        ]));
        $bom->merge(new IncludesBOM($folder . '/Licenses.config.json', $folder));
        self::assertCount(1, $bom->getPackages());
        $package = $bom->getPackages()['example/library'];
        self::assertSame('1.2.3', $package->getVersion());
        self::assertSame('Client-License', $package->getLicenseUsed());
        self::assertSame(['GPL-2.0-only', 'Client-License'], $package->getLicenseNames());
        self::assertSame('Installation terms', $package->getLicenseText('Client-License'));
        self::assertSame('https://example.org/license', $package->getLicenseLink('Client-License'));
        self::assertSame('private', $package->toComposerArray()['ecosystem']);
        self::assertTrue($package->hasLicenseText());
        $full = json_decode((new JsonBOM($bom))->buildJson(), true);
        self::assertSame($bom->getLicenses(), $full['licenses']);
        self::assertSame('Installation terms', $full['packages'][0]['license_text']['Client-License']);
        $minimal = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true);
        self::assertArrayNotHasKey('licenses', $minimal);
        self::assertSame('private', $minimal['packages'][0]['ecosystem']);
        self::assertSame($package->toComposerArray()['source'], $minimal['packages'][0]['source']);
    }

    /**
     * A definitions-only file must resolve licenses on packages supplied by another source.
     * 
     * @return void
     */
    public function testDefinitionsOnlyAndLegacyIncludesRemainCompatible() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'includes.json', json_encode([
            ['name' => 'example/library', 'license' => ['Client-License']]
        ]));
        $bom = new IncludesBOM($folder . '/includes.json', $folder);
        $this->writeFixture($folder, 'Licenses.config.json', json_encode([
            'licenses' => ['Client-License' => ['license_text' => 'Shared terms']]
        ]));
        $bom->merge(new IncludesBOM($folder . '/Licenses.config.json', $folder));
        self::assertSame('Shared terms', $bom->getPackages()['example/library']->getLicenseText('Client-License'));
        self::assertSame('Shared terms', (new JsonBOM($bom))->getLicenses()['Client-License']['license_text']);
        self::assertTrue($bom->getPackages()['example/library']->hasLicenseText());
        self::assertNull($bom->getPackages()['example/library']->getSource());
    }

    /**
     * Private packages must never use OSV's Git fallback or reappear through the Composer lock.
     * 
     * @return void
     */
    public function testOsvSkipsPrivatePackagesAcrossArtifactsWithoutCoverageWarnings() : void
    {
        $private = ['name' => 'example/private', 'version' => 'dev-main',
            'source' => ['type' => 'git', 'reference' => str_repeat('a', 40)]];
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => [
            'composer_lock' => ['packages' => [$private]],
            'sbom' => ['packages' => [array_replace($private, ['ecosystem' => 'PRIVATE'])]]
        ]]));
        $scanner = new OsvAuditScanner($this->workbench);
        self::assertSame([], $this->invokeProtected($scanner, 'dependencies', [$task]));
        self::assertFalse($scanner->supports($task));
        self::assertSame([], $scanner->audit($task));
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => [
            array_replace($private, ['ecosystem' => 'private']),
            ['name' => 'example/public', 'version' => '1.0.0']
        ]]]]));
        $dependencies = $this->invokeProtected($scanner, 'dependencies', [$task]);
        self::assertCount(1, $dependencies);
        self::assertSame(['package' => ['ecosystem' => 'Packagist', 'name' => 'example/public'], 'version' => '1.0.0'], $dependencies[0]['query']);
    }

    /**
     * Private classification must survive exports and suppress public-registry inference.
     * 
     * @return void
     */
    public function testPrivateEcosystemSurvivesCycloneDxAndIsIgnoredByNpm() : void
    {
        self::assertSame('private', PackageEcosystemDataType::findEcosystem('npm-asset/library', 'Private'));
        self::assertNull(PackageEcosystemDataType::convertToOsvEcosystem('private'));
        $bom = new LicenseBOM();
        $bom->addPackage(new BOMPackage(['name' => 'npm-asset/library', 'ecosystem' => 'private', 'version' => '1.0.0']));
        $path = $this->temporaryFolder() . '/sbom.json';
        (new CycloneDxBOM($bom))->saveToFile($path);
        $cdx = json_decode(file_get_contents($path), true);
        self::assertArrayNotHasKey('purl', $cdx['components'][0]);
        self::assertSame('private', $cdx['components'][0]['properties'][0]['value']);
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => $cdx]]));
        self::assertFalse((new OsvAuditScanner($this->workbench))->supports($task));
        $task->importUxonObject(new UxonObject(['parameters' => [
            'composer_lock' => ['packages' => [['name' => 'npm-asset/library', 'version' => '1.0.0']]]
        ]]));
        self::assertFalse((new OsvAuditScanner($this->workbench))->supports($task));
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        self::assertSame([], $this->invokeProtected($scanner, 'dependencies', [json_decode((new JsonBOM($bom))->buildJson(), true)]));
    }

    /**
     * Private npm entries in a saved BOM must exclude their public aliases from parallel locks.
     * 
     * @return void
     */
    public function testNpmPrivateExclusionTakesPrecedenceOverComposerLock() : void
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => [
            'composer_lock' => ['packages' => [['name' => 'npm-asset/scope--library', 'version' => '1.0.0']]],
            'sbom' => ['packages' => [['name' => '@scope/library', 'ecosystem' => 'private']]]
        ]]));
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        self::assertFalse($scanner->supports($task));
        self::assertSame([], $scanner->audit($task));
    }
}