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

    protected function perform()
    {
        $exface = $this->getWorkbench();
        $installed_counter = 0;
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $result = '';
            $app_selector = new AppSelector($exface, $app_alias);
            try {
                $installed_counter ++;
                $installer = new MetaModelInstaller($app_selector);
                $result .= $installer->install($this->getAppAbsolutePath($app_selector));
            } catch (\Exception $e) {
                $installed_counter --;
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
            $this->addResultMessage("Importing meta model for " . $app_alias . ": " . $result);
        }
        
        $this->getWorkbench()->clearCache();
        
        // Save the result and output a message for the user
        $this->setResult('');
        
        return;
    }
}
?>