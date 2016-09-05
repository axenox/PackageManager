<?php namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Exceptions\AppNotFoundError;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\Model\Object;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Exceptions\ActionRuntimeException;
use exface\Core\Interfaces\AppInterface;

/**
 * This Action saves alle elements of the meta model assotiated with an app as JSON files in the Model subfolder of the current 
 * installations folder of this app.
 * 
 * @author Andrej Kabachnik
 *
 */
class ExportAppModel extends AbstractAction {
	
	private $export_to_path_relative = null;
	
	protected function init(){
		$this->set_icon_name('export-data');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		$apps = $this->get_input_apps_data_sheet();
		
		$exported_counter = 0;
		foreach ($apps->get_rows() as $row){
			try {
				$app = $this->get_workbench()->get_app($row['ALIAS']);
			} catch (AppNotFoundError $e){
				$workbench = $this->get_workbench();
				$name_resolver = NameResolver::create_from_string($row['ALIAS'], NameResolver::OBJECT_TYPE_APP, $workbench);
				$this->get_app()->create_app_folder($name_resolver);
				$app = $this->get_workbench()->get_app($row['ALIAS']);
			}
			$this->export_model($app);
			$exported_counter++;
		}
			
		// Save the result and output a message for the user
		$this->set_result('');
		$this->set_result_message('Exported model files for ' . $exported_counter . ' apps to app-folders in "' . $this->get_export_to_path_relative() . '".');
		
		return;
	}
	
	/**
	 * 
	 * @throws ActionRuntimeException
	 * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
	 */
	protected function get_input_apps_data_sheet(){
		if ($this->get_input_data_sheet()
				&& !$this->get_input_data_sheet()->is_empty()
				&& !$this->get_input_data_sheet()->get_meta_object()->is('exface.Core.APP')){
					throw new ActionRuntimeException('Action "' . $this->get_alias() . '" exprects an exface.Core.APP as input, "' . $this->get_input_data_sheet()->get_meta_object()->get_alias_with_namespace() . '" given instead!');
		}
		
		$apps = $this->get_input_data_sheet()->copy();
		$apps->get_columns()->add_from_expression('ALIAS');
		if (!$apps->is_up_to_date()){
			if (!$apps->is_empty()){
				$apps->add_filter_from_column_values($apps->get_uid_column()->get_values(false));
			}
			$apps->data_read();
		}
		return $apps;
	}
	
	protected function export_model(AppInterface $app){
		$this->get_app()->filemanager()->mkdir($this->get_app()->get_path_to_app_absolute($app, $this->get_export_to_path_relative()) . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL);
		$this->export_model_file($app, $this->get_workbench()->model()->get_object('ExFace.Core.APP'), 'UID');
		$this->export_model_file($app, $this->get_workbench()->model()->get_object('ExFace.Core.OBJECT'), 'APP');
		$this->export_model_file($app, $this->get_workbench()->model()->get_object('ExFace.Core.OBJECT_BEHAVIORS'), 'OBJECT__APP');
		$this->export_model_file($app, $this->get_workbench()->model()->get_object('ExFace.Core.ATTRIBUTE'), 'OBJECT__APP');
		$this->export_model_file($app, $this->get_workbench()->model()->get_object('ExFace.Core.DATASRC'), 'APP');
		$this->export_model_file($app, $this->get_workbench()->model()->get_object('ExFace.Core.CONNECTION'), 'APP');
	}
	
	protected function export_model_file(AppInterface $app, Object $object, $app_filter_attribute_alias){
		/** @var $ds \exface\Core\CommonLogic\DataSheets\DataSheet */
		$ds = $this->get_workbench()->data()->create_data_sheet($object);
		foreach ($object->get_attribute_group('~ALL')->get_attributes() as $attr){
			$ds->get_columns()->add_from_expression($attr->get_alias());
		}
		$ds->add_filter_from_string($app_filter_attribute_alias, $app->get_uid());
		$ds->get_sorters()->add_from_string('CREATED_ON', 'ASC');
		$ds->get_sorters()->add_from_string($object->get_uid_alias(), 'ASC');
		$ds->data_read();
		$this->get_app()->filemanager()->dumpFile($this->get_app()->get_path_to_app_absolute($app, $this->get_export_to_path_relative()) . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL . DIRECTORY_SEPARATOR . $object->get_alias() . '.json', $ds->to_uxon());
	}
	
	public function get_export_to_path_relative() {
		return $this->export_to_path_relative;
	}
	
	public function set_export_to_path_relative($value) {
		$this->export_to_path_relative = $value;
		return $this;
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