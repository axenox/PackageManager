<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\IncludesBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;

/**
 * Protects the shared identity contract between package inventories and advisory rows.
 */
class BOMUrnTest extends AuditTestCase
{
    /**
     * Explicit classification must follow the same version rule as includes ownership after merges.
     * 
     * @return void
     */
    public function testPackageClassificationTracksCurrentVersionMetadata() : void
    {
        foreach ([[], ['version' => null], ['version' => '']] as $versionData) {
            $package = new BOMPackage(array_merge(['name' => 'example/library'], $versionData));
            self::assertFalse($package->isSeparatePackage());
            self::assertTrue($package->isSupplementalInfo());
            $package->merge(new BOMPackage(['name' => 'example/library', 'version' => '1.0.0']));
            self::assertTrue($package->isSeparatePackage());
            self::assertFalse($package->isSupplementalInfo());
            $package->merge(new BOMPackage(['name' => 'example/library', 'license_used' => 'MIT']));
            self::assertTrue($package->isSeparatePackage());
            self::assertFalse($package->isSupplementalInfo());
        }
        foreach (['0', 'v1.0.0', 'dev-main'] as $version) {
            $package = new BOMPackage(['name' => 'example/library', 'version' => $version]);
            self::assertTrue($package->isSeparatePackage());
            self::assertFalse($package->isSupplementalInfo());
        }
    }

    /**
     * Package identities must reflect merged metadata rather than stale imported URNs.
     * 
     * @return void
     */
    public function testUrnGetterTracksMetadataAndMatchesExport() : void
    {
        $package = new BOMPackage(['name' => 'npm-asset/jquery', 'urn' => 'stale']);
        self::assertInstanceOf(\axenox\PackageManager\Interfaces\BOMPackageInterface::class, $package);
        self::assertSame('npm:jquery:', $package->getUrn());
        self::assertSame($package->getUrn(), $package->toComposerArray()['urn']);
        $package->merge(new BOMPackage(['name' => 'jquery', 'ecosystem' => 'npm', 'version' => '3.0.0']));
        self::assertSame('npm:jquery:3.0.0', $package->getUrn());
        self::assertSame($package->getUrn(), $package->toComposerArray()['urn']);
    }

    /**
     * Asset aliases and native registry names must link to the same inventory entry.
     * 
     * @return void
     */
    public function testUrnsNormalizeAssetAliasesAndKeepVersionsAndEcosystems() : void
    {
        self::assertSame('npm:jquery:3.0.0', BOMPackage::buildUrn('npm-asset/jquery', '3.0.0'));
        self::assertSame('npm:@scope/package:v2.0.0', BOMPackage::buildUrn('npm-asset/scope--package', 'v2.0.0'));
        self::assertSame('bower:jquery:3.0.0', BOMPackage::buildUrn('jquery', '3.0.0', 'bower'));
        self::assertSame('npm:jquery:4.0.0', BOMPackage::buildUrn('jquery', '4.0.0', 'npm'));
    }

    /**
     * Missing versions must remain visibly unknown in stable package identities.
     * 
     * @return void
     */
    public function testUnknownVersionUsesAnEmptySegment() : void
    {
        self::assertSame('composer:example/library:', BOMPackage::buildUrn('example/library'));
    }

    /**
     * Version-specific merges must retain all installed versions and registry identities.
     * 
     * @return void
     */
    public function testMergingUsesUrnsAndVersionlessOverridesApplyToEachVersion() : void
    {
        $bom = new LicenseBOM();
        $incoming = new LicenseBOM();
        $bom->addPackage(new BOMPackage(['name' => 'npm-asset/jquery', 'version' => '3.0.0', 'license' => ['MIT']]));
        $incoming->addPackage(new BOMPackage(['name' => 'jquery', 'ecosystem' => 'npm', 'version' => '4.0.0']));
        $incoming->addPackage(new BOMPackage(['name' => 'jquery', 'ecosystem' => 'bower', 'version' => '3.0.0']));
        $bom->merge($incoming);
        self::assertSame(['npm:jquery:3.0.0', 'npm:jquery:4.0.0', 'bower:jquery:3.0.0'], array_keys($bom->getPackages()));
        $overrides = new LicenseBOM();
        $overrides->addPackage(new BOMPackage(['name' => 'jquery', 'ecosystem' => 'npm', 'license_used' => 'MIT']));
        $bom->merge($overrides);
        self::assertCount(3, $bom->getPackages());
        self::assertSame('MIT', $bom->getPackages()['npm:jquery:3.0.0']->getLicenseUsed());
        self::assertSame('MIT', $bom->getPackages()['npm:jquery:4.0.0']->getLicenseUsed());
        self::assertSame('Other', $bom->getPackages()['bower:jquery:3.0.0']->getLicenseUsed());
        $specific = new LicenseBOM();
        $specific->addPackage(new BOMPackage(['name' => 'npm-asset/jquery', 'version' => '3.0.0', 'description' => 'Updated']));
        $bom->merge($specific);
        self::assertCount(3, $bom->getPackages());
        self::assertSame('Updated', $bom->getPackages()['npm:jquery:3.0.0']->getDescription());
        self::assertNull($bom->getPackages()['npm:jquery:4.0.0']->getDescription());
    }

