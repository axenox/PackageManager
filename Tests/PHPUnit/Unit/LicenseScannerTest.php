<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\LicenseScanner;
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