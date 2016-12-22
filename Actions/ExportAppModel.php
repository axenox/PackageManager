<?php namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Exceptions\AppNotFoundError;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\Model\Object;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;

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
	 * @throws ActionInputInvalidObjectError
	 * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
	 */
	protected function get_input_apps_data_sheet(){
		if ($this->get_input_data_sheet()
				&& !$this->get_input_data_sheet()->is_empty()
				&& !$this->get_input_data_sheet()->get_meta_object()->is_exactly('exface.Core.APP')){
					throw new ActionInputInvalidObjectError($this, 'Action "' . $this->get_alias() . '" exprects an exface.Core.APP as input, "' . $this->get_input_data_sheet()->get_meta_object()->get_alias_with_namespace() . '" given instead!', '6T5TUR1');
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
		$this->get_app()->filemanager()->mkdir($this->get_model_folder_path_absolute($app));
		$this->get_app()->filemanager()->emptyDir($this->get_model_folder_path_absolute($app));
		
		// Fetch all model data in form of data sheets
		$sheets = $this->get_model_data_sheets($app);
		
		// Save each data sheet as a file and additionally compute the modification date of the last modified model instance and
		// the MD5-hash of the entire model definition (concatennated contents of all files). This data will be stored in the composer.json
		// and used in the installation process of the package
		$last_modification_time = '0000-00-00 00:00:00';
		$model_string = '';
		foreach ($sheets as $nr => $ds){
			$model_string .= $this->export_model_file($app, $ds, $nr.'_');
			$time = $ds->get_columns()->get_by_attribute($ds->get_meta_object()->get_attribute('MODIFIED_ON'))->aggregate(EXF_AGGREGATOR_MAX);
			$last_modification_time = $time > $last_modification_time ? $time : $last_modification_time;
		}
		
		// Save some information about the package in the extras of composer.json
		$package_props = array(
			'app_uid' 			=> $app->get_uid(),
			'app_alias' 		=> $app->get_alias_with_namespace(),
			'model_md5' 		=> md5($model_string),
			'model_timestamp' 	=> $last_modification_time
		);
		$composer_json = $this->get_app()->get_composer_json($app);
		$composer_json['extra']['app'] = $package_props;
		$this->get_app()->set_composer_json($app, $composer_json);
		
		return $this;
	}
	
	/**
	 * 
	 * @param AppInterface $app
	 * @return DataSheetInterface[]
	 */
	public function get_model_data_sheets(AppInterface $app){
		$sheets = array();
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.APP'), 'UID');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.OBJECT'), 'APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.OBJECT_BEHAVIORS'), 'OBJECT__APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.ATTRIBUTE'), 'OBJECT__APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.DATASRC'), 'APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.CONNECTION'), 'APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.ERROR'), 'APP');
		return $sheets;
	}
	
	/**
	 * 
	 * @param AppInterface $app
	 * @param Object $object
	 * @param string $app_filter_attribute_alias
	 * @return DataSheetInterface
	 */
	protected function get_object_data_sheet(AppInterface $app, Object $object, $app_filter_attribute_alias){
		$ds = DataSheetFactory::create_from_object($object);
		foreach ($object->get_attribute_group('~ALL')->get_attributes() as $attr){
			$ds->get_columns()->add_from_expression($attr->get_alias());
		}
		$ds->add_filter_from_string($app_filter_attribute_alias, $app->get_uid());
		$ds->get_sorters()->add_from_string('CREATED_ON', 'ASC');
		$ds->get_sorters()->add_from_string($object->get_uid_alias(), 'ASC');
		$ds->data_read();
		return $ds;
	}
	
	/**
	 * Writes the UXON representation of a data sheet, that contains all instances of the given object to a file and
	 * returns the file contents.
	 * The $app_filter_attribute_alias is used to create a filter for the data sheet that makes sure only those instances
	 * assotiated with the given app are exported.
	 * 
	 * @param AppInterface $app
	 * @param Object $object
	 * @param string $app_filter_attribute_alias
	 * @return string
	 */
	protected function export_model_file(AppInterface $app, DataSheetInterface $data_sheet, $filename_prefix = null){
		$contents = $data_sheet->to_uxon();
		$this->get_app()->filemanager()->dumpFile($this->get_model_folder_path_absolute($app) . DIRECTORY_SEPARATOR . $filename_prefix . $data_sheet->get_meta_object()->get_alias() . '.json', $contents);
		return $contents;
	}
	
	protected function get_model_folder_path_absolute(AppInterface $app){
		return $this->get_app()->get_path_to_app_absolute($app, $this->get_export_to_path_relative()) . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL;
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