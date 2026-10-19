<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerOutdatedScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\Exceptions\CliRuntimeException;

/**
 * Keeps native command diagnostics visible when the action forwards exception messages.
 */
class ComposerFailureDiagnosticTest extends AuditTestCase
{
    /**
     * Both Composer scanners must retain stderr in the message without accepting failed commands.
     * 
     * @dataProvider composerScanners
     * @param class-string<ComposerAuditScanner> $class
     * @return void
     */
    public function testFailedCommandExposesDiagnosticAndPreservesCause(string $class) : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'composer.phar', <<<'PHP'
<?php
fwrite(STDERR, "Repository request failed: fixture diagnostic.\n");
exit(1);
PHP
        );
        $scanner = new $class($this->workbench);
        try {
            $this->invokeProtected($scanner, 'runComposer', [['--version'], $folder]);
            self::fail('Failed Composer command was accepted.');
        } catch (CliRuntimeException $error) {
            self::assertSame(1, $error->getExitCode());
            self::assertStringContainsString('Repository request failed: fixture diagnostic.', $error->getMessage());
            self::assertStringContainsString('exit code `1`', $error->getMessage());
            self::assertSame(["Repository request failed: fixture diagnostic.\n"], $error->getCliOutput());
            self::assertInstanceOf(CliRuntimeException::class, $error->getPrevious());
        }
    }

    /**
     * Exercises the real inherited runner rather than reproducing it in a command double.
     * 
     * @return array<string, array{class-string<ComposerAuditScanner>}>
     */
    public static function composerScanners() : array
    {
        return ['advisories' => [ComposerAuditScanner::class], 'outdated' => [ComposerOutdatedScanner::class]];
    }
}