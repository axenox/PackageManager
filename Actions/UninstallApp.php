<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Factories\AppFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;

/**
 * This action uninstalls one or more apps
 *
 * @author Andrej Kabachnik
 *        
 */
class UninstallApp extends InstallApp
{

    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::UNINSTALL);
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
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $message .= "Uninstalling " . $app_alias . "...\n";
            $app_selector = new AppSelector($workbench, $app_alias);
            try {
                $installed_counter ++;
                $this->uninstall($app_selector);
            } catch (\Exception $e) {
                $installed_counter --;
                throw $workbench->getLogger()->logException($e);
            }
            $message .= $app_alias . " successfully uninstalled.\n";
        }
        
        $workbench->getCache()->clear();
        
        return ResultFactory::createMessageResult($task, $message);
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @return void
     */
    public function uninstall(AppSelectorInterface $app_selector) : string
    {
        $result = '';
        
        // Run the custom uninstaller of the app
        $app = AppFactory::create($app_selector);
        $custom_uninstaller_result = $app->uninstall();
        if ($custom_uninstaller_result) {
            $result .= ".\nUninstalling: " . $custom_uninstaller_result;
        }
        
        // Uninstall the model
        $result .= "\nModel changes: ";
        $result .= $this->uninstallModel($app);
        
        return $result;
    }

    public function uninstallModel(AppInterface $app) : string
    {
        $result = '';
        
        $transaction = $this->getWorkbench()->data()->startTransaction();
        /* @var $data_sheet \exface\Core\CommonLogic\DataSheets\DataSheet */
        foreach ($this->getApp()->getAction('ExportAppModel')->getModelDataSheets($app) as $data_sheet) {
            if (! $data_sheet->isEmpty()) {
                $counter = $data_sheet->dataDelete($transaction);
            }
            if ($counter > 0) {
                $result .= ($result ? "; " : "") . $data_sheet->getMetaObject()->getName() . " - " . $counter;
            }
        }
        $transaction->commit();
        
        return $result;
    }
}
?>