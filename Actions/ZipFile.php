<?php namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\NameResolver;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\ArchiveManager;
use exface\Core\Factories\AppFactory;

/**
 * This Action adds all files of a designated folder into a ZIP Archive
 *
 */
class ZipFile extends AbstractAction {
	private $file_path = '';
	private $file_name = 'download';

	protected function init(){
		$this->set_icon_name('repair');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}

	protected function perform(){
		$exface = $this->get_workbench();
		$result='';
		$zipManager = new ArchiveManager($exface);

		$filename = DIRECTORY_SEPARATOR .$this->file_name.".zip";
		foreach ($this->get_target_app_aliases() as $app_alias){

			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			$app = AppFactory::create($app_name_resolver);


			if ($this->get_file_path()=='') {
				$backupDir = $app->get_workbench()->filemanager()->get_path_to_backup_folder();
			} else {
				$backupDir = $app->get_workbench()->filemanager()->get_path_to_base_folder();
				$backupDir .=  DIRECTORY_SEPARATOR .$this->get_file_path();
			}
		}
		$zipManager->setFilePath($backupDir.$filename);
		if ($zipManager->addFolderFromSource($backupDir)){
			$this->add_result_message("\n\nSuccessfully added the folder ".$this->file_path." to archive!".$result);
		}
		else {
			$this->add_result_message("\n\nCould not add folder ".$this->file_path." to archive!".$result);
		}
		$zipManager->archive_close();
		// Save the result and output a message for the user
		$this->set_result('');

		return;
	}

	public function get_target_app_aliases() {
		if ( count($this->target_app_aliases) < 1
			&& $this->get_input_data_sheet()){

			if ($this->get_input_data_sheet()->get_meta_object()->is_exactly('exface.Core.APP')){
				$this->get_input_data_sheet()->get_columns()->add_from_expression('ALIAS');
				if (!$this->get_input_data_sheet()->is_empty()){
					if (!$this->get_input_data_sheet()->is_fresh()){
						$this->get_input_data_sheet()->data_read();
					}
				} elseif (!$this->get_input_data_sheet()->get_filters()->is_empty()){
					$this->get_input_data_sheet()->data_read();
				}
				$this->target_app_aliases = array_unique($this->get_input_data_sheet()->get_column_values('ALIAS', false));
			} elseif ($this->get_input_data_sheet()->get_meta_object()->is_exactly('axenox.PackageManager.PACKAGE_INSTALLED')){
				$this->get_input_data_sheet()->get_columns()->add_from_expression('app_alias');
				if (!$this->get_input_data_sheet()->is_empty()){
					if (!$this->get_input_data_sheet()->is_fresh()){
						$this->get_input_data_sheet()->data_read();
					}
				} elseif (!$this->get_input_data_sheet()->get_filters()->is_empty()){
					$this->get_input_data_sheet()->data_read();
				}
				$this->target_app_aliases = array_filter(array_unique($this->get_input_data_sheet()->get_column_values('app_alias', false)));
			} else {
				throw new ActionInputInvalidObjectError($this, 'The action "' . $this->get_alias_with_namespace() . '" can only be called on the meta objects "exface.Core.App" or "axenox.PackageManager.PACKAGE_INSTALLED" - "' . $this->get_input_data_sheet()->get_meta_object()->get_alias_with_namespace() . '" given instead!');
			}
		}

		return $this->target_app_aliases;
	}
	/**
	 * Set path to backup to different location
	 *
	 * @uxon-property backup_path
	 * @uxon-type string
	 *
	 */
	public function set_file_path($value){

		$this->file_path = str_replace("/",DIRECTORY_SEPARATOR,str_replace("\\",DIRECTORY_SEPARATOR,$value));

	}

	public function get_file_path(){
		return $this->file_path;
	}
	/**
	 * Set path to backup to different location
	 *
	 * @uxon-property file_name
	 * @uxon-type string
	 *
	 */
	public function set_file_name($value){

		$this->file_name = $value;

	}

	public function get_file_name(){
		return $this->file_name;
	}
}
?>