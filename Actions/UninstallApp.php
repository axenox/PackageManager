<?php namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\NameResolver;
use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\AppFactory;
use exface\Core\Interfaces\AppInterface;

/**
 * This action uninstalls one or more apps
 * 
 * @author Andrej Kabachnik
 *
 */
class UninstallApp extends InstallApp {
	
	protected function init(){
		parent::init();
		$this->set_icon_name('uninstall');
	}	
	
	protected function perform(){
		$exface = $this->get_workbench();
		$installed_counter = 0;
		foreach ($this->get_target_app_aliases() as $app_alias){
			$this->add_result_message("Uninstalling " . $app_alias . "...\n");
			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			try {
				$installed_counter++;
				$this->uninstall($app_name_resolver);
			} catch (\Exception $e){
				$installed_counter--;
				// FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
				throw $e;
			}
			$this->add_result_message($app_alias . " successfully uninstalled.\n");
		}
			
		// Save the result and output a message for the user
		$this->set_result('');
		
		return;
	}
	
	/**
	 * 
	 * @param NameResolverInterface $app_name_resolver
	 * @return void
	 */
	public function uninstall(NameResolverInterface $app_name_resolver){
		$result = '';
		
		// Run the custom uninstaller of the app
		$app = AppFactory::create($app_name_resolver);
		$custom_uninstaller_result = $app->uninstall();
		if ($custom_uninstaller_result){
			$result .= ".\nUninstalling: " . $custom_uninstaller_result;
		}
		
		// Uninstall the model
		$result .= "\nModel changes: ";
		$result .= $this->uninstall_model($app);
			
		// Save the result
		$this->add_result_message($result);
		return $result;
	}
	
	public function uninstall_model(AppInterface $app){
		$result = '';
		
		$transaction = $this->get_workbench()->data()->start_transaction();
		/* @var $data_sheet \exface\Core\CommonLogic\DataSheets\DataSheet */
		foreach ($this->get_app()->get_action('ExportAppModel')->get_model_data_sheets($app) as $data_sheet){
			if (!$data_sheet->is_empty()){
				$counter = $data_sheet->data_delete($transaction);
			}
			if ($counter > 0){
				$result .= ($result ? "; " : "") . $data_sheet->get_meta_object()->get_name() . " - " .  $counter;
			}
		}
		$transaction->commit();
		
		return $result;
	}

}
?>