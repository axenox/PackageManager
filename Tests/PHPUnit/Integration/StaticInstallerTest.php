<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use axenox\PackageManager\StaticInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `@php` Composer script of the StaticInstaller: the command registered in `composer.json` and the script
 * it points to.
 * 
 * This is an integration test, because it starts real (tiny) PHP processes. No workbench or database is used.
 */
class StaticInstallerTest extends TestCase
{
    /**
     * The `@php` command points to the real script relative to the installation folder, using forward slashes.
     * 
     * @return void
     */
    public function testComposerScriptCommandPointsToScript() : void
    {
        $command = StaticInstaller::getComposerScriptCommand('composerFinishUpdate');
        
        self::assertSame('@php vendor/axenox/packagemanager/bin/composer-script.php composerFinishUpdate', $command);
        self::assertSame(
            '@php lib/vendor/axenox/packagemanager/bin/composer-script.php composerFinishInstall', 
            StaticInstaller::getComposerScriptCommand('composerFinishInstall', 'lib\\vendor\\')
        );
        self::assertFileExists($this->getScriptPath());
    }
    
    /**
     * The script only runs whitelisted methods - anything else fails before a workbench is started.
     * 
     * @return void
     */
    public function testComposerScriptRejectsUnknownMethods() : void
    {
        foreach ([['getCoreAppAlias'], ['uninstall'], []] as $arguments) {
            [$exitCode, $output] = $this->runComposerScript($arguments);
            self::assertSame(1, $exitCode, $output);
            self::assertStringContainsString('Unknown StaticInstaller method', $output);
        }
    }
    
