<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerOutdatedScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;

/**
 * Ensures Composer's nested Git processes use the scanner's configured runtime budget.
 */
class ComposerProcessTimeoutTest extends AuditTestCase
{
    /**
     * An outer timeout increase must also reach Composer's internal process runner.
     * 
     * @dataProvider scannerTimeouts
     * @param class-string<ComposerAuditScanner> $class
     * @param int|null $timeout
     * @param string $expected
     * @return void
     */
    public function testComposerReceivesItsInternalProcessTimeout(string $class, ?int $timeout, string $expected) : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'composer.phar', <<<'PHP'
<?php
echo getenv('COMPOSER_PROCESS_TIMEOUT');
PHP
        );
        $scanner = new $class($this->workbench);
        if ($scanner instanceof ComposerOutdatedScanner && $timeout !== null) {
            $scanner->setTimeoutSeconds($timeout);
        }
        $result = $this->invokeProtected($scanner, 'runComposer', [['--version'], $folder]);
        self::assertSame(0, $result['exit_code']);
        self::assertSame($expected, $result['stdout']);
    }

    /**
     * Covers advisory compatibility, the outdated default and a designer override.
     * 
     * @return array<string, array{class-string<ComposerAuditScanner>, int|null, string}>
     */
    public static function scannerTimeouts() : array
    {
        return [
            'advisory default' => [ComposerAuditScanner::class, null, '300'],
            'outdated default' => [ComposerOutdatedScanner::class, null, '1800'],
            'outdated override' => [ComposerOutdatedScanner::class, 3600, '3600']
        ];
    }
}