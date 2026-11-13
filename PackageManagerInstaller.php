<?php
namespace axenox\PackageManager;

use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\Exceptions\UnexpectedValueException;

class PackageManagerInstaller extends AbstractAppInstaller
{
    /** Composer's helper to disable its `process-timeout` for the following scripts of an event */
    const COMPOSER_DISABLE_TIMEOUT = StaticInstaller::COMPOSER_DISABLE_TIMEOUT;

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
            yield $idt . 'CHANGE: adding "autoload.psr-0.axenox\\PackageManager" to root composer.json' . PHP_EOL;
            $changes ++;
        }
        
        $notices = [];
        $configured = StaticInstaller::configureComposerScripts($root_composer_json, function (string $message) use (&$notices) : void {
            $notices[] = $message;
        });
        foreach ($notices as $notice) {
            yield $idt . $notice;
        }
        if ($configured !== $root_composer_json) {
            $root_composer_json = $configured;
            $changes ++;
        }
        
        if ($changes > 0) {
            $this->getWorkbench()->filemanager()->dumpFile($root_composer_json_path, json_encode($root_composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            yield $idt . "Configured root composer.json for automatic app installation" . PHP_EOL;
        }
    }

    public function update($source_absolute_path)
    {
        return $this->install();
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
