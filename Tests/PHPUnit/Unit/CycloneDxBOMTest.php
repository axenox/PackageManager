<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Format\CycloneDxBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use exface\Core\Exceptions\RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * Covers CycloneDX serialization without Workbench, network access or installed scanners.
 */
class CycloneDxBOMTest extends TestCase
{
    /**
     * Empty inventories remain valid JSON arrays and do not assert dependency completeness.
     * 
     * @return void
     */
    public function testEmptyInventory() : void
    {
        $document = json_decode((new CycloneDxBOM(new LicenseBOM()))->toJSON(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('CycloneDX', $document['bomFormat']);
        self::assertSame('1.6', $document['specVersion']);
        self::assertSame(1, $document['version']);
        self::assertSame([], $document['components']);
        self::assertArrayNotHasKey('dependencies', $document);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $document['metadata']['timestamp']);
    }

    /**
     * Merged metadata supplies the version and selected license without changing the input.
     * 
     * @return void
     */
    public function testCombinedPackageMetadata() : void
    {
        $bom = new LicenseBOM();
        $bom->addPackage(new BOMPackage([
            'name' => 'example/library', 'type' => 'library', 'version' => 'v1.2.3',
            'description' => 'Example library', 'license' => ['MIT', 'proprietary'],
            'source' => ['url' => 'https://example.org/source'], 'homepage' => 'https://example.org'
        ]));
        $includes = new LicenseBOM();
        $includes->addPackage(new BOMPackage([
            'name' => 'example/library', 'license_used' => 'proprietary',
            'license_text' => ['proprietary' => "Custom terms\nCopyright"],
            'license_link' => ['proprietary' => 'https://example.org/license']
        ]));
        $bom->merge($includes);
        $before = $bom->getPackages()['example/library']->toComposerArray();
        $exporter = new CycloneDxBOM($bom);
        $component = json_decode($exporter->toJSON(), true)['components'][0];
        self::assertSame('example/library', $component['name']);
        self::assertSame('library', $component['type']);
        self::assertSame('v1.2.3', $component['version']);
        self::assertSame('Example library', $component['description']);
        self::assertSame('pkg:composer/example/library@v1.2.3', $component['purl']);
        self::assertSame('proprietary', $component['licenses'][0]['license']['name']);
        self::assertSame("Custom terms\nCopyright", base64_decode($component['licenses'][0]['license']['text']['content']));
        self::assertSame('base64', $component['licenses'][0]['license']['text']['encoding']);
        self::assertSame('https://example.org/license', $component['licenses'][0]['license']['url']);
        self::assertSame(['vcs', 'website', 'license'], array_column($component['externalReferences'], 'type'));
        self::assertSame($component['bom-ref'], json_decode($exporter->toJSON(), true)['components'][0]['bom-ref']);
        self::assertSame($before, $bom->getPackages()['example/library']->toComposerArray());
    }

    /**
     * Bundled metadata may be missing while asset aliases retain their actual ecosystems.
     * 
     * @return void
     */
    public function testMissingMetadataAndAssetPackageUrls() : void
    {
        $bom = new LicenseBOM();
        foreach ([
            ['name' => 'example/bundled'],
            ['name' => 'npm-asset/scope--package', 'version' => '1.0+build'],
            ['name' => 'npm-asset/jquery', 'version' => '3.7.1'],
            ['name' => 'bower-asset/library', 'license' => ['MIT OR Apache-2.0']]
        ] as $data) {
            $bom->addPackage(new BOMPackage($data));
        }
        $components = json_decode((new CycloneDxBOM($bom))->toJSON(), true)['components'];
        self::assertSame(['type', 'bom-ref', 'name'], array_keys($components[0]));
        self::assertSame('pkg:npm/%40scope/package@1.0%2Bbuild', $components[1]['purl']);
        self::assertSame('pkg:npm/jquery@3.7.1', $components[2]['purl']);
        self::assertSame('pkg:bower/library', $components[3]['purl']);
        self::assertSame('MIT OR Apache-2.0', $components[3]['licenses'][0]['license']['name']);
        self::assertCount(4, array_unique(array_column($components, 'bom-ref')));
    }

    /**
     * Saving produces a readable SBOM and retains the fluent exporter contract.
     * 
     * @return void
     */
    public function testSaveJson() : void
    {
        $path = tempnam(sys_get_temp_dir(), 'cyclonedx-');
        try {
            $exporter = new CycloneDxBOM(new LicenseBOM());
            self::assertSame($exporter, $exporter->saveJSON($path));
            self::assertSame('CycloneDX', json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['bomFormat']);
        } finally {
            unlink($path);
        }
    }

    /**
     * Invalid UTF-8 metadata raises an exception instead of writing an empty document.
     * 
     * @return void
     */
    public function testEncodingFailure() : void
    {
        $bom = new LicenseBOM();
        $bom->addPackage(new BOMPackage(['name' => 'example/library', 'description' => "\xB1"]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot encode CycloneDX BOM');
        (new CycloneDxBOM($bom))->toJSON();
    }

    /**
     * Unwritable destinations raise an exception rather than reporting success.
     * 
     * @return void
     */
    public function testWriteFailure() : void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write CycloneDX BOM');
        (new CycloneDxBOM(new LicenseBOM()))->saveJSON(sys_get_temp_dir());
    }
}