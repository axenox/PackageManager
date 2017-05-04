<?php namespace axenox\PackageManager;

use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Model\Object;
use exface\Core\CommonLogic\AbstractAppInstaller;

class MetaModelInstaller extends AbstractAppInstaller {

	const FOLDER_NAME_MODEL = 'Model';

	/**
	 * @param $source_absolute_path
	 * @return string
	 */
	public function install($source_absolute_path){
		return $this->install_model($this->get_name_resolver(), $source_absolute_path);
	}

	/**
	 * @param $source_absolute_path
	 * @return string
	 */
	public function update($source_absolute_path){
		return $this->install_model($this->get_name_resolver(), $source_absolute_path);
	}

	/**
	 * @param string $destination_absolute_path Destination folder for meta model backup
	 * @return string
	 */
	public function backup($destination_absolute_path){
		return $this->backup_model($destination_absolute_path);
	}

	/**
	 * @return string
	 */
	public function uninstall(){

	}

	/**
	 * Analyzes model data sheet and writes json files to the model folder
	 * @param $destinationAbsolutePath
	 * @return string
	 */
	protected function backup_model($destinationAbsolutePath){
		$app = $this->get_app();
		$dir = $destinationAbsolutePath.DIRECTORY_SEPARATOR.self::FOLDER_NAME_MODEL;
		$app->get_workbench()->filemanager()->path_construct($dir);

		// Fetch all model data in form of data sheets
		$sheets = $this->get_model_data_sheets();

		// Save each data sheet as a file and additionally compute the modification date of the last modified model instance and
		// the MD5-hash of the entire model definition (concatennated contents of all files). This data will be stored in the composer.json
		// and used in the installation process of the package
		$last_modification_time = '0000-00-00 00:00:00';
		$model_string = '';
		foreach ($sheets as $nr => $ds){
			$model_string .= $this->export_model_file($dir,$ds, $nr.'_');
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
		$composer_app = $this->get_workbench()->get_app("axenox.PackageManager");
		$composer_json = $composer_app->get_composer_json($composer_app);
		$composer_json['extra']['app'] = $package_props;
		$composer_app->set_composer_json($app, $composer_json);

		return '\n Created meta model backup for '.$app->get_alias_with_namespace().'.';
	}
	/**
	 * Writes JSON File of a $data_sheet to a specific location
	 *
	 * @param string $backupDir
	 * @param $data_sheet
	 * @param string $filename_prefix
	 * @return string
	 */
	protected function export_model_file($backupDir, $data_sheet, $filename_prefix = null){
		$contents = $data_sheet->to_uxon();
		if (!$data_sheet->is_empty()){
			$fileManager = $this->get_workbench()->filemanager();
			$fileManager->dumpFile($backupDir . DIRECTORY_SEPARATOR . $filename_prefix . $data_sheet->get_meta_object()->get_alias() . '.json', $contents);
			return $contents;
		}

		return '';
	}

	/**
	 *
	 * @param AppInterface $app
	 * @return DataSheetInterface[]
	 */
	public function get_model_data_sheets(){
		$sheets = array();
		$app = $this->get_app();
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.APP'), 'UID');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.OBJECT'), 'APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.OBJECT_BEHAVIORS'), 'OBJECT__APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.ATTRIBUTE'), 'OBJECT__APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.DATASRC'), 'APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.CONNECTION'), 'APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.ERROR'), 'APP');
		$sheets[] = $this->get_object_data_sheet($app, $this->get_workbench()->model()->get_object('ExFace.Core.OBJECT_ACTION'), 'APP');
		return $sheets;
	}
	/**
	 *
	 * @param AppInterface $app
	 * @param Object $object
	 * @param string $app_filter_attribute_alias
	 * @return DataSheetInterface
	 */
	protected function get_object_data_sheet($app, Object $object, $app_filter_attribute_alias){
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
	 *
	 * @param NameResolverInterface $app_name_resolver
	 * @param string $source_absolute_path
	 * @return string
	 */
	protected function install_model(NameResolverInterface $app_name_resolver, $source_absolute_path){
		$result = '';
		$exface = $this->get_workbench();
		$model_source = $source_absolute_path . DIRECTORY_SEPARATOR . self::FOLDER_NAME_MODEL;

		if (is_dir($model_source)){
			$transaction = $this->get_workbench()->data()->start_transaction();
			foreach (scandir($model_source) as $file){
				if ($file == '.' || $file == '..') continue;
				$data_sheet = DataSheetFactory::create_from_uxon($exface, UxonObject::from_json(file_get_contents($model_source . DIRECTORY_SEPARATOR . $file)));

				// Remove columns, that are not attributes. This is important to be able to import changes on the meta model itself.
				// The trouble is, that after new properties of objects or attributes are added, the export will already contain them
				// as columns, which would lead to an error because the model entities for these columns are not there yet.
				foreach ($data_sheet->get_columns() as $column){
					if (!$column->get_meta_object()->has_attribute($column->get_attribute_alias()) || !$column->get_attribute()){
						$data_sheet->get_columns()->remove($column);
					}
				}

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

				$counter = $data_sheet->data_replace_by_filters($transaction);
				if ($counter > 0){
					$result .= ($result ? "; " : "") . $data_sheet->get_meta_object()->get_name() . " - " .  $counter;
				}
			}
			// Commit the transaction
			$transaction->commit();

			if (!$result){
				$result .= 'No changes found';
			}

		} else {
			$result .= 'No model files to install';
		}
		return "\nModel changes: " . $result;
	}
}