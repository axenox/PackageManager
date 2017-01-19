<?php namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\NameResolver;
use axenox\PackageManager\MetaModelInstaller;

/**
 * This Action saves alle elements of the meta model assotiated with an app as JSON files in the Model subfolder of the current 
 * installations folder of this app.
 * 
 * @author Andrej Kabachnik
 *
 */
class ImportAppModel extends InstallApp {
	
	protected function init(){
		$this->set_icon_name('repair');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		$exface = $this->get_workbench();
		$installed_counter = 0;
		foreach ($this->get_target_app_aliases() as $app_alias){
			$result = '';
			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			try {
				$installed_counter++;
				$installer = new MetaModelInstaller($app_name_resolver);
				$result .= $installer->install($this->get_app_absolute_path($app_name_resolver));
			} catch (\Exception $e){
				$installed_counter--;
				// FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
				throw $e;
			}
			$this->add_result_message("Importing meta model for " . $app_alias . ": " . $result);
		}
			
		// Save the result and output a message for the user
		$this->set_result('');
	
		return;
	}

}
?>