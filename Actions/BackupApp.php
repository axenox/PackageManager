<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Factories\AppFactory;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Events\Installer\OnBeforeAppBackupEvent;
use exface\Core\Events\Installer\OnAppBackupEvent;

/**
 * This action installs one or more apps including their meta model, custom installer, etc.
 *
 * @method \axenox\PackageManager\PackageManagerApp getApp()
 *        
 * @author Andrej Kabachnik
 *        
 */
class BackupApp extends InstallApp
{
    private $backup_path = '';

    protected function init()
    {
        $this->setIcon(Icons::HDD_O);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $target_aliases = []) : \Generator
    {
        $backup_counter = 0;
        foreach ($target_aliases as $app_alias) {
            yield "Creating Backup for " . $app_alias . "..." . PHP_EOL;
            $app_selector = new AppSelector($this->getWorkbench(), $app_alias);
            try {
                $backup_counter ++;
                yield from $this->backup($app_selector);
            } catch (\Exception $e) {
                $backup_counter --;
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
            yield "... Sucessfully created backup for " . $app_alias . " ." . PHP_EOL;
        }
        
        if (count($target_aliases) == 0) {
            yield 'No apps had been selected for backup!';
        } elseif ($backup_counter == 0) {
            yield 'No backups have been created';
        }
    }

    /**
     *
     * @param AppSelectorInterface $appSelector            
     * @return string
     */
    public function backup(AppSelectorInterface $appSelector) : \Iterator
    {
        $app = AppFactory::create($appSelector);
        
        $installer = $app->getInstaller();
        $directory = $appSelector->getFolderRelativePath();
        if ($this->getBackupPath() == '') {
            $backupDir = $app->getWorkbench()->filemanager()->getPathToBackupFolder();
            $sDirName = date('Y-m-d Hi');
            $backupDir .= DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $sDirName;
        } else {
            if ($app->getWorkbench()->filemanager()->pathIsAbsolute($this->getBackupPath())) {
                $backupDir = $this->getBackupPath();
            } else {
                $backupDir = $app->getWorkbench()->filemanager()->getPathToBackupFolder();
                $backupDir .= DIRECTORY_SEPARATOR . $this->getBackupPath() . $directory;
            }
        }
        $backupDir = $app->getWorkbench()->filemanager()->pathNormalize($backupDir, DIRECTORY_SEPARATOR);
        
        $event = new OnBeforeAppBackupEvent($app->getSelector(), $backupDir);
        $this->getWorkbench()->eventManager()->dispatch($event);
        foreach ($event->getPreprocessors() as $proc) {
            yield from $proc;
        }
        
        yield from $installer->backup($backupDir);
        
        $event = new OnAppBackupEvent($app->getSelector(), $backupDir);
        $this->getWorkbench()->eventManager()->dispatch($event);
        foreach ($event->getPostprocessors() as $proc) {
            yield from $proc;
        }
    }

    /**
     * Set path to backup to different location
     *
     * @uxon-property backup_path
     * @uxon-type string
     * 
     * @param $value
     * @return BackupApp
     */
    public function setBackupPath($value)
    {
        $this->backup_path = $value;
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    public function getBackupPath()
    {
        return $this->backup_path;
    }
}
?>