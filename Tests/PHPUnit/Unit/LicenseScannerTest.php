<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\LicenseScanner;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;

/**
 * Checks license completeness using local evidence without downloads or a persisted metamodel.
 */
class LicenseScannerTest extends AuditTestCase
{
    /**
    * Source metadata must not turn contradictory full-inventory evidence into a clean result.
     * 
     * @return void
     */
    public function testInlineTextOverridesCompactSourceMetadata() : void
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => [
            ['name' => 'example/complete', 'license' => ['MIT'], 'license_text' => ['MIT' => 'Terms'], 'license_text_source' => ['MIT' => BOMPackage::LICENSE_TEXT_SOURCE_MISSING]],
            ['name' => 'example/blank', 'license' => ['MIT'], 'license_text' => ['MIT' => ' '], 'license_text_source' => ['MIT' => BOMPackage::LICENSE_TEXT_SOURCE_EMBEDDED]]
        ]]]]));
        $findings = (new LicenseScanner($this->workbench))->audit($task);
        self::assertCount(1, $findings);
        self::assertSame('example/blank', $findings[0]->getPackage());
        self::assertSame('missing-license-text', $findings[0]->getSourceId());
    }

    /**
    * Unknown source values must fail rather than silently certify or reject license terms.
     * 
     * @return void
     */
    public function testMalformedCompactSourceFails() : void
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => [[
            'name' => 'example/library', 'license' => ['MIT'], 'license_text_source' => ['MIT' => 'unknown']
        ]]]]]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('license_text_source values must be embedded, local, link or missing');
        (new LicenseScanner($this->workbench))->audit($task);
    }

    /**
     * Archived compact inventories must preserve license findings without storing any terms.
     * 
     * @return void
     */
    public function testMinimalExportPreservesFullLicenseFindingsWithoutTexts() : void
    {
        $bom = new LicenseBOM();
        $bom->setLicenses(['Client-License' => ['license_text' => 'Shared installation terms']]);
        foreach ([
            ['name' => 'example/complete', 'license' => ['MIT'], 'license_text' => ['MIT' => 'MIT terms']],
            ['name' => 'example/shared', 'ecosystem' => 'private', 'license_used' => 'Client-License'],
            ['name' => 'example/dual', 'license' => ['MIT', 'BSD-3-Clause'], 'license_used' => 'BSD-3-Clause', 'license_text' => ['MIT' => 'MIT terms']],
            ['name' => 'example/link', 'license' => ['MIT'], 'license_link' => ['MIT' => 'https://example.org/license']],
            ['name' => 'example/blank', 'license' => ['MIT'], 'license_text' => ['MIT' => " \n "]],
            ['name' => 'example/missing'],
            ['name' => 'example/none', 'license' => ['NOASSERTION']],
            ['name' => 'example/local', 'license' => ['MIT'], 'license_file' => ['MIT' => 'example/local/LICENSE']]
        ] as $data) {
            $bom->addPackage(new BOMPackage($data));
        }
        $minimalJson = (new JsonBOM($bom))->setFormat('minimal')->buildJson();
        $minimal = json_decode($minimalJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('licenses', $minimal);
        foreach ($minimal['packages'] as $data) {
            self::assertArrayNotHasKey('license_text', $data);
            self::assertArrayNotHasKey('license_link', $data);
            self::assertArrayNotHasKey('license_file', $data);
        }
        self::assertStringNotContainsString('MIT terms', $minimalJson);
        self::assertStringNotContainsString('Shared installation terms', $minimalJson);
        self::assertSame(['MIT' => BOMPackage::LICENSE_TEXT_SOURCE_EMBEDDED], $minimal['packages'][0]['license_text_source']);
        self::assertSame(['Client-License'], $minimal['packages'][1]['license']);
        self::assertSame(['Client-License' => BOMPackage::LICENSE_TEXT_SOURCE_EMBEDDED], $minimal['packages'][1]['license_text_source']);
        self::assertSame(['MIT' => BOMPackage::LICENSE_TEXT_SOURCE_EMBEDDED, 'BSD-3-Clause' => BOMPackage::LICENSE_TEXT_SOURCE_MISSING], $minimal['packages'][2]['license_text_source']);
        self::assertSame(['MIT' => BOMPackage::LICENSE_TEXT_SOURCE_LINK], $minimal['packages'][3]['license_text_source']);
        self::assertSame(['MIT' => BOMPackage::LICENSE_TEXT_SOURCE_LOCAL], $minimal['packages'][7]['license_text_source']);
        $scanner = new LicenseScanner($this->workbench);
        $results = [];
        foreach ([(new JsonBOM($bom))->buildJson(), $minimalJson] as $json) {
            $task = new GenericTask($this->workbench);
            $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => $json]]));
            self::assertTrue($scanner->supports($task));
            $results[] = array_map(static fn ($finding) => [
                $finding->getPackage(), $finding->getSourceId(), $finding->getLevel()
            ], $scanner->audit($task));
        }
        self::assertCount(7, $results[0]);
        self::assertSame($results[0], $results[1]);
        $roundTrip = new LicenseBOM();
        foreach ($minimal['packages'] as $data) {
            $roundTrip->addPackage(new BOMPackage($data));
        }
        self::assertSame($minimalJson, (new JsonBOM($roundTrip))->setFormat('minimal')->buildJson());
    }

    /**
     * Severity and evidence must distinguish absent declarations from absent license text.
     * 
     * @return void
     */
    public function testMissingLicenseAndTextProduceIndependentAdvisories() : void
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => [
            ['name' => 'example/missing', 'version' => '1.0.0'],
            ['name' => 'example/link-only', 'license' => ['MIT'], 'license_link' => ['MIT' => 'https://example.org/license']],
            ['name' => 'example/whitespace', 'license_used' => 'Client-License', 'license_text' => ['Client-License' => " \n "]],
            ['name' => 'example/complete', 'license' => ['MIT'], 'license_text' => ['MIT' => 'Terms']]
        ]]]]));
        $scanner = new LicenseScanner($this->workbench);
        self::assertTrue($scanner->supports($task));
        $findings = $scanner->audit($task);
        self::assertCount(4, $findings);
        self::assertSame(['missing-license', 'missing-license-text', 'missing-license-text', 'missing-license-text'],
            array_map(static fn ($finding) => $finding->getSourceId(), $findings));
        self::assertSame([300, 100, 100, 100], array_map(static fn ($finding) => $finding->getLevel(), $findings));
        self::assertSame('license', $findings[0]->getType());
        self::assertSame('license', $findings[0]->getSource());
        self::assertSame('1.0.0', $findings[0]->getVersionInstalled());
        self::assertSame([], $scanner->getHints());
    }

    /**
     * Named definitions and explicit selections must be resolved before checking private packages.
     * 
     * @return void
     */
    public function testNamedLicensesAndEffectiveSelectionAreUsedForPrivatePackages() : void
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => [
            'packages' => [
                ['name' => 'example/private', 'ecosystem' => 'private', 'license_used' => 'Client-License'],
                ['name' => 'example/dual', 'license' => ['MIT', 'Client-License'], 'license_used' => 'Client-License', 'license_text' => ['MIT' => 'MIT terms']],
                ['name' => 'example/private-missing', 'ecosystem' => 'private']
            ],
            'licenses' => ['Client-License' => ['license_text' => 'Client terms']]
        ]]]));
        $findings = (new LicenseScanner($this->workbench))->audit($task);
        self::assertCount(2, $findings);
        self::assertSame(['example/private-missing', 'example/private-missing'], array_map(static fn ($finding) => $finding->getPackage(), $findings));
        self::assertSame('private', $findings[0]->getEcosystem());
    }

    /**
     * Unsupported explicit artifacts must not accidentally audit the current installation.
     * 
     * @return void
     */
    public function testUnsupportedArtifactsAndEmptyInventories() : void
    {
        $scanner = new LicenseScanner($this->workbench);
        foreach ([['composer_lock' => ['packages' => []]], ['sbom' => ['bomFormat' => 'CycloneDX', 'components' => []]],
            ['sbom' => ['spdxVersion' => 'SPDX-2.3', 'packages' => []]]] as $parameters) {
            $task = new GenericTask($this->workbench);
            $task->importUxonObject(new UxonObject(['parameters' => $parameters]));
            self::assertFalse($scanner->supports($task));
            self::assertSame([], $scanner->audit($task));
        }
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => []]]]));
        self::assertTrue($scanner->supports($task));
        self::assertSame([], $scanner->audit($task));
        $scanner->install($task);
    }

    /**
     * Malformed package inventories must fail instead of appearing license-compliant.
     * 
     * @return void
     */
    public function testMalformedInventoryFails() : void
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => [['license' => ['MIT']]]]]]));
        $this->expectException(RuntimeException::class);
        (new LicenseScanner($this->workbench))->audit($task);
    }

    /**
     * Text for a different licensing alternative must not satisfy the selected license.
     * 
     * @return void
     */
    public function testOnlyEffectiveLicenseTextCounts() : void
    {
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => ['packages' => [[
            'name' => '@scope/library', 'ecosystem' => 'npm', 'license' => ['MIT', 'BSD-3-Clause'],
            'license_used' => 'BSD-3-Clause', 'license_text' => ['MIT' => 'MIT terms']
        ]]]]]));
        $findings = (new LicenseScanner($this->workbench))->audit($task);
        self::assertCount(1, $findings);
        self::assertSame('missing-license-text', $findings[0]->getSourceId());
        self::assertSame('npm-asset/scope--library', $findings[0]->getPackage());
        self::assertSame('npm', $findings[0]->getEcosystem());
    }

    /**
     * The artifact contract accepts JSON strings, decoded arrays and UXON consistently.
     * 
     * @return void
     */
    public function testArtifactRepresentations() : void
    {
        $data = ['packages' => [['name' => 'example/library']]];
        foreach ([$data, json_encode($data, JSON_THROW_ON_ERROR), new UxonObject($data)] as $artifact) {
            $task = new GenericTask($this->workbench);
            $task->importUxonObject(new UxonObject(['parameters' => ['sbom' => $artifact]]));
            self::assertCount(2, (new LicenseScanner($this->workbench))->audit($task));
        }
    }

    /**
     * Folder scans require a generated inventory; explicit artifacts must still prevent fallback.
     * 
     * @return void
     */
    public function testFolderInventoryAndExplicitArtifactPrecedence() : void
    {
        $folder = $this->temporaryFolder();
        $scanner = new LicenseScanner($this->workbench);
        $task = new GenericTask($this->workbench);
        $task->importUxonObject(new UxonObject(['parameters' => ['folder' => $folder]]));
        self::assertFalse($scanner->supports($task));
        self::assertTrue(mkdir($folder . '/vendor'));
        try {
            $this->writeFixture($folder . '/vendor', 'licenses.json', '{"packages":[{"name":"example/library"}]}');
            self::assertTrue($scanner->supports($task));
            self::assertCount(2, $scanner->audit($task));
            $task->importUxonObject(new UxonObject(['parameters' => [
                'folder' => $folder, 'composer_lock' => ['packages' => []]
            ]]));
            self::assertFalse($scanner->supports($task));
            self::assertSame([], $scanner->audit($task));
        } finally {
            if (is_file($folder . '/vendor/licenses.json')) {
                unlink($folder . '/vendor/licenses.json');
            }
            rmdir($folder . '/vendor');
        }
    }
}