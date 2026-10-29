<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicensePackageJsonEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseTextEnricher;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;

/**
 * Covers local manifest recovery without installed dependencies or network requests.
 */
class FindLicensePackageJsonEnricherTest extends AuditTestCase
{
    /**
     * Asset lock entries must recover legacy license names before indexing local license text.
     * 
     * @return void
     */
    public function testLegacyLicensesAreResolvedBeforeTextDiscovery() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'package.json', '{"licenses":[{"type":"MIT","url":"https://example.org/license"}]}');
        $this->writeFixture($folder, 'LICENSE', 'MIT license terms');
        $package = new BOMPackage(['name' => basename($folder), 'version' => '1.2.3']);
        $package = (new FindLicensePackageJsonEnricher(dirname($folder)))->enrich($package);
        $package = (new FindLicenseTextEnricher(dirname($folder)))->enrich($package);
        self::assertSame(['MIT'], $package->getLicenseNames());
        self::assertSame('MIT', $package->getLicenseUsed());
        self::assertSame('https://example.org/license', $package->getLicenseLink('MIT'));
        self::assertSame('MIT license terms', $package->getLicenseText('MIT'));
        self::assertNull($package->getLicenseText('Other'));
        self::assertSame('1.2.3', $package->getVersion());
    }

    /**
     * All supported manifest shapes must retain declared expressions and licensing alternatives.
     * 
     * @return void
     */
    public function testModernAndLegacyManifestShapes() : void
    {
        $folder = $this->temporaryFolder();
        $enricher = new FindLicensePackageJsonEnricher(dirname($folder));
        foreach ([
            [['license' => 'MIT'], ['MIT']],
            [['license' => '(MIT OR Apache-2.0)'], ['(MIT OR Apache-2.0)']],
            [['license' => ['type' => 'MIT']], ['MIT']],
            [['licenses' => [['type' => 'MIT'], ['type' => 'BSD-3-Clause'], ['type' => 'MIT']]], ['MIT', 'BSD-3-Clause']],
            [['license' => '', 'licenses' => ['MIT']], ['MIT']],
            [['license' => 'MIT', 'licenses' => ['BSD-3-Clause']], ['MIT']]
        ] as [$manifest, $expected]) {
            $this->writeFixture($folder, 'package.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            $package = $enricher->enrich(new BOMPackage(['name' => basename($folder), 'license_used' => 'Other']));
            self::assertSame($expected, $package->getLicenseNames());
            self::assertSame($expected[0], $package->getLicenseUsed());
        }
    }

    /**
     * Local manifests must not undo Composer declarations or installation-specific selections.
     * 
     * @return void
     */
    public function testExistingDeclarationsAndSelectionsRemainUntouched() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'package.json', '{"license":"MIT"}');
        foreach ([['license' => ['Apache-2.0']], ['license_used' => 'Client-License']] as $data) {
            $package = new BOMPackage(array_merge(['name' => basename($folder)], $data));
            self::assertSame($package, (new FindLicensePackageJsonEnricher(dirname($folder)))->enrich($package));
            self::assertSame($data, array_diff_key($package->toComposerArray(), ['name' => true]));
        }
    }

    /**
     * Shared definitions must still win after a previously unknown license has been discovered.
     * 
     * @return void
     */
    public function testSharedDefinitionsApplyToDiscoveredLicenseNames() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'package.json', '{"licenses":[{"type":"MIT","url":"https://example.org/original"}]}');
        $bom = new LicenseBOM();
        $bom->setLicenses(['MIT' => ['license_text' => 'Shared terms', 'license_link' => 'https://example.org/override']]);
        $package = (new FindLicensePackageJsonEnricher(dirname($folder)))->enrich(new BOMPackage(['name' => basename($folder)]));
        $bom->addPackage($package);
        $package = $bom->getPackages()[basename($folder)];
        self::assertSame('Shared terms', $package->getLicenseText('MIT'));
        self::assertSame('https://example.org/override', $package->getLicenseLink('MIT'));
    }

    /**
     * Missing declarations cannot be inferred from absent manifests or file-reference syntax.
     * 
     * @return void
     */
    public function testAbsentOrUnusableLicenseMetadataStaysUnknown() : void
    {
        $folder = $this->temporaryFolder();
        $enricher = new FindLicensePackageJsonEnricher(dirname($folder));
        $package = new BOMPackage(['name' => basename($folder)]);
        self::assertSame($package, $enricher->enrich($package));
        foreach (['{}', '{"license":""}', '{"license":"SEE LICENSE IN LICENSE.txt"}',
            '{"licenses":[{},null,42,{"type":""}]}'] as $manifest) {
            $this->writeFixture($folder, 'package.json', $manifest);
            self::assertSame($package, $enricher->enrich($package));
            self::assertSame('Other', $package->getLicenseUsed());
        }
    }

    /**
     * Native scoped npm names must resolve to their Composer asset installation directory.
     * 
     * @return void
     */
    public function testNativeScopedNamesFindAssetPackagistManifests() : void
    {
        $folder = $this->temporaryFolder();
        self::assertTrue(mkdir($folder . '/npm-asset'));
        self::assertTrue(mkdir($folder . '/npm-asset/scope--library'));
        try {
            $this->writeFixture($folder . '/npm-asset/scope--library', 'package.json', '{"license":"MIT"}');
            $enricher = new FindLicensePackageJsonEnricher($folder);
            foreach (['@scope/library', 'npm-asset/scope--library'] as $name) {
                $package = $enricher->enrich(new BOMPackage(['name' => $name, 'ecosystem' => 'npm']));
                self::assertSame('MIT', $package->getLicenseUsed());
                self::assertSame($name, $package->getName());
            }
        } finally {
            if (is_file($folder . '/npm-asset/scope--library/package.json')) {
                unlink($folder . '/npm-asset/scope--library/package.json');
            }
            rmdir($folder . '/npm-asset/scope--library');
            rmdir($folder . '/npm-asset');
        }
    }

    /**
     * A corrupt manifest must fail rather than silently preserve an incorrect license report.
     * 
     * @return void
     */
    public function testMalformedManifestFails() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'package.json', '{');
        $this->expectException(DataTypeCastingError::class);
        (new FindLicensePackageJsonEnricher(dirname($folder)))->enrich(new BOMPackage(['name' => basename($folder)]));
    }
}