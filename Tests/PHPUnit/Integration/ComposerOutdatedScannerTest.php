<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use axenox\PackageManager\Common\Audit\Scanner\ComposerOutdatedScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;

/**
 * Verifies native Composer output using a local repository without network access.
 */
class ComposerOutdatedScannerTest extends AuditTestCase
{
    /**
     * Real commands must expose root and transitive blockers without installing packages.
     * 
     * Requires Composer 2.7 or newer on the host. Packagist is disabled and every
     * available version comes from the temporary project's inline package repository.
     * 
     * @return void
     */
    public function testNativeComposerCommandsReportConstraintOwners() : void
    {
        $folder = $this->temporaryFolder();
        $locked = [
            ['name' => 'example/library', 'version' => '1.0.0', 'type' => 'metapackage'],
            ['name' => 'example/consumer', 'version' => '1.2.0', 'type' => 'metapackage', 'require' => ['example/library' => '^1']]
        ];
        $manifest = json_encode([
            'name' => 'example/project',
            'require' => ['example/library' => '^1', 'example/consumer' => '^1'],
            'repositories' => [
                ['type' => 'package', 'package' => array_merge($locked, [
                    ['name' => 'example/library', 'version' => '2.0.0', 'type' => 'metapackage']
                ])],
                ['packagist.org' => false]
            ]
        ], JSON_THROW_ON_ERROR);
        $lock = json_encode(['packages' => $locked, 'packages-dev' => []], JSON_THROW_ON_ERROR);
        $this->writeFixture($folder, 'composer.json', $manifest);
        $this->writeFixture($folder, 'composer.lock', $lock);
        $task = new GenericTask($this->workbench);
        $task->setParameter('folder', $folder);
        $scanner = new ComposerOutdatedScanner($this->workbench);
        $findings = $scanner->audit($task);
        self::assertSame([], $scanner->getHints());
        self::assertCount(1, $findings);
        self::assertSame(50, $findings[0]->getLevel());
        self::assertSame('outdated', $findings[0]->getType());
        self::assertSame('1.0.0', $findings[0]->getVersionInstalled());
        self::assertSame('2.0.0', $findings[0]->getVersionFixed());
        self::assertStringContainsString('example/project', $findings[0]->getDescription());
        self::assertStringContainsString('example/consumer', $findings[0]->getDescription());
        self::assertStringContainsString('example/library (^1)', $findings[0]->getDescription());
        self::assertSame($manifest, file_get_contents($folder . '/composer.json'));
        self::assertSame($lock, file_get_contents($folder . '/composer.lock'));
        self::assertDirectoryDoesNotExist($folder . '/vendor');
    }
}