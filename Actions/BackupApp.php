<?php namespace axenox\PackageManager\Actions;

use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\AppFactory;
use exface\Core\Exceptions\DirectoryNotFoundError;
use axenox\PackageManager\MetaModelInstaller;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;

/**
 * This action installs one or more apps including their meta model, custom installer, etc.
 * 
 * @method PackageManagerApp get_app()
 * 
 * @author Andrej Kabachnik
 *
 */
class BackupApp extends AbstractAction {
	
	private $target_app_aliases = array();
	private $backup_path = '';

	protected function init(){
		$this->set_icon_name('repair');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		$exface = $this->get_workbench();
		$backup_counter = 0;
		foreach ($this->get_target_app_aliases() as $app_alias){
			$this->add_result_message("Creating Backup for " . $app_alias . "...\n");
			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			try {
				$backup_counter++;
				$this->backup($app_name_resolver);
			} catch (\Exception $e){
				$backup_counter--;
				// FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
				throw $e;
			}
			$this->add_result_message("\n Sucessfully created backup for " . $app_alias . " .\n");
		}
		
		if (count($this->get_target_app_aliases()) == 0){
			$this->add_result_message('No apps had been selected for backup!');
		} elseif ($backup_counter == 0) {
			$this->add_result_message('No backups have been created');
		}
			
		// Save the result
		$this->set_result('');
		
		return;
	}

	/**
	 * Get all affected apps
	 *
	 * @return array
	 * @throws ActionInputInvalidObjectError
	 */
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
	
	public function set_target_app_aliases(array $values) {
		$this->target_app_aliases = $values;
		return $this;
	}

	/**
	 * 
	 * @param NameResolverInterface $app_name_resolver
	 * @return string
	 */
	public function backup(NameResolverInterface $app_name_resolver){
		$result = '';

		$app = AppFactory::create($app_name_resolver);

		$installer = $app->get_installer(new MetaModelInstaller($app_name_resolver));
		$appAlias = $app_name_resolver->get_alias();
		$directory = $app_name_resolver->get_class_directory();
		if ($this->get_backup_path()=='') {
			$backupDir = $app->get_workbench()->filemanager()->get_path_to_backup_folder();
			$sDirName = $appAlias . "_backup_" . date('Y_m_d_H');
			$backupDir .= $directory . DIRECTORY_SEPARATOR . $sDirName;
		} else {
			$backupDir = $app->get_workbench()->filemanager()->get_path_to_base_folder();
			$backupDir .=  DIRECTORY_SEPARATOR.$this->get_backup_path().$directory;
		}
		$backupDir = $app->get_workbench()->filemanager()->path_normalize($backupDir,DIRECTORY_SEPARATOR);

		$installer_result = $installer->backup($backupDir);
		$result .= $installer_result . (substr($installer_result, -1) != '.' ? '.' : '');
			
		// Save the result
		$this->add_result_message($result);
		return $result;
	}
	
	/**
	 * 
	 * @param NameResolverInterface $app_name_resolver
	 * @throws DirectoryNotFoundError
	 * @return string
	 */
	public function get_app_absolute_path(NameResolverInterface $app_name_resolver){
		$app_path = $this->get_app()->filemanager()->get_path_to_vendor_folder() . $app_name_resolver->get_class_directory();
		if (!file_exists($app_path) || !is_dir($app_path)){
			throw new DirectoryNotFoundError('"' . $app_path . '" does not point to an installable app!', '6T5TZN5');
		}
		return $app_path;
	}
	/**
	 * Set path to backup to different location
	 *
	 * @uxon-property backup_path
	 * @uxon-type string
	 *
	 */
	public function set_backup_path($value){

			$this->backup_path = $value;

	}

	public function get_backup_path(){
		return $this->backup_path;
	}
}
?>