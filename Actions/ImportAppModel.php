<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\MetaModelInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;

/**
 * This Action saves alle elements of the meta model assotiated with an app as JSON files in the Model subfolder of the current
 * installations folder of this app.
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
                $installer = new MetaModelInstaller($app_selector);
                yield from $installer->install($this->getAppAbsolutePath($app_selector));
            } catch (\Exception $e) {
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
        }
    }
}