<?php
namespace axenox\PackageManager;

use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\AppFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\NameResolver;

class PackageManagerApp extends \exface\Core\CommonLogic\AbstractApp {
	
	const FOLDER_NAME_MODEL = 'Model';
	
	public function filemanager(){
		return $this->get_workbench()->filemanager();
	}
	
	public function create_app_folder(NameResolverInterface $name_resolver){
		
		// Make sure the vendor folder exists
		$app_vendor_folder = $this->filemanager()->get_path_to_vendor_folder() . DIRECTORY_SEPARATOR . $name_resolver->get_vendor();
		if (!is_dir($app_vendor_folder)){
			mkdir($app_vendor_folder);
		}
		
		// Create the app folder
		$app_folder = $app_vendor_folder . DIRECTORY_SEPARATOR . $name_resolver->get_alias();
		if (!is_dir($app_folder)){
			mkdir($app_folder);
		}
		
		// Make sure, the app class exists
		if (!class_exists($name_resolver->get_class_name_with_namespace())){
			$this->create_app_class($name_resolver);
		}
		$app = AppFactory::create($name_resolver);
				
		$this->create_composer_json($app);
	}
	
	protected function create_app_class(NameResolverInterface $name_resolver){
		$class_name = $name_resolver->get_alias() . 'App';
		$class_namespace = substr($name_resolver->get_class_namespace(), 1);
		$file_path = $this->filemanager()->get_path_to_vendor_folder() . $name_resolver->get_class_directory() . DIRECTORY_SEPARATOR . $class_name . '.php';
		$file_contents = <<<PHP
<?php namespace {$class_namespace};
				
class {$class_name} extends \\exface\\Core\\CommonLogic\\AbstractApp {
	
}	
PHP;

		$this->filemanager()->dumpFile($file_path, $file_contents);
	}
	
	protected function create_composer_json(AppInterface $app){
		$file_path = $this->get_path_to_app_absolute($app) . DIRECTORY_SEPARATOR . 'composer.json';
		if (!file_exists($file_path)){
			$json = array(
					"name" 		=> $app->get_vendor() . '/' . str_replace($app->get_vendor() . $this->get_workbench()->get_config_value('namespace_separator') , '', $app->get_alias_with_namespace()),
					"require" 	=> array(
							"exface/core" => "~0.1"
					),
					"extra" 	=> array(
							"app_uid" => $app->get_uid()
					)
			);
			$this->filemanager()->dumpFile($file_path, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}
		return $this;
	}
	
	/**
	 * Returns the path to the
	 * @param AbstractApp $app
	 * @return string
	 */
	public function get_path_to_app_relative(AppInterface $app = null, $base_path = '') {
		$path = '';
		if (!$base_path){
			if ($this->get_configuration_value('PATH_TO_AUTHORED_PACKAGES')){
				$path = $this->get_app()->get_configuration_value('PATH_TO_AUTHORED_PACKAGES') . DIRECTORY_SEPARATOR;
			} else {
				$path = 'vendor' . DIRECTORY_SEPARATOR;
			}
		}
		return $path . ($app ? $app->get_vendor() . DIRECTORY_SEPARATOR . str_replace($app->get_vendor() . $this->get_workbench()->get_config_value('namespace_separator'), '', $app->get_alias()) : '');
	}
	
	public function get_path_to_app_absolute(AppInterface $app = null, $base_path = ''){
		return $this->get_workbench()->get_installation_path() . DIRECTORY_SEPARATOR  . $this->get_path_to_app_relative($app, $base_path);
	}
	
	public function get_installed_version($app_alias){
		$package_object = $this->get_workbench()->model()->get_object('axenox.PackageManager.PACKAGE_INSTALLED');
		$data_sheet = DataSheetFactory::create_from_object($package_object);
		$data_sheet->get_columns()->add_from_expression('version');
		$data_sheet->add_filter_from_string('name', $this->get_package_name_from_app_alias($app_alias));
		$data_sheet->data_read();
		return $data_sheet->get_cell_value('version', 0);
	}
	
	public function get_package_name_from_app_alias($app_alias){
		return str_replace(NameResolver::NAMESPACE_SEPARATOR, '/', $app_alias);
	}
}
?>