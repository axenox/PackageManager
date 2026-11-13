<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies compact fallback exception output in clean PHP processes without Workbench or CLI printer dependencies.
 */
class StaticInstallerExceptionOutputTest extends TestCase
{
    /**
     * A chain keeps every summary and vendor-relative location, but expands only the original cause's trace.
     *
     * @return void
     */
    public function testFallbackExpandsOnlyRootCauseAndShortensVendorPaths() : void
    {
        [$code, $output] = $this->runFallback(<<<'PHP'
class RootFailure extends \RuntimeException
{
    public function __construct($vendor)
    {
        parent::__construct('Original failure');
        $this->file = $vendor . 'example' . DIRECTORY_SEPARATOR . 'Root.php';
        $this->line = 21;
    }
}
function rootFailure($vendor) { return new RootFailure($vendor); }
$vendor = dirname($argv[1], 3) . DIRECTORY_SEPARATOR;
$root = rootFailure($vendor);
$outer = new \RuntimeException('Outer failure', 0, new \RuntimeException('Middle failure', 0, $root));
FallbackStaticInstaller::printFailure($outer);
PHP
        );
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('[3 of 3] RuntimeException:', $output);
        self::assertStringContainsString('Outer failure', $output);
        self::assertStringContainsString('[2 of 3] RuntimeException:', $output);
        self::assertStringContainsString('Middle failure', $output);
        self::assertStringContainsString('[1 of 3] RootFailure:', $output);
        self::assertStringContainsString(' in example' . DIRECTORY_SEPARATOR . 'Root.php:21', $output);
        self::assertSame(1, substr_count($output, '#0 '), $output);
        self::assertStringContainsString('rootFailure(', $output);
        self::assertStringNotContainsString(dirname(__DIR__, 5) . DIRECTORY_SEPARATOR, $output);
    }

    /**
     * Vendor stack-frame paths are shortened while a real external exception location remains absolute.
     *
     * @return void
     */
    public function testFallbackShortensRealVendorTraceAndPreservesExternalLocation() : void
    {
        [$code, $output] = $this->runFallback(<<<'PHP'
$path = tempnam(sys_get_temp_dir(), 'pm-external-exception-');
try {
    file_put_contents($path, '<?php throw new \RuntimeException("External failure");');
    ThrowingComposerStream::$path = $path;
    FallbackStaticInstaller::migrateRootComposerJson('pmexception://composer.json');
    echo 'EXPECTED-FILE:' . $path . PHP_EOL;
} finally {
    unlink($path);
}
PHP
        , <<<'PHP'
class ThrowingComposerStream
{
    public $context;
    public static $path;
    public function url_stat($path, $flags) { return ['mode' => 0100444]; }
    public function stream_open($path, $mode, $options, &$openedPath) { require self::$path; }
}
stream_wrapper_register('pmexception', ThrowingComposerStream::class);
PHP
        );
        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/EXPECTED-FILE:([^\r\n]+)/', $output);
        preg_match('/EXPECTED-FILE:([^\r\n]+)/', $output, $matches);
        self::assertStringContainsString(' in ' . $matches[1] . ':1', $output);
        self::assertStringContainsString('axenox' . DIRECTORY_SEPARATOR . 'packagemanager' . DIRECTORY_SEPARATOR . 'StaticInstaller.php', $output);
        self::assertStringNotContainsString(dirname(__DIR__, 5) . DIRECTORY_SEPARATOR, $output);
    }

    /**
     * A real Core exception keeps its Log-ID visible even after all autoloaders have been removed.
     *
     * @return void
     */
    public function testFallbackKeepsLogIdVisibleWithoutAutoloading() : void
    {
        [$code, $output] = $this->runFallback(<<<'PHP'
require dirname($argv[1], 3) . DIRECTORY_SEPARATOR . 'autoload.php';
$exception = new \exface\Core\Exceptions\RuntimeException('Logged failure');
$id = $exception->getId();
foreach (spl_autoload_functions() as $autoload) {
    spl_autoload_unregister($autoload);
}
if (class_exists(\exface\Core\Facades\ConsoleFacade\CliOutputPrinter::class, false)) {
    throw new \RuntimeException('Fixture unexpectedly loaded CLI printer');
}
FallbackStaticInstaller::printFailure($exception);
echo 'EXPECTED-ID:' . $id . PHP_EOL;
PHP
        );
        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/EXPECTED-ID:([A-Z0-9]+)/', $output);
        preg_match('/EXPECTED-ID:([A-Z0-9]+)/', $output, $matches);
        self::assertStringContainsString('Logged failure (see Log-ID ' . $matches[1] . ')', $output);
        self::assertSame(1, substr_count($output, '#0 '), $output);
    }

    /**
     * Calls the fallback without an autoloader unless the scenario explicitly loads one.
     *
     * @param string $scenario
     * @param string $setup optional local stream fixture
     * @return array{int, string}
     */
    private function runFallback(string $scenario, string $setup = '') : array
    {
        $installer = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'StaticInstaller.php';
        $script = <<<'PHP'
require $argv[1];
class FallbackStaticInstaller extends \axenox\PackageManager\StaticInstaller
{
    public static function printFailure(\Throwable $exception) : void
    {
        parent::printException($exception);
    }
}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script . PHP_EOL . $setup . PHP_EOL . $scenario, $installer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
