<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use axenox\PackageManager\MetaModelInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\CommonLogic\Tasks\ResultMessageStream;

/**
 * Saves the metamodel for the selected apps as JSON files in the apps folder.
 *
 * @author Andrej Kabachnik
 *        
 */
class ExportAppModel extends AbstractActionDeferred
{

    private $export_to_path_relative = null;

    protected function init()
    {
        $this->setIcon(Icons::HDD_O);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
        $this->setInputObjectAlias('exface.Core.APP');
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
        $result = new ResultMessageStream($task);
        
        
        $generator = function() use ($apps, $workbench, $result, $transaction) {
            $exported_counter = 0;
            foreach ($apps->getRows() as $row) {
                yield 'Exporting app ' . $row['ALIAS'] . '...' . PHP_EOL;
                
                $app_selector = new AppSelector($workbench, $row['ALIAS']);
                $app = $workbench->getApp($row['ALIAS']);
                if (! file_exists($app->getDirectoryAbsolutePath())) {
                    $this->getApp()->createAppFolder($app);
                }
                
                $installer = new MetaModelInstaller($app_selector);
                $backupDir = $this->getModelFolderPathAbsolute($app);
                yield from $installer->backup($backupDir);
                
                $exported_counter ++;
                
                yield '... exported app ' . $row['ALIAS'] . ' into ' . ($this->getExportToPathRelative() ? '"' . $this->getExportToPathRelative() . '"' : ' the respective app folders') . '.' . PHP_EOL;
            }
            
            // Trigger regular action post-processing as required by AbstractActionDeferred.
            $this->performAfterDeferred($result, $transaction);
        };
        
        $result->setMessageStreamGenerator($generator);
        return $result;
    }

    /**
     *
     * @throws ActionInputInvalidObjectError
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function getInputAppsDataSheet(TaskInterface $task)
    {
        $input = $this->getInputDataSheet($task);
        
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