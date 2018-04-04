<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Exceptions\AppNotFoundError;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use axenox\PackageManager\MetaModelInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\CommonLogic\Filemanager;

/**
 * This Action saves alle elements of the meta model assotiated with an app as JSON files in the Model subfolder of the current
 * installations folder of this app.
 *
 * @author Andrej Kabachnik
 *        
 */
class ExportAppModel extends AbstractAction
{

    private $export_to_path_relative = null;

    protected function init()
    {
        $this->setIcon(Icons::DOWNLOAD);
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
        $apps = $this->getInputAppsDataSheet($task);
        
        $workbench = $this->getWorkbench();
        $exported_counter = 0;
        foreach ($apps->getRows() as $row) {
            $app_selector = new AppSelector($workbench, $row['ALIAS']);
            $app = $workbench->getApp($row['ALIAS']);
            if (! file_exists($app->getDirectoryAbsolutePath())) {
                $this->getApp()->createAppFolder($app);
            }
            
            $installer = new MetaModelInstaller($app_selector);
            $backupDir = $this->getModelFolderPathAbsolute($app);
            $installer->backup($backupDir);
            
            $exported_counter ++;
        }
        
        // Save the result and output a message for the user
        $message = 'Exported model files and pages for ' . $exported_counter . ' apps to app-folders into ' . ($this->getExportToPathRelative() ? '"' . $this->getExportToPathRelative() . '"' : ' the respective app folders') . '.';
        
        return ResultFactory::createMessageResult($task, $message);
    }

    /**
     *
     * @throws ActionInputInvalidObjectError
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function getInputAppsDataSheet(TaskInterface $task)
    {
        $input = $this->getInputDataSheet($task);
        if (! $input->isEmpty() && ! $input->getMetaObject()->isExactly('exface.Core.APP')) {
            throw new ActionInputInvalidObjectError($this, 'Action "' . $this->getAlias() . '" exprects an exface.Core.APP as input, "' . $input->getMetaObject()->getAliasWithNamespace() . '" given instead!', '6T5TUR1');
        }
        
        $apps = $input;
        $apps->getColumns()->addFromExpression('ALIAS');
        if (! $apps->isFresh()) {
            if (! $apps->isEmpty()) {
                $apps->addFilterFromColumnValues($apps->getUidColumn());
            }
            $apps->dataRead();
        }
        return $apps;
    }

    protected function getModelFolderPathAbsolute(AppInterface $app)
    {
        return $this->getApp()->getPathToAppAbsolute($app, $this->getExportToPathRelative());
    }

    public function getExportToPathRelative()
    {
        return $this->export_to_path_relative;
    }

    public function setExportToPathRelative($value)
    {
        $this->export_to_path_relative = $value;
        return $this;
    }

    /**
     *
     * @return PackageManagerApp
     * @see \exface\Core\Interfaces\Actions\ActionInterface::getApp()
     */
    public function getApp()
    {
        return parent::getApp();
    }
}
?>