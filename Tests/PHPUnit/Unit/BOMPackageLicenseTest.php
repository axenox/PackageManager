<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\BOMPackageLicense;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseSPDXEnricher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonMapError;
use PHPUnit\Framework\TestCase;

/**
 * Protects provenance and compact evidence independently of package discovery.
 */
class BOMPackageLicenseTest extends TestCase
{
    /**
     * Per-license file reads must update the original objects without conflating their terms.
     *
     * @return void
     */
    public function testFileEnricherUpdatesDistinctLicenseObjects() : void
    {
        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('license-objects-', true);
        mkdir($folder);
        try {
            file_put_contents($folder . '/MIT-LICENSE', 'MIT terms');
            file_put_contents($folder . '/BSD-LICENSE', 'BSD terms');
            $package = new BOMPackage([
                'name' => 'example/library', 'license_used' => 'MIT AND BSD-3-Clause',
                'licenses' => [
                    ['name' => 'MIT', 'file' => 'MIT-LICENSE'],
                    ['name' => 'BSD-3-Clause', 'file' => 'BSD-LICENSE']
                ]
            ]);
            $licenses = $package->getLicenses();
            (new FindLicenseFileEnricher($folder))->enrich($package);
            self::assertSame('MIT terms', $licenses['MIT']->getText());
            self::assertSame('BSD terms', $licenses['BSD-3-Clause']->getText());
            self::assertSame('local', $licenses['MIT']->getSource());
            self::assertSame('local', $licenses['BSD-3-Clause']->getSource());
            self::assertSame($licenses['MIT'], $package->getLicenses()['MIT']);
            self::assertTrue($package->hasLicenseText());
        } finally {
            foreach (['MIT-LICENSE', 'BSD-LICENSE'] as $file) {
                if (is_file($folder . '/' . $file)) {
                    unlink($folder . '/' . $file);
                }
            }
            rmdir($folder);
        }
    }

    /**
     * An explicit conjunction without declarations still needs live objects for every required term.
     *
     * @return void
     */
    public function testSpdxEnrichesSelectionOnlyLicenseObjects() : void
    {
        $handler = new MockHandler([new Response(200, [], 'MIT terms'), new Response(200, [], 'BSD terms')]);
        $package = new BOMPackage(['name' => 'example/library', 'license_used' => 'MIT AND BSD-3-Clause']);
        $licenses = $package->getLicenses();
        $enricher = new FindLicenseSPDXEnricher('', new Client(['handler' => HandlerStack::create($handler)]));
        $enricher->enrich($package);
        self::assertSame(['MIT', 'BSD-3-Clause'], array_keys($licenses));
        self::assertSame('MIT terms', $licenses['MIT']->getText());
        self::assertSame('BSD terms', $licenses['BSD-3-Clause']->getText());
        self::assertSame('spdx', $licenses['BSD-3-Clause']->getSource());
        self::assertSame($licenses['BSD-3-Clause'], $package->getLicenses()['BSD-3-Clause']);
        self::assertTrue($package->hasLicenseText());
        self::assertSame('BSD terms', $package->toBOM()['licenses'][1]['text']);
        self::assertSame(0, $handler->count());
    }

    /**
     * Resolving an alternative must not overwrite existing terms or silently change the selection.
     *
     * @return void
     */
    public function testSpdxEnrichesUnselectedAlternativeWithoutOverwritingText() : void
    {
        $handler = new MockHandler([new Response(200, [], 'Apache terms')]);
        $package = new BOMPackage(['name' => 'example/library', 'license_used' => 'MIT', 'licenses' => [
            ['name' => 'MIT', 'text' => 'Existing MIT terms'],
            ['name' => 'Apache-2.0', 'link' => 'https://example.org/license']
        ]]);
        $licenses = $package->getLicenses();
        (new FindLicenseSPDXEnricher('', new Client(['handler' => HandlerStack::create($handler)])))->enrich($package);
        self::assertSame('Existing MIT terms', $licenses['MIT']->getText());
        self::assertSame('config', $licenses['MIT']->getSource());
        self::assertSame('Apache terms', $licenses['Apache-2.0']->getText());
        self::assertSame('spdx', $licenses['Apache-2.0']->getSource());
        self::assertSame('MIT', $package->getLicenseUsed());
        self::assertSame(0, $handler->count());
    }

