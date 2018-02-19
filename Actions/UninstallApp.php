<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Factories\AppFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;

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

    protected function perform()
    {
        $exface = $this->getWorkbench();
        $installed_counter = 0;
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $this->addResultMessage("Uninstalling " . $app_alias . "...\n");
            $app_selector = new AppSelector($exface, $app_alias);
            try {
                $installed_counter ++;
                $this->uninstall($app_selector);
            } catch (\Exception $e) {
                $installed_counter --;
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
            $this->addResultMessage($app_alias . " successfully uninstalled.\n");
        }
        
        // Save the result and output a message for the user
        $this->setResult('');
        
        return;
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @return void
     */
    public function uninstall(AppSelectorInterface $app_selector)
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
        
        // Save the result
        $this->addResultMessage($result);
        return $result;
    }

    public function uninstallModel(AppInterface $app)
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