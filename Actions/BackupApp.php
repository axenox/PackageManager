<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\PackageManagerApp;
use exface\Core\Factories\AppFactory;
use axenox\PackageManager\MetaModelInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;

/**
 * This action installs one or more apps including their meta model, custom installer, etc.
 *
 * @method PackageManagerApp getApp()
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
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $exface = $this->getWorkbench();
        $backup_counter = 0;
        $messasge = '';
        
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $message .= "Creating Backup for " . $app_alias . "...\n";
            $app_selector = new AppSelector($exface, $app_alias);
            try {
                $backup_counter ++;
                $message .= $this->backup($app_selector);
            } catch (\Exception $e) {
                $backup_counter --;
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
            $message .= "\n Sucessfully created backup for " . $app_alias . " .\n";
        }
        
        if (count($this->getTargetAppAliases()) == 0) {
            $message .= 'No apps had been selected for backup!';
        } elseif ($backup_counter == 0) {
            $message .= 'No backups have been created';
        }
        
        return ResultFactory::createMessageResult($task, $message);
    }

    /**
     *
     * @param AppSelectorInterface $appSelector            
     * @return string
     */
    public function backup(AppSelectorInterface $appSelector) : string
    {
        $result = '';
        
        $app = AppFactory::create($appSelector);
        
        $installer = $app->getInstaller(new MetaModelInstaller($appSelector));
        $directory = $appSelector->getFolderRelativePath();
        if ($this->getBackupPath() == '') {
            $backupDir = $app->getWorkbench()->filemanager()->getPathToBackupFolder();
            $sDirName = date('Y_m_d_H_m');
            $backupDir .= $directory . DIRECTORY_SEPARATOR . $sDirName;
        } else {
            $backupDir = $app->getWorkbench()->filemanager()->getPathToBackupFolder();
            $backupDir .= DIRECTORY_SEPARATOR . $this->getBackupPath() . $directory;
        }
        $backupDir = $app->getWorkbench()->filemanager()->pathNormalize($backupDir, DIRECTORY_SEPARATOR);
        
        $installer_result = $installer->backup($backupDir);
        $result .= $installer_result . (substr($installer_result, - 1) != '.' ? '.' : '');
        
        // Save the result
        return $result;
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