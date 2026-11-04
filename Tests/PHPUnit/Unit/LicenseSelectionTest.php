<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\IncludesBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;

/**
 * Ensures selecting a license does not require repeating it or erase declared alternatives.
 */
class LicenseSelectionTest extends AuditTestCase
{
    /**
     * Selection-only configuration must resolve shared terms and export a usable license list.
     * 
     * @return void
     */
    public function testSelectionOnlyConfigurationGeneratesLicenseList() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'Licenses.config.json', json_encode([
            'packages' => [['name' => 'example/client-app', 'ecosystem' => 'private', 'license_used' => 'Client-License']],
            'licenses' => ['Client-License' => ['license_text' => 'Installation terms']]
        ]));
        $bom = new IncludesBOM($folder . '/Licenses.config.json', $folder);
        $package = $bom->getPackages()['private:example/client-app:'];
        self::assertSame(['Client-License'], array_keys($package->getLicenses()));
        self::assertTrue($package->hasLicense());
        self::assertTrue($package->hasLicenseText());
        self::assertSame('Installation terms', $package->getLicenses()['Client-License']->getText());
        $export = json_decode((new JsonBOM($bom))->buildJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Client-License'], array_column($export['packages'][0]['licenses'], 'name'));
        self::assertSame('Client-License', $export['packages'][0]['license_used']);
        self::assertArrayNotHasKey('license', $package->toBOM());
    }

    /**
     * A generated fallback must not be treated as an explicit replacement during merging.
     * 
     * @return void
     */
    public function testSelectionOnlyOverridePreservesComposerAlternatives() : void
    {
        $composer = new LicenseBOM();
        $composer->addPackage(new BOMPackage([
            'name' => 'example/library', 'version' => '1.2.3', 'license' => ['GPL-2.0-only', 'Apache-2.0']
        ]));
        $overrides = new LicenseBOM();
        $overrides->addPackage(new BOMPackage(['name' => 'example/library', 'license_used' => 'Apache-2.0']));
        $composer->merge($overrides);
        $package = $composer->getPackages()['composer:example/library:1.2.3'];
        self::assertSame(['GPL-2.0-only', 'Apache-2.0'], array_keys($package->getLicenses()));
        self::assertSame('Apache-2.0', $package->getLicenseUsed());
        self::assertSame('1.2.3', $package->getVersion());
        $export = json_decode((new JsonBOM($composer))->buildJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['GPL-2.0-only', 'Apache-2.0'], array_column($export['packages'][0]['licenses'], 'name'));
    }

    /**
     * Empty selections must not invent licenses, and changing a selection must refresh the fallback.
     * 
     * @return void
     */
    public function testFallbackTracksSelectionWithoutInventingUnknownLicenses() : void
    {
        $package = new BOMPackage(['name' => 'example/library', 'license' => []]);
        foreach ([null, ''] as $selection) {
            $package->setLicenseUsed($selection);
            self::assertSame([], array_keys($package->getLicenses()));
            self::assertFalse($package->hasLicense());
        }
        $package->setLicenseUsed('Client-License');
        self::assertSame(['Client-License'], array_keys($package->getLicenses()));
        $package->setLicenseUsed('Another-License');
        self::assertSame(['Another-License'], array_keys($package->getLicenses()));
        $bom = new LicenseBOM();
        $bom->addPackage($package);
        $minimal = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Another-License'], array_column($minimal['packages'][0]['licenses'], 'name'));
        self::assertSame('Another-License', $minimal['packages'][0]['license_used']);
        self::assertSame([['name' => 'Another-License', 'text_available' => false]], $minimal['packages'][0]['licenses']);
        $package->setLicenseUsed(null);
        self::assertSame([], array_keys($package->getLicenses()));
    }
}