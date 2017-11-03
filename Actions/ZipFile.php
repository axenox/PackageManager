<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\NameResolver;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\ArchiveManager;
use exface\Core\Factories\AppFactory;
use exface\Core\CommonLogic\Constants\Icons;

/**
 * This Action adds all files of a designated folder into a ZIP Archive
 */
class ZipFile extends AbstractAction
{

    private $file_path = '';

    private $file_name = 'download';

    protected function init()
    {
        $this->setIcon(Icons::FILE_ARCHIVE_O);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }

    protected function perform()
    {
        $exface = $this->getWorkbench();
        $result = '';
        $zipManager = new ArchiveManager($exface);
        
        $filename = DIRECTORY_SEPARATOR . $this->file_name . ".zip";
        foreach ($this->getTargetAppAliases() as $app_alias) {
            
            $app_name_resolver = NameResolver::createFromString($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
            $app = AppFactory::create($app_name_resolver);
            
            if ($this->getFilePath() == '') {
                $backupDir = $app->getWorkbench()->filemanager()->getPathToBackupFolder();
            } else {
                $backupDir = $app->getWorkbench()->filemanager()->getPathToBaseFolder();
                $backupDir .= DIRECTORY_SEPARATOR . $this->getFilePath();
            }
        }
        $zipManager->setFilePath($backupDir . $filename);
        if ($zipManager->addFolderFromSource($backupDir)) {
            $this->addResultMessage("\n\nSuccessfully added the folder " . $this->file_path . " to archive!" . $result);
        } else {
            $this->addResultMessage("\n\nCould not add folder " . $this->file_path . " to archive!" . $result);
        }
        $zipManager->archiveClose();
        // Save the result and output a message for the user
        $this->setResult('');
        
        return;
    }

    /**
     * Get all affected apps
     *
     * @return array
     * @throws ActionInputInvalidObjectError
     */
    public function getTargetAppAliases()
    {
        if ( count($this->target_app_aliases) < 1
            && $input_data = $this->getInputDataSheet()){
                
                if ($input_data->getMetaObject()->isExactly('exface.Core.APP')){
                    $input_data->getColumns()->addFromExpression('ALIAS');
                    if (!$input_data->isEmpty()){
                        if (!$input_data->isFresh()){
                            $input_data->dataRead();
                        }
                    } elseif (!$input_data->getFilters()->isEmpty()){
                        $input_data->dataRead();
                    }
                    $this->target_app_aliases = array_unique($input_data->getColumnValues('ALIAS', false));
                } elseif ($input_data->getMetaObject()->isExactly('axenox.PackageManager.PACKAGE_INSTALLED')){
                    $input_data->getColumns()->addFromExpression('app_alias');
                    if (!$input_data->isEmpty()){
                        if (!$input_data->isFresh()){
                            $input_data->dataRead();
                        }
                    } elseif (!$input_data->getFilters()->isEmpty()){
                        $input_data->dataRead();
                    }
                    $this->target_app_aliases = array_filter(array_unique($input_data->getColumnValues('app_alias', false)));
                } else {
                    throw new ActionInputInvalidObjectError($this, 'The action "' . $this->getAliasWithNamespace() . '" can only be called on the meta objects "exface.Core.App" or "axenox.PackageManager.PACKAGE_INSTALLED" - "' . $input_data->getMetaObject()->getAliasWithNamespace() . '" given instead!');
                }
        }
        
        return $this->target_app_aliases;
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

    public function getFileName()
    {
        return $this->file_name;
    }
}
?>