    /**
    * Resolved terms must survive selection changes just as terms stored through named setters did.
     *
     * @return void
     */
    public function testResolvedSelectionObjectsRemainLiveWhenSelectionChanges() : void
    {
        $package = new BOMPackage(['name' => 'example/library', 'license_used' => 'MIT']);
        $license = $package->getLicenses()['MIT'];
        $license->setText('MIT terms', 'spdx');
        $package->setLicenseUsed('MIT');
        self::assertSame($license, $package->getLicenses()['MIT']);
        self::assertTrue($package->hasLicenseText());
        $package->setLicenseUsed('BSD-3-Clause');
        self::assertSame(['MIT', 'BSD-3-Clause'], array_keys($package->getLicenses()));
        self::assertSame($license, $package->getLicenses()['MIT']);
        self::assertFalse($package->hasLicenseText());
        $package->setLicenseUsed(null);
        self::assertSame(['MIT'], array_keys($package->getLicenses()));
        self::assertTrue($package->hasLicenseText());
    }

    /**
     * An unresolved placeholder must not become a declaration merely because it was once selected.
     *
     * @return void
     */
    public function testUnresolvedSelectionObjectsDisappearWhenSelectionChanges() : void
    {
        $package = new BOMPackage(['name' => 'example/library', 'license_used' => 'MIT']);
        self::assertSame(['MIT'], array_keys($package->getLicenses()));
        $package->setLicenseUsed('BSD-3-Clause');
        self::assertSame(['BSD-3-Clause'], array_keys($package->getLicenses()));
        $package->setLicenseUsed(null);
        self::assertSame([], $package->getLicenses());
    }

    /**
     * Package consumers must explicitly select an object rather than use per-license forwarding getters.
     *
     * @return void
     */
    public function testPackageExposesLicenseObjectsInsteadOfPerLicenseGetters() : void
    {
        $package = new BOMPackage(['name' => 'example/library', 'licenses' => [['name' => 'MIT']]]);
        foreach (['getLicenseName', 'getLicenseNames', 'getLicenseText', 'getLicenseLink', 'getLicenseFile', 'getLicenseTextSource'] as $method) {
            self::assertFalse(method_exists($package, $method));
        }
        self::assertSame('MIT', $package->getLicenseUsed());
        self::assertSame('MIT', $package->getLicenses()['MIT']->getName());
    }

