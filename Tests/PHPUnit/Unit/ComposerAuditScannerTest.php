<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

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

    /** Lock-only scans must preserve the project while parsing advisories and abandoned packages. */
    public function testLockOnlyScanParsesFindingsAndRemovesIsolatedFiles() : void
    {
        self::assertTrue($this->scanner->supports($this->task));
        $rows = $this->scanner->audit($this->task);
        self::assertCount(2, $rows);
        self::assertSame('EOL', $rows[1]['TYPE']);
        self::assertSame('CVE-2026-0003', $rows[0]['CVE']);
        self::assertSame('', $rows[1]['CVE']);
        self::assertDirectoryDoesNotExist($this->scanner->scannedFolders[0]);
        self::assertFileDoesNotExist($this->folder . '/composer.json');
        self::assertSame($this->lockJson, file_get_contents($this->folder . '/composer.lock'));
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