    /**
     * A file with legacy callbacks is migrated once - other content is kept, a migrated file stays untouched.
     * 
     * @return void
     */
    public function testRootComposerJsonIsMigrated() : void
    {
        $path = tempnam(sys_get_temp_dir(), 'composer-json-');
        try {
            file_put_contents($path, json_encode([
                'require' => ['acme/app' => '^1'],
                'scripts' => ['post-update-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishUpdate']]
            ]));
            ob_start();
            $changed = StaticInstaller::migrateRootComposerJson($path);
            ob_end_clean();
            
            self::assertTrue($changed);
            $json = json_decode(file_get_contents($path), true);
            self::assertSame(['acme/app' => '^1'], $json['require']);
            self::assertSame(
                ['Composer\\Config::disableProcessTimeout', StaticInstaller::getComposerScriptCommand('composerFinishUpdate')], 
                $json['scripts']['post-update-cmd']
            );
            self::assertFalse(StaticInstaller::migrateRootComposerJson($path));
            self::assertFalse(StaticInstaller::migrateRootComposerJson($path . '.missing'));
        } finally {
            @unlink($path);
        }
    }
    
    /**
     * A legacy callback inside Composer migrates the composer.json and runs the same method in a new PHP process.
     * 
     * Simulates the Composer process: no autoloader at all, only a stub for the Composer event and the 
     * StaticInstaller itself. Thus, any workbench class used on the way would fail - and none may be loaded, because
     * it might clash with libraries bundled with Composer.
     * 
     * @return void
     */
    public function testLegacyCallbackRunsMethodInNewProcessWithoutWorkbench() : void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pm-legacy-callback-' . uniqid();
        mkdir($dir);
        try {
            $composerJson = $dir . DIRECTORY_SEPARATOR . 'composer.json';
            file_put_contents($composerJson, json_encode(['scripts' => [
                'post-install-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishInstall'],
                'post-update-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishUpdate']
            ]]));
            
            [$exitCode, $output] = $this->runLegacyCallback($dir, 'composerFinishUpdate', 0);
            
            self::assertSame(0, $exitCode, $output);
            self::assertStringContainsString('ARGS:["composerFinishUpdate"]', $output);
            self::assertStringContainsString('CWD-HAS-COMPOSER-JSON:yes', $output);
            self::assertStringContainsString('RESULT:OK', $output);
            self::assertStringContainsString('CLASSES:Composer\\Script\\Event,LegacyCallbackStaticInstaller,axenox\\PackageManager\\StaticInstaller' . PHP_EOL, $output);
            $json = json_decode(file_get_contents($composerJson), true);
            self::assertSame(
                ['Composer\\Config::disableProcessTimeout', StaticInstaller::getComposerScriptCommand('composerFinishUpdate')], 
                $json['scripts']['post-update-cmd']
            );
            self::assertSame(
                ['Composer\\Config::disableProcessTimeout', StaticInstaller::getComposerScriptCommand('composerFinishInstall')], 
                $json['scripts']['post-install-cmd']
            );
            
            // A failing process makes the callback fail too - with a hint how to rerun it manually
            [, $output] = $this->runLegacyCallback($dir, 'composerFinishInstall', 3);
            self::assertStringContainsString('ARGS:["composerFinishInstall"]', $output);
            self::assertStringContainsString('RESULT:StaticInstaller::composerFinishInstall failed with exit code 3', $output);
            self::assertStringContainsString('php vendor/axenox/packagemanager/bin/composer-script.php composerFinishInstall', $output);
        } finally {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
    
    /**
     * @return string
     */
    private function getScriptPath() : string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'composer-script.php';
    }
    
    /**
     * Calls a legacy callback of the StaticInstaller in a PHP process, that mimics Composer, and captures its output.
     * 
     * The new process started by the callback runs a fake script instead of `bin/composer-script.php`, which prints
     * its arguments and working directory and exits with the given code.
     * 
     * @param string $dir folder with the root composer.json
     * @param string $method
     * @param int $scriptExitCode
     * @return array{int, string} exit code and output
     */
    private function runLegacyCallback(string $dir, string $method, int $scriptExitCode) : array
    {
        $fakeScript = $dir . DIRECTORY_SEPARATOR . 'fake-composer-script.php';
        file_put_contents($fakeScript, '<?php echo "ARGS:" . json_encode(array_slice($argv, 1)) . PHP_EOL . "CWD-HAS-COMPOSER-JSON:" . (is_file("composer.json") ? "yes" : "no") . PHP_EOL; exit(' . $scriptExitCode . ');');
        $composerProcess = $dir . DIRECTORY_SEPARATOR . 'composer-process.php';
        file_put_contents($composerProcess, <<<'PHP'
<?php
namespace Composer\Script { class Event {} }
namespace {
    require $argv[1];
    class LegacyCallbackStaticInstaller extends \axenox\PackageManager\StaticInstaller
    {
        protected static function getRootComposerJsonPath() : string { return getenv('PM_TEST_COMPOSER_JSON'); }
        protected static function getComposerScriptPathAbsolute() : string { return getenv('PM_TEST_SCRIPT'); }
    }
    try {
        LegacyCallbackStaticInstaller::{$argv[2]}(new \Composer\Script\Event());
        echo 'RESULT:OK' . PHP_EOL;
    } catch (\RuntimeException $e) {
        echo 'RESULT:' . $e->getMessage() . PHP_EOL;
    }
    $classes = array_filter(get_declared_classes(), function($class) { return (new \ReflectionClass($class))->isUserDefined(); });
    sort($classes);
    echo 'CLASSES:' . implode(',', $classes) . PHP_EOL;
}
PHP
        );
        $env = getenv() + [
            'PM_TEST_COMPOSER_JSON' => $dir . DIRECTORY_SEPARATOR . 'composer.json',
            'PM_TEST_SCRIPT' => $fakeScript
        ];
        $staticInstaller = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'StaticInstaller.php';
        $process = proc_open([PHP_BINARY, $composerProcess, $staticInstaller, $method], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
    
    /**
     * Runs `bin/composer-script.php` and captures its output, so it does not clutter the test output.
     * 
     * @param string[] $arguments
     * @return array{int, string} exit code and output
     */
    private function runComposerScript(array $arguments) : array
    {
        $process = proc_open(array_merge([PHP_BINARY, $this->getScriptPath()], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }
}
