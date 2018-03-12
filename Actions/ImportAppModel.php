<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\MetaModelInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\CommonLogic\Tasks\ResultMessage;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;

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
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $workbench = $this->getWorkbench();
        $installed_counter = 0;
        $message = '';
        
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $app_selector = new AppSelector($workbench, $app_alias);
            try {
                $installed_counter ++;
                $installer = new MetaModelInstaller($app_selector);
                $message .= $installer->install($this->getAppAbsolutePath($app_selector));
            } catch (\Exception $e) {
                $installed_counter --;
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
            $message .= "Importing meta model for " . $app_alias . ": " . $message;
        }
        
        $workbench->clearCache();
        
        return new ResultMessage($task, $message);
    }
}
?>