<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\AppInstallers\MetaModelInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\AppInstallerInterface;

/**
 * Imports the models of one or more apps from JSON files without executing any other installers
 *
 * @author Andrej Kabachnik
 *        
 */
class ImportAppModel extends InstallApp
{

    protected function init()
    {
        $this->setIcon(Icons::WRENCH);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $aliases = []) : \Generator
    {
        foreach ($aliases as $app_alias) {
            $app_selector = new AppSelector($this->getWorkbench(), $app_alias);
            yield "Importing meta model for " . $app_alias . ": " . PHP_EOL;
            try {
                $app = $this->getWorkbench()->getApp($app_alias);
                
                // Make sure to fully instantiate the installers of an app here before fetching
                // the model installer - in case other installers will modify it or listen to its
                // events (like the DataInstaller)
                $modelInstaller = $app->getInstaller()->extract(function(AppInstallerInterface $inst){
                    return ($inst instanceof MetaModelInstaller);
                });
                
                yield from $modelInstaller->install($this->getAppAbsolutePath($app_selector));
            } catch (\Exception $e) {
                yield 'ERROR ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL;
                $this->getWorkbench()->getLogger()->logException($e);
            }
        }
    }
}