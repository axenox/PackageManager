<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\AbstractAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\TrivySBOMScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureComposerScanner;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\Exceptions\RuntimeException;

/** Covers lock isolation, command failures and parsing without running Composer. */
class ComposerAuditScannerTest extends AuditTestCase
{
    private $folder;
    private $task;
    private $scanner;
    private $lockJson;

    /** {@inheritDoc} @see AuditTestCase::setUp() */
    protected function setUp() : void
    {
        parent::setUp();
        $this->folder = $this->temporaryFolder();
        $this->lockJson = json_encode([
            'packages' => [['name' => 'php/package', 'version' => '1.0.0']], 'packages-dev' => []
        ], JSON_THROW_ON_ERROR);
        $this->writeFixture($this->folder, 'composer.lock', $this->lockJson);
        $this->task = new GenericTask($this->workbench);
        $this->task->setParameter('folder', $this->folder);
        $this->scanner = new FixtureComposerScanner($this->workbench);
        $this->scanner->response = ['advisories' => ['php/package' => [[
            'cve' => 'CVE-2026-0003', 'advisoryId' => 'PKSA-fixture', 'title' => 'Composer fixture', 'severity' => 'high'
        ]]], 'abandoned' => ['old/package' => 'new/package']];
    }

    /**
     * Composer execution belongs only to the scanner that uses it.
     * 
     * @return void
     */
    public function testComposerHelpersBelongToComposerScanner() : void
    {
        self::assertSame(ComposerAuditScanner::class, (new \ReflectionMethod(ComposerAuditScanner::class, 'runComposer'))->getDeclaringClass()->getName());
        self::assertSame(ComposerAuditScanner::class, (new \ReflectionMethod(ComposerAuditScanner::class, 'isComposerAvailable'))->getDeclaringClass()->getName());
        self::assertFalse(method_exists(AbstractAuditScanner::class, 'runComposer'));
        self::assertFalse(method_exists(AbstractAuditScanner::class, 'isComposerAvailable'));
        self::assertFalse(method_exists(ComposerNpmAuditScanner::class, 'runComposer'));
        self::assertFalse(method_exists(TrivySBOMScanner::class, 'runComposer'));
    }

    /**
     * Archived locks are read from parameters without inspecting installation files.
     * 
     * @return void
     */
    public function testComposerLockParameterUsesIsolatedFiles() : void
    {
        $task = new GenericTask($this->workbench);
        $task->setParameter('composer_lock', $this->lockJson);
        $task->setParameter('folder', $this->folder . '/missing');
        self::assertTrue($this->scanner->supports($task));
        $findings = $this->scanner->audit($task);
        self::assertCount(2, $findings);
        self::assertSame('1.0.0', $findings[0]->getVersionInstalled());
        self::assertDirectoryDoesNotExist($this->scanner->scannedFolders[0]);
    }

    /**
     * SBOM-only input must not scan Composer dependencies from the installation.
     * 
     * @return void
     */
    public function testSbomParameterDisablesComposerFolderFallback() : void
    {
        $task = new GenericTask($this->workbench);
        $task->setParameter('sbom', ['bomFormat' => 'CycloneDX']);
        self::assertFalse($this->scanner->supports($task));
        self::assertSame([], $this->scanner->audit($task));
    }

    /** Lock-only scans must preserve the project while parsing advisories and abandoned packages. */
    public function testLockOnlyScanParsesFindingsAndRemovesIsolatedFiles() : void
    {
        self::assertTrue($this->scanner->supports($this->task));
        $rows = $this->scanner->audit($this->task);
        self::assertCount(2, $rows);
        self::assertSame('EOL', $rows[1]->getType());
        self::assertSame('CVE-2026-0003', $rows[0]->getCve());
        self::assertSame('', $rows[1]->getCve());
        self::assertSame('1.0.0', $rows[0]->getVersionInstalled());
        self::assertSame('Replace with new/package', $rows[1]->getRemediation());
        self::assertDirectoryDoesNotExist($this->scanner->scannedFolders[0]);
        self::assertFileDoesNotExist($this->folder . '/composer.json');
        self::assertSame($this->lockJson, file_get_contents($this->folder . '/composer.lock'));
    }

