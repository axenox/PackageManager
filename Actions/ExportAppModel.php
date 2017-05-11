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
use axenox\PackageManager\MetaModelInstaller;
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
		$resultMessage = '';
		$apps = $this->get_input_apps_data_sheet();
		$workbench = $this->get_workbench();
		$exported_counter = 0;
		foreach ($apps->get_rows() as $row){
			try {
				$app = $this->get_workbench()->get_app($row['ALIAS']);
			} catch (AppNotFoundError $e){

				$name_resolver = NameResolver::create_from_string($row['ALIAS'], NameResolver::OBJECT_TYPE_APP, $workbench);
				$this->get_app()->create_app_folder($name_resolver);
				$app = $this->get_workbench()->get_app($row['ALIAS']);
			}
			$app_name_resolver = NameResolver::create_from_string($row['ALIAS'], NameResolver::OBJECT_TYPE_APP, $workbench);
			$installer = new MetaModelInstaller($app_name_resolver);
			$backupDir = $this->get_model_folder_path_absolute($app);
			$resultMessage .=  $installer->backup($backupDir);
			$exported_counter++;
		}

		// Save the result and output a message for the user
		$this->set_result('');
		$this->set_result_message($resultMessage);

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
		if (!$apps->is_fresh()){
			if (!$apps->is_empty()){
				$apps->add_filter_from_column_values($apps->get_uid_column()->get_values(false));
			}
			$apps->data_read();
		}
		return $apps;
	}

	protected function get_model_folder_path_absolute(AppInterface $app){
		return $this->get_app()->get_path_to_app_absolute($app, $this->get_export_to_path_relative());
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