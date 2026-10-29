<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerOutdatedScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Protects configurable repository-scan budgets without spawning slow Composer commands.
 */
class ComposerTimeoutTest extends AuditTestCase
{
    /**
     * A slow repository refresh must not inherit the shorter advisory-query timeout.
     * 
     * @return void
     */
    public function testOutdatedDefaultDoesNotChangeAdvisoryTimeout() : void
    {
        self::assertSame(300.0, $this->invokeProtected(new ComposerAuditScanner($this->workbench), 'getComposerTimeout'));
        self::assertSame(1800.0, $this->invokeProtected(new ComposerOutdatedScanner($this->workbench), 'getComposerTimeout'));
    }

    /**
     * Designer configuration must reach the budget consumed by the shared runner.
     * 
     * @return void
     */
    public function testUxonOverridesOnlyItsScannerInstance() : void
    {
        $scanner = new ComposerOutdatedScanner($this->workbench);
        $scanner->importUxonObject(new UxonObject(['timeout_seconds' => 3600]));
        self::assertSame(3600.0, $this->invokeProtected($scanner, 'getComposerTimeout'));
        self::assertSame(1800.0, $this->invokeProtected(new ComposerOutdatedScanner($this->workbench), 'getComposerTimeout'));
    }

    /**
     * Unbounded or negative settings must fail rather than hang a synchronous audit.
     * 
     * @dataProvider invalidTimeouts
     * @param int $seconds
     * @return void
     */
    public function testInvalidTimeoutIsRejected(int $seconds) : void
    {
        $scanner = new ComposerOutdatedScanner($this->workbench);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be greater than zero');
        $scanner->setTimeoutSeconds($seconds);
    }

    /**
     * Covers both ways a configured runtime limit could become unbounded or invalid.
     * 
     * @return array<string, array{int}>
     */
    public static function invalidTimeouts() : array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }
}