    /**
     * Composer's native fields and ignored-policy notes remain scanner-owned evidence.
     * 
     * @return void
     */
    public function testIgnoredAdvisoriesRetainNativeFieldsAndPolicyReason() : void
    {
        $this->scanner->response['ignored-advisories'] = ['php/package' => [[
            'advisoryId' => 'PKSA-ignored',
            'cve' => null,
            'title' => 'Ignored advisory',
            'severity' => 'moderate',
            'link' => 'https://example.org/composer',
            'description' => 'Composer description',
            'affectedVersions' => '<2',
            'ignoreReason' => 'Reviewed exception'
        ]]];
        $rows = $this->scanner->audit($this->task);
        self::assertCount(3, $rows);
        $finding = $rows[1];
        self::assertSame('PKSA-ignored', $finding->getSourceId());
        self::assertSame('', $finding->getCve());
        self::assertSame('composer', $finding->getSource());
        self::assertSame('Ignored advisory', $finding->getName());
        self::assertSame(200, $finding->getLevel());
        self::assertSame('https://example.org/composer', $finding->getDetailsUrl());
        self::assertSame('Composer description Ignored by Composer policy: Reviewed exception', $finding->getDescription());
        self::assertSame('<2', $finding->getVersionsAffected());
        self::assertSame('1.0.0', $finding->getVersionInstalled());
    }

    /**
     * Abandoned packages without a replacement still provide direct lifecycle findings.
     * 
     * @return void
     */
    public function testAbandonedPackageWithoutReplacementRetainsInstalledVersion() : void
    {
        $this->scanner->response['abandoned'] = ['php/package' => null];
        $rows = $this->scanner->audit($this->task);
        $finding = $rows[1];
        self::assertSame('EOL', $finding->getType());
        self::assertSame('abandoned:php/package', $finding->getSourceId());
        self::assertSame('', $finding->getPublicId());
        self::assertSame('Replace this unmaintained package.', $finding->getRemediation());
        self::assertSame('1.0.0', $finding->getVersionInstalled());
    }

    /** Failed commands must not leak the temporary project or swallow the original failure. */
    public function testScannerFailureStillRemovesIsolatedFiles() : void
    {
        $this->scanner->fail = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated scanner failure');
        try {
            $this->scanner->audit($this->task);
        } finally {
            self::assertCount(1, $this->scanner->scannedFolders);
            self::assertDirectoryDoesNotExist($this->scanner->scannedFolders[0]);
        }
    }

    /** Invalid Composer output must retain stderr diagnostics and clean up the isolated project. */
    public function testInvalidOutputRetainsStderrAndRemovesIsolatedFiles() : void
    {
        $this->scanner->stdout = '';
        $this->scanner->stderr = 'Simulated Composer initialization failure';
        try {
            $this->scanner->audit($this->task);
            self::fail('Empty Composer output was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringStartsWith('Composer audit returned invalid JSON (exit code 1)', $error->getMessage());
            self::assertStringContainsString($this->scanner->stderr, $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->scanner->scannedFolders[0]);
    }

    /** Composer emits no JSON for empty locks, so they must bypass command execution. */
    public function testEmptyLockDoesNotExecuteComposer() : void
    {
        $this->writeFixture($this->folder, 'composer.lock', '{"packages":[],"packages-dev":[]}');
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(0, $this->scanner->scannedFolders);
    }

    /** Malformed lock files must fail explicitly rather than report no vulnerabilities. */
    public function testMalformedLockFailsBeforeComposerExecution() : void
    {
        $this->writeFixture($this->folder, 'composer.lock', '{invalid-json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid scanner JSON:');
        try {
            $this->scanner->audit($this->task);
        } finally {
            self::assertCount(0, $this->scanner->scannedFolders);
        }
    }
}