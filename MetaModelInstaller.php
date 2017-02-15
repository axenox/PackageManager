<?php namespace axenox\PackageManager;

use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\AbstractAppInstaller;

class MetaModelInstaller extends AbstractAppInstaller {

	
	/**
	 * @return string
	 */
	public function install($source_absolute_path){
		return $this->install_model($this->get_name_resolver(), $source_absolute_path);
	}
	
	/**
	 * @return string
	 */
	public function update($source_absolute_path){
		return $this->install_model($this->get_name_resolver(), $source_absolute_path);
	}
	
	/**
	 * @return string
	 */
	public function backup($destination_absolute_path){
		
	}
	
	/**
	 * @return string
	 */
	public function uninstall(){
		
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
		$model_source = $source_absolute_path . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL;;
	
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