    /**
     * Nested configuration must not bypass the license evidence boundary.
     *
     * @return void
     */
    public function testPackageConfigurationRejectsRecordedEvidence() : void
    {
        $this->expectException(UxonMapError::class);
        $package = new BOMPackage();
        $package->importUxonObject(new UxonObject(['name' => 'example/library', 'licenses' => [
            ['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]
        ]]));
    }

    /**
     * Shared configuration must not grant evidence that package configuration cannot grant.
     *
     * @return void
     */
    public function testSharedConfigurationRejectsRecordedEvidence() : void
    {
        $this->expectException(UxonMapError::class);
        $bom = new LicenseBOM();
        $bom->importUxonObject(new UxonObject(['licenses' => [
            ['name' => 'MIT', 'text_available' => true]
        ]]));
    }

    /**
     * Archived package and shared evidence must remain usable without configurable evidence setters.
     *
     * @return void
     */
    public function testInventoryBOMEvidenceRoundTripsThroughResultImport() : void
    {
        $data = [
            'packages' => [['name' => 'example/library', 'licenses' => [['name' => 'MIT']]]],
            'licenses' => [['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]]
        ];
        $bom = new LicenseBOM($data);
        self::assertTrue($bom->getPackages()['composer:example/library:']->hasLicenseText());
        $snapshot = $bom->toBOM();
        self::assertSame('spdx', $snapshot['packages'][0]['licenses'][0]['source']);
        self::assertTrue((new LicenseBOM($snapshot))->getPackages()['composer:example/library:']->hasLicenseText());
    }

    /**
     * Designers cannot claim that a particular enricher supplied configured text.
     *
     * @return void
     */
    public function testConfigurationRejectsLicenseSource() : void
    {
        $this->expectException(UxonMapError::class);
        $license = new BOMPackageLicense();
        $license->importUxonObject(new UxonObject(['name' => 'MIT', 'source' => 'spdx']));
    }

    /**
     * Designers cannot mark unresolved terms as available through configuration.
     *
     * @return void
     */
    public function testConfigurationRejectsLicenseAvailability() : void
    {
        $this->expectException(UxonMapError::class);
        $license = new BOMPackageLicense();
        $license->importUxonObject(new UxonObject(['name' => 'MIT', 'text_available' => true]));
    }

    /**
     * A configured file reference is not proof that its contents were successfully read.
     *
     * @return void
     */
    public function testConfiguredFileDoesNotClaimResolution() : void
    {
        $license = new BOMPackageLicense();
        $license->importUxonObject(new UxonObject(['name' => 'MIT', 'file' => 'example/library/LICENSE']));
        self::assertFalse($license->hasText());
        self::assertNull($license->getSource());
        self::assertSame('example/library/LICENSE', $license->getFile());
    }

    /**
     * Archived evidence must round-trip without becoming configurable provenance.
     *
     * @return void
     */
    public function testRecordedBOMEvidenceUsesSeparateImportPath() : void
    {
        $data = ['name' => 'MIT', 'source' => 'custom-enricher', 'text_available' => true];
        $license = new BOMPackageLicense($data);
        self::assertSame($data, $license->toBOM());
        self::assertSame(['name' => 'MIT'], $license->exportUxonObject()->toArray());
        self::assertTrue($license->hasText());
        $data['text'] = '';
        self::assertFalse((new BOMPackageLicense($data))->hasText());
    }

    /**
     * Shared defaults and package additions must remain runtime operations on the collection.
     *
     * @return void
     */
    public function testInventoryRuntimeChangesPreserveImportedConfiguration() : void
    {
        $configuration = [
            'packages' => [['name' => 'example/private', 'license_used' => 'Commercial']],
            'licenses' => [['name' => 'Commercial', 'text' => 'Initial terms']]
        ];
        $bom = new LicenseBOM();
        $bom->importUxonObject(new UxonObject($configuration));
        $bom->setLicenses([['name' => 'Commercial', 'text' => 'Updated shared terms']]);
        $package = new BOMPackage(['name' => 'example/library', 'license' => ['MIT']]);
        $bom->addPackage($package);
        $package->setLicenseText('MIT', 'Runtime terms', 'custom-enricher');
        $snapshot = $bom->toBOM();
        self::assertSame($configuration, $bom->exportUxonObject()->toArray());
        self::assertCount(2, $snapshot['packages']);
        self::assertSame('Updated shared terms', $snapshot['packages'][0]['licenses'][0]['text']);
        self::assertSame('Runtime terms', $snapshot['packages'][1]['licenses'][0]['text']);
        self::assertSame('custom-enricher', $snapshot['packages'][1]['licenses'][0]['source']);
        self::assertSame('Updated shared terms', $snapshot['licenses'][0]['text']);
    }

    /**
     * Selection, enrichment and merging must not turn configuration export into mutable state.
     *
     * @return void
     */
    public function testPackageRuntimeChangesPreserveImportedConfiguration() : void
    {
        $configuration = [
            'name' => 'example/library', 'version' => '1.0.0',
            'source' => ['type' => 'git', 'reference' => 'abc'],
            'licenses' => [['name' => 'MIT']]
        ];
        $package = new BOMPackage();
        $package->importUxonObject(new UxonObject($configuration));
        $package->setLicenseUsed('MIT')->setLicenseText('MIT', 'Runtime terms', 'custom-enricher');
        $package->merge(new BOMPackage(['name' => 'example/library', 'description' => 'Supplement',
            'source' => ['url' => 'https://example.org/library.git']]));
        self::assertSame($configuration, $package->exportUxonObject()->toArray());
        self::assertSame('Runtime terms', $package->toBOM()['licenses'][0]['text']);
        self::assertSame('MIT', $package->toBOM()['license_used']);
        self::assertSame('Supplement', $package->getDescription());
        self::assertSame('abc', $package->toBOM()['source']['reference']);
        $package->getLicenses()['MIT']->setText('Updated nested terms', 'custom-enricher');
        self::assertSame('Updated nested terms', $package->toBOM()['licenses'][0]['text']);
        self::assertSame($configuration, $package->exportUxonObject()->toArray());
    }

    /**
     * API changes belong to runtime state, not the configuration retained by the UXON trait.
     *
     * @return void
     */
    public function testLicenseRuntimeChangesPreserveImportedConfiguration() : void
    {
        $configuration = ['name' => 'MIT', 'link' => 'https://example.org/license'];
        $license = new BOMPackageLicense();
        $license->importUxonObject(new UxonObject($configuration));
        $license->setText('Downloaded terms', 'custom-enricher');
        self::assertSame($configuration, $license->exportUxonObject()->toArray());
        self::assertSame('Downloaded terms', $license->toBOM()['text']);
        self::assertSame('custom-enricher', $license->toBOM()['source']);
        self::assertTrue($license->toBOM()['text_available']);
        $incoming = new BOMPackageLicense(['name' => 'MIT']);
        $incoming->setText('Updated terms', 'another-enricher');
        $license->merge($incoming);
        self::assertSame('Updated terms', $license->getText());
        self::assertSame('another-enricher', $license->getSource());
        self::assertSame($configuration, $license->exportUxonObject()->toArray());
    }

    /**
     * Existing URLs and legacy aliases must not prevent successful standard-term resolution.
     *
     * @return void
     */
    public function testSpdxResolvesConjunctionAndRecordsActualSource() : void
    {
        $handler = new MockHandler([new Response(200, [], 'Font terms'), new Response(200, [], 'Code terms')]);
        $enricher = new FindLicenseSPDXEnricher('', new Client(['handler' => HandlerStack::create($handler)]));
        $package = new BOMPackage(['name' => 'example/fonts', 'license' => ['(OFL-1.1 AND MIT)'],
            'license_link' => ['MIT' => 'https://example.org/license']]);
        $enricher->enrich($package);
        self::assertTrue($package->hasLicenseText());
        self::assertSame('spdx', $package->getLicenses()['OFL-1.1']->getSource());
        self::assertSame('spdx', $package->getLicenses()['MIT']->getSource());
        self::assertSame(0, $handler->count());
    }

    /**
     * Compact exports must preserve resolution evidence while withholding the actual terms.
     *
     * @return void
     */
    public function testCompactExportRoundTripsSpdxEvidence() : void
    {
        $bom = new LicenseBOM();
        $package = new BOMPackage(['name' => 'example/library', 'license' => ['MIT']]);
        $package->setLicenseText('MIT', 'Standard terms', 'spdx');
        $bom->addPackage($package);
        $json = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true, 512, JSON_THROW_ON_ERROR);
        $data = $json['packages'][0];
        self::assertArrayNotHasKey('license', $data);
        self::assertArrayNotHasKey('license_text_source', $data);
        self::assertSame([['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]], $data['licenses']);
        self::assertTrue((new BOMPackage($data))->hasLicenseText());
    }

    /**
    * Shared UXON definitions must resolve selected terms in the runtime inventory.
     *
     * @return void
     */
    public function testInventoryImportsTypedPackagesAndSharedTerms() : void
    {
        $bom = new LicenseBOM();
        $bom->importUxonObject(new UxonObject([
            'packages' => [['name' => 'example/private', 'license_used' => 'Commercial']],
            'licenses' => [['name' => 'Commercial', 'text' => 'Shared terms']]
        ]));
        $package = $bom->getPackages()['composer:example/private:'];
        self::assertTrue($package->hasLicenseText());
        self::assertSame('Shared terms', $package->getLicenses()['Commercial']->getText());
        $export = $bom->toBOM();
        self::assertSame('Commercial', $export['licenses'][0]['name']);
        self::assertSame('Shared terms', $export['packages'][0]['licenses'][0]['text']);
    }

    /**
     * Composer transport metadata must not leak into the canonical UXON package.
     *
     * @return void
     */
    public function testPackageFiltersComposerMetadataAndImportsLegacyTerms() : void
    {
        $package = new BOMPackage([
            'name' => 'example/library', 'license' => ['MIT'], 'license_text' => ['MIT' => 'Terms'],
            'require' => ['php' => '^8.0'], 'source' => ['type' => 'git', 'reference' => 'abc', 'mirrors' => []]
        ]);
        $data = $package->toBOM();
        self::assertArrayNotHasKey('require', $data);
        self::assertArrayNotHasKey('license', $data);
        self::assertSame(['type' => 'git', 'reference' => 'abc'], $data['source']);
        self::assertSame('Terms', $package->getLicenses()['MIT']->getText());
        self::assertTrue($package->hasLicenseText());
    }

    /**
     * Both terms are required for an AND expression; alternatives must not be flattened.
     *
     * @return void
     */
    public function testConjunctionRequiresEveryComponent() : void
    {
        $package = new BOMPackage();
        $package->importUxonObject(new UxonObject([
            'name' => 'example/fonts', 'licenses' => [['name' => '(OFL-1.1 AND MIT)']]
        ]));
        self::assertSame(['OFL-1.1', 'MIT'], array_keys($package->getLicenses()));
        self::assertSame('(OFL-1.1 AND MIT)', $package->getLicenseUsed());
        $package->setLicenseText('OFL-1.1', 'Font terms', 'spdx');
        self::assertFalse($package->hasLicenseText());
        $package->setLicenseText('MIT', 'Code terms', 'spdx');
        self::assertTrue($package->hasLicenseText());
        self::assertStringContainsString('Code terms', $package->getLicenses()['MIT']->getText());
        self::assertSame(['MIT OR Apache-2.0'], BOMPackage::splitLicenseNames('MIT OR Apache-2.0'));
    }

    /**
     * Successfully downloaded terms must retain their true origin through export.
     *
     * @return void
     */
    public function testTextRecordsExplicitProvenance() : void
    {
        $license = new BOMPackageLicense(['name' => 'MIT']);
        $license->setText('Standard terms', 'spdx');
        self::assertTrue($license->hasText());
        self::assertSame('spdx', $license->getSource());
        self::assertSame('Standard terms', $license->toBOM()['text']);
    }

    /**
     * A reference must not erase already resolved terms or imply a successful download.
     *
     * @return void
     */
    public function testLinkOnlySupplementPreservesResolvedTerms() : void
    {
        $license = new BOMPackageLicense(['name' => 'MIT']);
        $license->setText('Terms', 'spdx');
        $link = new BOMPackageLicense(['name' => 'MIT', 'link' => 'https://example.org/license']);
        self::assertFalse($link->hasText());
        $license->merge($link);
        self::assertSame('spdx', $license->getSource());
        self::assertSame('Terms', $license->getText());
        self::assertSame('https://example.org/license', $license->getLink());
    }

    /**
     * Compact inventories must distinguish successful resolution from attempted lookup.
     *
     * @return void
     */
    public function testCompactEvidenceDoesNotDependOnPropertyOrder() : void
    {
        $license = new BOMPackageLicense(['source' => 'spdx', 'name' => 'MIT', 'text_available' => true]);
        self::assertTrue($license->hasText());
        self::assertNull($license->getText());
        $unresolved = new BOMPackageLicense(['name' => 'MIT', 'source' => 'spdx']);
        self::assertFalse($unresolved->hasText());
    }
}