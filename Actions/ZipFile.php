<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\ArchiveManager;
use exface\Core\Factories\AppFactory;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Tasks\TaskResultInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\CommonLogic\Tasks\TaskResultMessage;

/**
 * This Action adds all files of a designated folder into a ZIP Archive
 */
class ZipFile extends InstallApp
{

    private $file_path = '';

    private $file_name = 'download';

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::init()
     */
    protected function init()
    {
        $this->setIcon(Icons::FILE_ARCHIVE_O);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : TaskResultInterface
    {
        $exface = $this->getWorkbench();
        $message = '';
        $zipManager = new ArchiveManager($exface);
        
        $filename = DIRECTORY_SEPARATOR . $this->file_name . ".zip";
        foreach ($this->getTargetAppAliases() as $app_alias) {
            
            $app_selector = new AppSelector($exface, $app_alias);
            $app = AppFactory::create($app_selector);
            
            if ($this->getFilePath() == '') {
                $backupDir = $app->getWorkbench()->filemanager()->getPathToBackupFolder();
            } else {
                $backupDir = $app->getWorkbench()->filemanager()->getPathToBaseFolder();
                $backupDir .= DIRECTORY_SEPARATOR . $this->getFilePath();
            }
        }
        $zipManager->setFilePath($backupDir . $filename);
        if ($zipManager->addFolderFromSource($backupDir)) {
            $message .= "\n\nSuccessfully added the folder " . $this->file_path . " to archive!";
        } else {
            $message .= "\n\nCould not add folder " . $this->file_path . " to archive!";
        }
        $zipManager->archiveClose();
        
        return new TaskResultMessage($task, $message);
    }

    /**
     * Set path to backup to different location
     *
     * @uxon-property backup_path
     * @uxon-type string
     */
    public function setFilePath($value)
    {
        $this->file_path = str_replace("/", DIRECTORY_SEPARATOR, str_replace("\\", DIRECTORY_SEPARATOR, $value));
    }

    /**
     * 
     * @return string
     */
    public function getFilePath()
    {
        return $this->file_path;
    }

    /**
     * Set path to backup to different location
     *
     * @uxon-property file_name
     * @uxon-type string
     */
    public function setFileName($value)
    {
        $this->file_name = $value;
    }

    /**
     * 
     * @return string
     */
    public function getFileName()
    {
        return $this->file_name;
    }
}
?>