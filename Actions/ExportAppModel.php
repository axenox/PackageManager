<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\AppInstallers\DataInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\AppInstallerInterface;
use exface\Core\Events\Installer\OnBeforeAppBackupEvent;
use exface\Core\Events\Installer\OnAppBackupEvent;

/**
 * Saves the metamodel for the selected apps as JSON files in the corresponding vendor folders.
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
            
            $app = $this->getWorkbench()->getApp($row['ALIAS']);
            if (! file_exists($app->getDirectoryAbsolutePath())) {
                $this->getApp()->createAppFolder($app);
            }
            $backupDir = $this->getModelFolderPathAbsolute($app);

            // Make sure to fully instantiate the installers of an app here before fetching
            // the model installer - in case other installers will modify it or listen to its
            // events (like the DataInstaller)
            $modelInstaller = $app->getInstaller()->extract(function(AppInstallerInterface $inst){
                return ($inst instanceof DataInstaller);
            });
            
            $event = new OnBeforeAppBackupEvent($app->getSelector(), $backupDir);
            $this->getWorkbench()->eventManager()->dispatch($event);
            foreach ($event->getPreprocessors() as $proc) {
                yield from $proc;
            }
            
            yield from $modelInstaller->backup($backupDir);
            
            $event = new OnAppBackupEvent($app->getSelector(), $backupDir);
            $this->getWorkbench()->eventManager()->dispatch($event);
            foreach ($event->getPostprocessors() as $proc) {
                yield from $proc;
            }
            
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