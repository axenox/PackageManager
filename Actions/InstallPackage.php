<?php namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\ActionInterface;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Exceptions\ActionRuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\AbstractAction;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class InstallPackage extends AbstractAction {
	
	private $source_path = null;
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
			$source = $this->get_source_path() . DIRECTORY_SEPARATOR . self::get_path_from_alias($app_alias);
			$target = NameResolver::APPS_DIRECTORY . DIRECTORY_SEPARATOR . self::get_path_from_alias($app_alias);
			try {
				$installed_counter++;
				$this->install($source, $target);
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
	
	public function get_source_path() {
		if (is_null($this->source_path)){
			$this->source_path = NameResolver::APPS_DIRECTORY;
		}
		return $this->source_path;
	}
	
	public function set_source_path($value) {
		$this->source_path = $value;
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
	 * @param string $source_path
	 * @param string $target_path
	 * @throws ActionRuntimeException
	 * @return void
	 */
	public function install($source_path, $target_path){
		if (!file_exists($source_path) || !is_dir($source_path)){
			throw new ActionRuntimeException('"' . $source_path . '" does not point to an installable app!');
		}
	
		// Install the model
		$exface = $this->get_workbench();
		$model_source = $source_path . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL;
		if (is_dir($model_source)){
			$this->add_result_message("Model...\n");
			$transaction = $this->get_workbench()->data()->start_transaction();
			foreach (scandir($model_source) as $file){
				if ($file == '.' || $file == '..') continue;
				$data_sheet = DataSheetFactory::create_from_uxon($exface, UxonObject::from_json(file_get_contents($model_source . DIRECTORY_SEPARATOR . $file)));
				if ($behavior = $data_sheet->get_meta_object()->get_behaviors()->get_by_alias('exface.Core.TimeStampingBehavior')){
					$behavior->disable();
				}
				$counter = $data_sheet->data_replace_matching_filters(true);
				$this->add_result_message($data_sheet->get_meta_object()->get_name() . " - " .  $counter . "\n");
			}
			$transaction->commit();
		}
	}
}
?>