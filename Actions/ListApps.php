<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Factories\AppFactory;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\CommonLogic\Tasks\ResultMessageStream;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This action uninstalls one or more apps
 *
 * @author Andrej Kabachnik
 *        
 */
class ListApps extends AbstractActionDeferred implements iCanBeCalledFromCLI
{

    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::LIST_);
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $result = new ResultMessageStream($task);
        
        $generator = function() use ($result, $transaction) {
            $aliasesInPackages = static::findAppAliasesInVendorFolders($this->getWorkbench()->filemanager()->getPathToVendorFolder());
            $aliasesInModel = static::findAppAliasesInModel($this->getWorkbench());
            
            $aliasesInstalledAndPackaged = array_intersect($aliasesInModel, $aliasesInPackages);
            if (empty($aliasesInstalledAndPackaged) === false) {
                yield 'Installed and packaged:' . PHP_EOL;
                foreach ($aliasesInstalledAndPackaged as $alias) {
                    yield '  ' . $alias . PHP_EOL;
                }
            }
            
            $aliasesNotPackaged = array_diff($aliasesInModel, $aliasesInPackages);
            if (empty($aliasesNotPackaged) === false) {
                yield 'Not packaged (model exists, but not package yet):' . PHP_EOL;
                foreach ($aliasesNotPackaged as $alias) {
                    yield '  ' . $alias . PHP_EOL;
                }
            }
            
            $aliasesNotInstalled = array_diff($aliasesInPackages, $aliasesInModel);
            if (empty($aliasesNotInstalled) === false) {
                yield 'Not installed (package exists, but model not installed yet):' . PHP_EOL;
                foreach ($aliasesNotInstalled as $alias) {
                    yield '  ' . $alias . PHP_EOL;
                }
            }
            
            // Trigger regular action post-processing as required by AbstractActionDeferred.
            $this->performAfterDeferred($result, $transaction);
        };
        
        $result->setMessageStreamGenerator($generator);
        return $result;
    }
    
    /**
     * Finds aliases of apps among the packages in the given vendor folder.
     * 
     * @return string[]
     */
    public static function findAppAliasesInVendorFolders(string $vendorFolderAbsolutePath) : array
    {
        $installedAppAliases = [];
        foreach (glob($vendorFolderAbsolutePath . '/*' , GLOB_ONLYDIR) as $vendorPath) {
            foreach (glob($vendorPath . '/*' , GLOB_ONLYDIR) as $packagePath) {
                if (file_exists($packagePath . DIRECTORY_SEPARATOR . 'composer.json')) {
                    $composerJson = json_decode(file_get_contents($packagePath . DIRECTORY_SEPARATOR . 'composer.json'), true);
                    if (is_array($composerJson) === false) {
                        continue;
                    }
                    
                    if (array_key_exists('extra', $composerJson) && array_key_exists('app', $composerJson['extra']) && $alias = $composerJson['extra']['app']['app_alias']) {
                        $installedAppAliases[] = $alias;
                    }
                }
            }
        }
        $installedAppAliases = array_unique($installedAppAliases);
        return $installedAppAliases;
    }
    
    /**
     * Returns an array of aliases of all apps found in the current meta model.
     * 
     * @param WorkbenchInterface $workbench
     * @return string[]
     */
    public static function findAppAliasesInModel(WorkbenchInterface $workbench) : array
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'exface.Core.APP');
        $ds->getColumns()->addFromExpression('ALIAS');
        $ds->dataRead();
        return $ds->getColumns()->get('ALIAS')->getValues(false);
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments() : array
    {
        return [];
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() : array
    {
        return [];
    }
    
}