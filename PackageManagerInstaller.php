<?php
namespace axenox\PackageManager;

use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\Exceptions\UnexpectedValueException;

class PackageManagerInstaller extends AbstractAppInstaller
{
    /** Composer's helper to disable its `process-timeout` for the following scripts of an event */
    const COMPOSER_DISABLE_TIMEOUT = 'Composer\\Config::disableProcessTimeout';

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::install()
     */
    public function install(string $source_absolute_path) : \Iterator
    {
        $idt = $this->getOutputIndentation();
        
        $root_composer_json_path = $this->getWorkbench()->filemanager()->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.json';
        if (! file_exists($root_composer_json_path)) {
            yield $idt . 'Root composer.json not found under "' . $root_composer_json_path . '" - automatic installation of apps will not work! See the package manager docs for solutions.' . PHP_EOL;
            return;
        }
        
        try {
            $root_composer_json = $this->parseComposerJson($root_composer_json_path);
        } catch (\Throwable $e) {
            yield  $idt . 'ERROR: ' . $e->getMessage() . ' - automatic installation of apps will not work! See the package manager docs for solutions.';
            // Do not continue - writing the file would overwrite the existing (unparsable) content
            return;
        }
        
        $changes = 0;
        
        if (! isset($root_composer_json['autoload']['psr-0']["axenox\\PackageManager"])) {
            $root_composer_json['autoload']['psr-0']["axenox\\PackageManager"] = "vendor/";
            $changes ++;
        }
        
        $configured = static::configureComposerScripts($root_composer_json);
        if ($configured !== $root_composer_json) {
            $root_composer_json = $configured;
            $changes ++;
        }
        
        if ($changes > 0) {
            $this->getWorkbench()->filemanager()->dumpFile($root_composer_json_path, json_encode($root_composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            yield $idt . "Configured root composer.json for automatic app installation" . PHP_EOL;
        } else {
            yield $idt . "Checked root composer.json" . PHP_EOL;
        }
    }

    public function update($source_absolute_path)
    {
        return $this->install();
    }
    
    /**
     * Registers the scripts of the `StaticInstaller` in the given root `composer.json` and returns the result.
     * 
     * - Package events use callbacks, because they need the Composer event and never start a workbench.
     * - `post-install-cmd` and `post-update-cmd` use `@php` scripts, so the workbench runs in its own PHP process
     * and not inside Composer (see `StaticInstaller` class description). Legacy callbacks are replaced by the
     * `@php` script at the same position, so the order of other scripts is kept. `Composer\Config::disableProcessTimeout`
     * is added in front of it unless already present - otherwise Composer would stop the installers after 300 seconds.
     * 
     * Running this repeatedly does not change the result.
     * 
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    protected static function configureComposerScripts(array $json) : array
    {
        $callbacks = [
            'post-package-install' => StaticInstaller::class . '::composerFinishPackageInstall',
            'post-package-update' => StaticInstaller::class . '::composerFinishPackageUpdate'
        ];
        foreach ($callbacks as $event => $callback) {
            $scripts = static::getComposerScripts($json, $event);
            if (! in_array($callback, $scripts, true)) {
                $scripts[] = $callback;
                $json['scripts'][$event] = $scripts;
            }
        }
        
        $vendorDir = $json['config']['vendor-dir'] ?? 'vendor';
        $commands = [
            'post-install-cmd' => 'composerFinishInstall',
            'post-update-cmd' => 'composerFinishUpdate'
        ];
        foreach ($commands as $event => $method) {
            $scripts = static::getComposerScripts($json, $event);
            $command = StaticInstaller::getComposerScriptCommand($method, $vendorDir);
            $replaced = [StaticInstaller::class . '::' . $method, $command];
            
            $position = null;
            $kept = [];
            foreach ($scripts as $script) {
                if (in_array($script, $replaced, true)) {
                    $position = $position ?? count($kept);
                    continue;
                }
                $kept[] = $script;
            }
            $position = $position ?? count($kept);
            
            $insert = [$command];
            if (! in_array(self::COMPOSER_DISABLE_TIMEOUT, array_slice($kept, 0, $position), true)) {
                array_unshift($insert, self::COMPOSER_DISABLE_TIMEOUT);
            }
            array_splice($kept, $position, 0, $insert);
            
            if ($kept !== $scripts) {
                $json['scripts'][$event] = $kept;
            }
        }
        return $json;
    }
    
    /**
     * Returns the scripts registered for a Composer event as array - Composer also allows a single string.
     * 
     * @param array<string, mixed> $json
     * @param string $event
     * @return string[]
     */
    protected static function getComposerScripts(array $json, string $event) : array
    {
        $scripts = $json['scripts'][$event] ?? [];
        return is_array($scripts) ? array_values($scripts) : [$scripts];
    }

    public function uninstall() : \Iterator
    {
        return 'Uninstall not implemented for' . $this->getSelectorInstalling()->getAliasWithNamespace() . '!';
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::backup()
     */
    public function backup(string $destination_absolute_path) : \Iterator
    {
        return new \EmptyIterator();
    }

    protected function parseComposerJson($root_composer_json_path)
    {
        $root_composer_json = json_decode(file_get_contents($root_composer_json_path), true);
        if (! is_array($root_composer_json)) {
            switch (json_last_error()) {
                case JSON_ERROR_NONE:
                    $error = 'No errors';
                    break;
                case JSON_ERROR_DEPTH:
                    $error = 'Maximum stack depth exceeded';
                    break;
                case JSON_ERROR_STATE_MISMATCH:
                    $error = 'Underflow or the modes mismatch';
                    break;
                case JSON_ERROR_CTRL_CHAR:
                    $error = 'Unexpected control character found';
                    break;
                case JSON_ERROR_SYNTAX:
                    $error = 'Syntax error, malformed JSON';
                    break;
                case JSON_ERROR_UTF8:
                    $error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
                    break;
                default:
                    $error = 'Unknown error';
                    break;
            }
            throw new UnexpectedValueException('Cannot parse root composer.json under "' . $root_composer_json_path . '": ' . $error);
        }
        return $root_composer_json;
    }
}
?>