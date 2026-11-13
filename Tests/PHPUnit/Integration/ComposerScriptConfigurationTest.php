<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Verifies real CLI output and file persistence without loading a workbench or the installation's composer.json.
 */
class ComposerScriptConfigurationTest extends TestCase
{
    /**
     * Missing registrations are saved without legacy callbacks; a repeated check emits nothing.
     *
     * @return void
     */
    public function testMissingScriptsAreReportedAndSavedOnce() : void
    {
        $path = tempnam(sys_get_temp_dir(), 'pm-composer-');
        try {
            file_put_contents($path, '{"require":{"acme/app":"^1"}}');
            [$code, $output] = $this->runMigration($path);
            self::assertSame(0, $code, $output);
            self::assertSame(6, substr_count($output, 'CHANGE:'));
            self::assertStringContainsString('Saved Composer script changes', $output);
            self::assertStringNotContainsString('ERROR', $output);
            $saved = file_get_contents($path);
            $json = json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['acme/app' => '^1'], $json['require']);
            self::assertCount(4, $json['scripts']);

            [$code, $output] = $this->runMigration($path);
            self::assertSame(0, $code, $output);
            self::assertSame('', $output);
            self::assertSame($saved, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    /**
     * Invalid JSON, invalid script entries and missing files produce errors without Core classes being available.
     *
     * @return void
     */
    public function testUnexpectedInputsProduceErrorsAndRemainUntouched() : void
    {
        $path = tempnam(sys_get_temp_dir(), 'pm-composer-');
        try {
            foreach (['{', '[]', '{"scripts":{"post-update-cmd":[123]}}', '{"scripts":false}'] as $contents) {
                file_put_contents($path, $contents);
                [$code, $output] = $this->runMigration($path);
                self::assertSame(0, $code, $output);
                self::assertStringContainsString('ERROR', $output);
                self::assertStringNotContainsString('Saved Composer script changes', $output);
                self::assertStringNotContainsString('Fatal error', $output);
                self::assertSame($contents, file_get_contents($path));
            }
            [$code, $output] = $this->runMigration($path . '.missing');
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('ERROR: composer.json not found', $output);
        } finally {
            unlink($path);
        }
    }

    /**
     * Failed writes still report all detected changes before the error and never claim to have saved them.
     *
     * @return void
     */
    public function testWriteFailureDoesNotHideDetectedChanges() : void
    {
        [$code, $output] = $this->runMigration('pmreadonly://composer.json', <<<'PHP'
class ReadOnlyComposerStream
{
    public $context;
    private $position = 0;
    public function url_stat($path, $flags) { return ['mode' => 0100444]; }
    public function stream_open($path, $mode, $options, &$openedPath) { return $mode === 'rb'; }
    public function stream_read($count) { $data = substr('{}', $this->position, $count); $this->position += strlen($data); return $data; }
    public function stream_eof() { return $this->position >= 2; }
    public function stream_stat() { return []; }
}
stream_wrapper_register('pmreadonly', ReadOnlyComposerStream::class);
PHP
        );
        self::assertSame(0, $code, $output);
        self::assertSame(6, substr_count($output, 'CHANGE:'));
        self::assertStringContainsString('ERROR: cannot save Composer script changes', $output);
        self::assertStringNotContainsString('Saved Composer script changes', $output);
        self::assertLessThan(strpos($output, 'ERROR: cannot save'), strpos($output, 'CHANGE:'));
    }

    /**
     * Runs only the static configuration path in a clean process and captures stdout and stderr.
     *
     * @param string $path
     * @param string $setup optional local stream fixture
     * @return array{int, string}
     */
    private function runMigration(string $path, string $setup = '') : array
    {
        $installer = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'StaticInstaller.php';
        $script = $setup . ' require $argv[1]; \axenox\PackageManager\StaticInstaller::migrateRootComposerJson($argv[2]);';
        $process = proc_open([PHP_BINARY, '-r', $script, $installer, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
