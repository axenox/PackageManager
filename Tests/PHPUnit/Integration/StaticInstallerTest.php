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
     * @return string
     */
    private function getScriptPath() : string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'composer-script.php';
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
