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
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

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
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [$this->getInputAppsDataSheet($task)];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(DataSheetInterface $apps = null) : \Generator
    {
        if ($apps === null || $apps->isEmpty()) {
            yield 'No apps to export' . PHP_EOL;
            return;
        }
        
        $exported_counter = 0;
        foreach ($apps->getRows() as $row) {
            yield 'Exporting app ' . $row['ALIAS'] . '...' . PHP_EOL;
            
            $app_selector = new AppSelector($this->getWorkbench(), $row['ALIAS']);
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
                $apps->getFilters()->addConditionFromColumnValues($apps->getUidColumn());
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