    /**
     * License-only supplements must not create duplicates or claim another package's bundle ownership.
     * 
     * @return void
     */
    public function testVersionlessIncludesPreserveInstalledIdentityAndBundleOwnership() : void
    {
        $folder = $this->temporaryFolder();
        foreach ([[], ['version' => null], ['version' => '']] as $versionData) {
            $this->writeFixture($folder, 'includes.json', json_encode([
                array_merge(['name' => 'npm-asset/scope--package', 'license_used' => 'MIT'], $versionData)
            ]));
            $supplement = new IncludesBOM($folder . '/includes.json', $folder, 'composer:example/host:1.0.0');
            self::assertArrayNotHasKey('bundled_in', $supplement->getPackages()['npm:@scope/package:']->toComposerArray());

            $bom = new LicenseBOM();
            $bom->addPackage(new BOMPackage([
                'name' => '@scope/package', 'ecosystem' => 'npm', 'version' => '1.0.0'
            ]));
            $bom->addPackage(new BOMPackage([
                'name' => '@scope/package', 'ecosystem' => 'npm', 'version' => '2.0.0',
                'bundled_in' => 'composer:example/original-owner:1.0.0'
            ]));
            $bom->merge($supplement);

            self::assertSame(['npm:@scope/package:1.0.0', 'npm:@scope/package:2.0.0'], array_keys($bom->getPackages()));
            foreach (['full', 'minimal'] as $format) {
                $packages = json_decode((new JsonBOM($bom))->setFormat($format)->buildJson(), true)['packages'];
                self::assertCount(2, $packages);
                self::assertSame(['1.0.0', '2.0.0'], array_column($packages, 'version'));
                self::assertSame(['npm:@scope/package:1.0.0', 'npm:@scope/package:2.0.0'], array_column($packages, 'urn'));
                self::assertArrayNotHasKey('bundled_in', $packages[0]);
                self::assertSame('composer:example/original-owner:1.0.0', $packages[1]['bundled_in']);
                if ($format === 'full') {
                    self::assertSame(['MIT', 'MIT'], array_column($packages, 'license_used'));
                }
            }
        }
    }

    /**
     * Bundle links and URNs must survive both JSON formats and rebuilding an archived inventory.
     * 
     * @return void
     */
    public function testIncludesOwnershipAndMinimalRoundTrip() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'includes.json', json_encode([
            ['name' => 'jquery', 'ecosystem' => 'npm', 'version' => '3.0.0', 'urn' => 'stale']
        ]));
        $bom = new IncludesBOM($folder . '/includes.json', $folder, 'composer:example/host:1.0.0');
        foreach (['full', 'minimal'] as $format) {
            $package = json_decode((new JsonBOM($bom))->setFormat($format)->buildJson(), true)['packages'][0];
            self::assertSame('npm:jquery:3.0.0', $package['urn']);
            self::assertSame('composer:example/host:1.0.0', $package['bundled_in']);
            $roundTrip = new LicenseBOM();
            $roundTrip->addPackage(new BOMPackage($package));
            self::assertArrayHasKey($package['urn'], $roundTrip->getPackages());
        }
        $this->writeFixture($folder, 'composer.json', '{"name":"example/host","version":"2.0.0"}');
        $automatic = new IncludesBOM($folder . '/includes.json', $folder);
        self::assertSame('composer:example/host:2.0.0', $automatic->getPackages()['npm:jquery:3.0.0']->toComposerArray()['bundled_in']);
        $this->writeFixture($folder, 'Licenses.config.json', '{"packages":[{"name":"example/private"}]}');
        $configuration = new IncludesBOM($folder . '/Licenses.config.json', $folder);
        self::assertArrayNotHasKey('bundled_in', $configuration->getPackages()['composer:example/private:']->toComposerArray());
    }

    /**
     * One advisory affecting two installed versions must produce two resolvable inventory links.
     * 
     * @return void
     */
    public function testAdvisoriesRetainSeparateVersionUrns() : void
    {
        $action = new Audit($this->workbench->getApp('axenox.PackageManager'));
        $findings = [];
        foreach (['3.0.0', '4.0.0'] as $version) {
            $findings[] = new Finding('CVE-2026-0001', 'Advisory', 'vulnerability', 'npm-asset/jquery', 'osv', 'high', versionInstalled: $version);
        }
        $rows = $this->invokeProtected($action, 'toDataSheetRows', [$findings]);
        self::assertSame(['npm:jquery:3.0.0', 'npm:jquery:4.0.0'], array_column($rows, 'BOM_URN'));
        self::assertCount(2, array_unique(array_column($rows, 'ID')));
    }
}