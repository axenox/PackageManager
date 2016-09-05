<?php namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\ActionInterface;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Exceptions\ActionRuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\NameResolverInterface;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class ImportAppModel extends AbstractAction {
	
	private $vendor_folder_path = null;
	private $target_app_aliases = array();
	
	protected function init(){
		$this->set_icon_name('repair');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		if ( count($this->get_target_app_aliases()) < 1
		&& $this->get_input_data_sheet() 
		&& $this->get_input_data_sheet()->get_meta_object()->is('exface.Core.APP')
		&& !$this->get_input_data_sheet()->is_empty()){
			$this->get_input_data_sheet()->get_columns()->add_from_expression('ALIAS');
			if (!$this->get_input_data_sheet()->is_up_to_date()){
				$this->get_input_data_sheet()->data_read();
			}
			$this->set_target_app_aliases($this->get_input_data_sheet()->get_column_values('ALIAS', false));
		}
		
		$installed_counter = 0;
		foreach ($this->get_target_app_aliases() as $app_alias){
			$this->add_result_message("Installing " . $app_alias . ":\n");
			$exface = $this->get_workbench();
			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			try {
				$installed_counter++;
				$this->install($app_name_resolver);
			} catch (\Exception $e){
				$installed_counter--;
				// FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
				throw $e;
			}
			$this->add_result_message("Installed " . $app_alias . "\n");
		}
			
		// Save the result and output a message for the user
		$this->set_result('');
		
		return;
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
	 * @param string $vendor_folder_path
	 * @param string $target_path
	 * @throws ActionRuntimeException
	 * @return void
	 */
	public function install(NameResolverInterface $app_name_resolver){
		$app_path = $this->get_app()->filemanager()->get_path_to_vendor_folder() . $app_name_resolver->get_class_directory();
		if (!file_exists($app_path) || !is_dir($app_path)){
			throw new ActionRuntimeException('"' . $app_path . '" does not point to an installable app!');
		}
	
		// Install the model
		$exface = $this->get_workbench();
		$model_source = $app_path . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL;
		if (is_dir($model_source)){
			$this->add_result_message("Model...\n");
			$transaction = $this->get_workbench()->data()->start_transaction();
			foreach (scandir($model_source) as $file){
				if ($file == '.' || $file == '..') continue;
				$data_sheet = DataSheetFactory::create_from_uxon($exface, UxonObject::from_json(file_get_contents($model_source . DIRECTORY_SEPARATOR . $file)));
				if ($mod_col = $data_sheet->get_columns()->get_by_expression('MODIFIED_ON')){
					$mod_col->set_ignore_fixed_values(true);
				}
				// Disable timestamping behavior because it will prevent multiple installations of the same
				// model since the first install will set the update timestamp to something later than the
				// timestamp saved in the model files
				if ($behavior = $data_sheet->get_meta_object()->get_behaviors()->get_by_alias('exface.Core.Behaviors.TimeStampingBehavior')){
					$behavior->disable();
				}
				
				$counter = $data_sheet->data_replace_by_filters(true);
				$this->add_result_message($data_sheet->get_meta_object()->get_name() . " - " .  $counter . "\n");
			}
			
			// Save version number
			/* TODO Saving the version number properly seems non trivial:
			 * 
			 * If we save it inside the app table, it will be saved to the model files too. Since saving occurs
			 * always before publishing a new version, the old version number will always be saved and published
			 * with the model - this is ugly.
			 * 
			 * If we save it in a separate table, we will need to look for entries in this table and either 
			 * create an entry or update one. Autocreate on update  des not work without UID-columns yet though.
			 * 
			 * At the moment, there is no real nessecity for a version number in the app model. So currently it
			 * seems not worth the hassle. 
			*/
			/*
			$version_ds = DataSheetFactory::create_from_object_id_or_alias($exface, 'exface.Core.APP_VERSION');
			$version_ds->add_row(array('app' => $app_uid, 'version' => $this->get_app()->get_installed_version($name_resolver->get_alias_with_namespace())));
			$version_ds->data_update(true, $transaction);*/
			
			// Commit the transaction
			$transaction->commit();
		}
		
	}
}
?>