<?php namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\ActionInterface;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Exceptions\ActionRuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\AppFactory;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class InstallApp extends AbstractAction {
	
	private $vendor_folder_path = null;
	private $target_app_aliases = array();
	
	protected function init(){
		$this->set_icon_name('repair');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		$exface = $this->get_workbench();
		$installed_counter = 0;
		foreach ($this->get_target_app_aliases() as $app_alias){
			$this->add_result_message("Installing " . $app_alias . "...\n");
			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			try {
				$installed_counter++;
				$this->install($app_name_resolver);
			} catch (\Exception $e){
				$installed_counter--;
				// FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
				throw $e;
			}
			$this->add_result_message($app_alias . " successfully installed.\n");
		}
			
		// Save the result and output a message for the user
		$this->set_result('');
		
		return;
	}
	
	public function get_app_by_alias($alias){
		try {
			$app = $this->get_workbench()->get_app($alias);
		} catch (AppNotFoundError $e){
			$workbench = $this->get_workbench();
			$name_resolver = NameResolver::create_from_string($alias, NameResolver::OBJECT_TYPE_APP, $workbench);
			$this->get_app()->create_app_folder($name_resolver);
			$app = $this->get_workbench()->get_app($alias);
		}
		return $app;
	}
	
	public function get_vendor_folder_path() {
		if (is_null($this->vendor_folder_path)){
			$this->vendor_folder_path = $this->get_app()->filemanager()->get_path_to_vendor_folder();
		}
		return $this->vendor_folder_path;
	}
	
	public function set_vendor_folder_path($value) {
		$this->vendor_folder_path = $value;
		return $this;
	}  
	
	/**
	 * @return PackageManagerApp
	 * @see \exface\Core\Interfaces\Actions\ActionInterface::get_app()
	 */
	public function get_app(){
		return parent::get_app();
	}
	
	public function get_target_app_aliases() {
		if ( count($this->target_app_aliases) < 1
		&& $this->get_input_data_sheet()
		&& $this->get_input_data_sheet()->get_meta_object()->is('exface.Core.APP')
		&& !$this->get_input_data_sheet()->is_empty()){
			$this->get_input_data_sheet()->get_columns()->add_from_expression('ALIAS');
			if (!$this->get_input_data_sheet()->is_up_to_date()){
				$this->get_input_data_sheet()->data_read();
			}
			$this->target_app_aliases = $this->get_input_data_sheet()->get_column_values('ALIAS', false);
		}
		return $this->target_app_aliases;
	}
	
	public function set_target_app_aliases(array $values) {
		$this->target_app_aliases = $values;
		return $this;
	}  
	
	protected static function get_path_from_alias($string){
		return str_replace(NameResolver::NAMESPACE_SEPARATOR, DIRECTORY_SEPARATOR, $string);
	}
	
	/**
	 * 
	 * @param NameResolverInterface $app_name_resolver
	 * @throws ActionRuntimeException
	 * @return void
	 */
	public function install(NameResolverInterface $app_name_resolver){
		$result = '';
		
		// Install the model
		$result .= "\nModel changes: ";
		$result .= $this->install_model($app_name_resolver);
		
		// Finalize installation running the custom installer of the app
		$app = AppFactory::create($app_name_resolver);
		$custom_installer_result = $app->install();
		if ($custom_installer_result){
			$result .= ".\nFinalizing installation: " . $custom_installer_result;
		}
			
		// Save the result
		$this->add_result_message($result);
		return $result;
	}
	
	public function install_model(NameResolverInterface $app_name_resolver){
		$result = '';
		$exface = $this->get_workbench();
		$model_source = $this->get_app_absolute_path($app_name_resolver) . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL;
		
		if (is_dir($model_source)){
			$transaction = $this->get_workbench()->data()->start_transaction();
			foreach (scandir($model_source) as $file){
				if ($file == '.' || $file == '..') continue;
				$data_sheet = DataSheetFactory::create_from_uxon($exface, UxonObject::from_json(file_get_contents($model_source . DIRECTORY_SEPARATOR . $file)));
				if ($mod_col = $data_sheet->get_columns()->get_by_expression('MODIFIED_ON')){
					$mod_col->set_ignore_fixed_values(true);
				}
				if ($user_col = $data_sheet->get_columns()->get_by_expression('MODIFIED_BY_USER')){
					$user_col->set_ignore_fixed_values(true);
				}
				// Disable timestamping behavior because it will prevent multiple installations of the same
				// model since the first install will set the update timestamp to something later than the
				// timestamp saved in the model files
				if ($behavior = $data_sheet->get_meta_object()->get_behaviors()->get_by_alias('exface.Core.Behaviors.TimeStampingBehavior')){
					$behavior->disable();
				}
		
				$counter = $data_sheet->data_replace_by_filters(true);
				if ($counter > 0){
					$result .= ($result ? "; " : "") . $data_sheet->get_meta_object()->get_name() . " - " .  $counter;
				}
			}
			// Commit the transaction
			$transaction->commit();
			
			if (!$result){
				$result = 'No changes found';
			}
			
		} else {
			$result = 'No model files to install';
		}
		return $result;
	}
	
	public function get_app_absolute_path(NameResolverInterface $app_name_resolver){
		$app_path = $this->get_app()->filemanager()->get_path_to_vendor_folder() . $app_name_resolver->get_class_directory();
		if (!file_exists($app_path) || !is_dir($app_path)){
			throw new ActionRuntimeException('"' . $app_path . '" does not point to an installable app!');
		}
		return $app_path;
	}
}
?>