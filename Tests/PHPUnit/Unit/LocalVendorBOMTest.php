<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Common\LicenseBOM\LocalVendorBOM;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Keeps default enrichment explicit and confined to the top-level local vendor inventory.
 */
class LocalVendorBOMTest extends TestCase
{
    /**
     * Construction, intermediate merges and plain targets must never trigger default downloads.
     *
     * @return void
     */
    public function testConstructionAndMergingDoNotRunDefaultEnrichers() : void
    {
        $responses = new MockHandler([new Response(200, [], 'MIT terms')]);
        $source = new LicenseBOM(['packages' => [['name' => 'example/library', 'license' => ['MIT']]]]);
        $local = new LocalVendorBOM(sys_get_temp_dir(), new Client(['handler' => $responses]));
        self::assertSame(1, $responses->count());
        $local->merge($source);
        self::assertFalse($local->getPackages()['composer:example/library:']->hasLicenseText());
        self::assertSame(1, $responses->count());

        $plain = new LicenseBOM();
        $plain->merge($local);
        $plain->addPackage(new BOMPackage(['name' => 'example/another', 'license' => ['MIT']]));
        self::assertCount(2, $plain->getPackages());
        foreach ($plain->getPackages() as $package) {
            self::assertFalse($package->hasLicenseText());
        }
        self::assertSame(1, $responses->count());
    }

    /**
     * Merged project terms must take precedence before any remote source is consulted.
     *
     * @return void
     */
    public function testOverridesAreMergedBeforeExplicitEnrichment() : void
    {
        $local = new LocalVendorBOM(sys_get_temp_dir(), new Client(['handler' => new MockHandler()]));
        $local->merge(new LicenseBOM(['packages' => [[
            'name' => 'example/library', 'licenses' => [[
                'name' => 'MIT', 'link' => 'https://raw.githubusercontent.com/example/library/main/LICENSE'
            ]]
        ]]]));
        $local->merge(new LicenseBOM(['licenses' => [['name' => 'MIT', 'text' => 'Reviewed project terms']]]));
        self::assertSame($local, $local->enrich());
        $license = $local->getPackages()['composer:example/library:']->getLicenses()['MIT'];
        self::assertSame('Reviewed project terms', $license->getText());
        self::assertSame('config', $license->getSource());
    }

    /**
     * Manifest discovery and later merges require explicit enrichment, without accumulating passes.
     *
     * @return void
     */
    public function testManifestRecoveryAndLaterMergesWaitForEnrichment() : void
    {
        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('local-vendor-bom-', true);
        $packagePath = $folder . DIRECTORY_SEPARATOR . 'npm-asset' . DIRECTORY_SEPARATOR . 'example';
        $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'package.json';
        mkdir($packagePath, 0777, true);
        try {
            file_put_contents($manifestPath, '{"license":"MIT"}');
            $responses = new MockHandler([new Response(200, [], 'MIT terms')]);
            $local = new LocalVendorBOM($folder, new Client(['handler' => $responses]));
            $local->merge(new LicenseBOM(['packages' => [['name' => 'npm-asset/example']]]));
            $package = $local->getPackages()['npm:example:'];
            self::assertFalse($package->hasLicense());
            self::assertSame(1, $responses->count());

            $local->enrich();
            self::assertSame('MIT', $package->getLicenseUsed());
            self::assertSame('MIT terms', $package->getLicenses()['MIT']->getText());
            self::assertSame('spdx', $package->getLicenses()['MIT']->getSource());
            self::assertSame(0, $responses->count());
            $local->enrich();
            self::assertSame(0, $responses->count());

            $local->merge(new LicenseBOM(['packages' => [['name' => 'example/later', 'license' => ['MIT']]]]));
            $later = $local->getPackages()['composer:example/later:'];
            self::assertFalse($later->hasLicenseText());
            self::assertSame(0, $responses->count());
            $responses->append(new Response(200, [], 'Later MIT terms'));
            $local->enrich();
            self::assertSame('Later MIT terms', $later->getLicenses()['MIT']->getText());
            self::assertSame(0, $responses->count());
        } finally {
            if (is_file($manifestPath)) {
                unlink($manifestPath);
            }
            rmdir($packagePath);
            rmdir(dirname($packagePath));
            rmdir($folder);
        }
    }
}
