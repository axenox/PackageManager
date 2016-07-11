<?php
namespace axenox\PackageManager\Actions;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Exceptions\ActionRuntimeException;
use exface\Core\CommonLogic\AbstractApp;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\Model\Object;
use exface\Core\CommonLogic\NameResolver;
/**
 * This action runs one or more selected test steps
 * 
 * @author aka
 *
 */
class MakePackage extends AbstractAction {
	
	private $export_to_path_relative = null;
	
	protected function init(){
		$this->set_icon_name('new-app');
		$this->set_input_rows_min(1);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		if (strcasecmp($this->get_input_data_sheet()->get_meta_object()->get_alias_with_namespace(), 'exface.Core.APP') != 0){
			throw new ActionRuntimeException('Action "' . $this->get_alias() . '" exprects an exface.Core.APP as input, "' . $this->get_input_data_sheet()->get_meta_object()->get_alias_with_namespace() . '" given instead!');
		}
		
		$apps = $this->get_input_data_sheet();
		$apps->get_columns()->add_from_expression('ALIAS');
		if (!$apps->is_up_to_date()){
			$apps->add_filter_from_column_values($apps->get_uid_column()->get_values(false));
			$apps->data_read();
		}
		
		$exported_counter = 0;
		foreach ($apps->get_rows() as $row){
			$this->export_package($this->exface()->get_app($row['ALIAS']));
			$exported_counter++;
		}
			
		// Save the result and output a message for the user
		$this->set_result('');
		$this->set_result_message('Created ' . $exported_counter . ' Packages in "' . $this->get_export_to_path_relative() . '" object(s).');
		
		return;
	}
	
	public function export_package(AbstractApp $app){
		$this->export_files($app);
		$this->export_model($app);
		$this->export_composer_json($app);
	}
	
	protected function export_model(AbstractApp $app){
		$this->get_app()->filemanager()->mkdir($this->get_export_to_path_absolute($app) . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL);
		$this->export_model_file($app, $this->exface()->model()->get_object('ExFace.Core.APP'), 'UID');
		$this->export_model_file($app, $this->exface()->model()->get_object('ExFace.Core.APP_CONFIG'), 'APP');
		$this->export_model_file($app, $this->exface()->model()->get_object('ExFace.Core.OBJECT'), 'APP');
		$this->export_model_file($app, $this->exface()->model()->get_object('ExFace.Core.OBJECT_BEHAVIORS'), 'OBJECT__APP');
		$this->export_model_file($app, $this->exface()->model()->get_object('ExFace.Core.ATTRIBUTE'), 'OBJECT__APP');
		$this->export_model_file($app, $this->exface()->model()->get_object('ExFace.Core.DATASRC'), 'APP');
		$this->export_model_file($app, $this->exface()->model()->get_object('ExFace.Core.CONNECTION'), 'APP');
	}
	
	protected function export_model_file(AbstractApp $app, Object $object, $app_filter_attribute_alias){
		$ds = $this->exface()->data()->create_data_sheet($object);
		foreach ($object->get_attribute_group('~ALL')->get_attributes() as $attr){
			$ds->get_columns()->add_from_expression($attr->get_alias());
		}
		$ds->add_filter_from_string($app_filter_attribute_alias, $app->get_uid());
		$ds->data_read();
		$this->get_app()->filemanager()->dumpFile($this->get_export_to_path_absolute($app) . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR . $object->get_alias() . '.json', $ds->to_uxon());
	}
	
	protected function export_composer_json(AbstractApp $app){
		$file_path = $this->get_export_to_path_absolute($app) . DIRECTORY_SEPARATOR . 'composer.json';
		if (file_exists($file_path)){
			// Read the existing composer.json (need to escape slashes first, since we saved it with unescaped slashes)
			$json = json_decode(str_replace('/', '\/', file_get_contents($file_path)));
		} else {
			$json = $this->create_composer_json_from_scratch($app);
		}
		$this->get_app()->filemanager()->dumpFile($file_path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return $this;
	}
	
	protected function create_composer_json_from_scratch(AbstractApp $app){
		$json = array(
				"name" => $this->get_app()->get_vendor() . '/' . str_replace($app->get_vendor() . $this->exface()->get_config_value('namespace_separator') , '', $app->get_alias_with_namespace()),
				"version" => "0.1",
				"extra" => array(
						"app_uid" => $app->get_uid()
				)
		);
		return $json;
	}
	
	protected function export_files(AbstractApp $app){
		$absolute_path = NameResolver::APPS_DIRECTORY . DIRECTORY_SEPARATOR . $app->get_directory();
		$this->get_app()->filemanager()->copyDir($absolute_path, $this->get_export_to_path_absolute($app));
		return $this;
	}
	
	/**
	 * Returns the path to the 
	 * @param AbstractApp $app
	 * @return string
	 */
	public function get_export_to_path_relative(AbstractApp $app = null) {
		if (is_null($this->export_to_path_relative)){
			$this->export_to_path_relative = $this->get_app()->get_configuration_value('PATH_TO_AUTHORED_PACKAGES');
		}
		return $this->export_to_path_relative . ($app ? DIRECTORY_SEPARATOR . $app->get_vendor() . DIRECTORY_SEPARATOR . str_replace($app->get_vendor() . $this->exface()->get_config_value('namespace_separator'), '', $app->get_alias()) : '');
	}
	
	public function set_export_to_path_relative($value) {
		$this->export_to_path_relative = $value;
		return $this;
	}  
	
	public function get_export_to_path_absolute(AbstractApp $app = null){
		return $this->exface()->get_installation_path() . '/' . $this->get_export_to_path_relative($app);
	}
	
	/**
	 * @return PackageManagerApp
	 * @see \exface\Core\Interfaces\Actions\ActionInterface::get_app()
	 */
	public function get_app(){
		return parent::get_app();
	}
}
?>