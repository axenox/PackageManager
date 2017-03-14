<?php
namespace axenox\PackageManager;

use exface\Core\CommonLogic\AbstractApp;
use exface\Core\CommonLogic\AbstractAppInstaller;
use exface\Core\Exceptions\UnexpectedValueException;

class PackageManagerInstaller extends AbstractAppInstaller {
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractApp::install()
	 */
	public function install($source_absolute_path){
		$root_composer_json_path = $this->get_workbench()->filemanager()->get_path_to_base_folder() . DIRECTORY_SEPARATOR . 'composer.json';
		if (!file_exists($root_composer_json_path)){
			return "\n" . 'Root composer.json not found under "' . $root_composer_json_path . '" - automatic installation of apps will not work! See the package manager docs for solutions.';
		}
		
		try {
			$root_composer_json = $this->parse_composer_json($root_composer_json_path);
		} catch (\Throwable $e){
			return "\n" . $e->getMessage() . ' - automatic installation of apps will not work! See the package manager docs for solutions.';
		}
		
		$result = '';
		$changes = 0;
		
		if (!isset($root_composer_json['autoload']['psr-0']["axenox\\PackageManager"])){
			$root_composer_json['autoload']['psr-0']["axenox\\PackageManager"] = "vendor/";
			$changes++;
		}
		
		// Package install/update scripts
		if (!is_array($root_composer_json['scripts']['post-package-install']) || !in_array("axenox\\PackageManager\\StaticInstaller::composer_finish_package_install", $root_composer_json['scripts']['post-package-install'])){
			$root_composer_json['scripts']['post-package-install'][] = "axenox\\PackageManager\\StaticInstaller::composer_finish_package_install";
			$changes++;
		}
		if (!is_array($root_composer_json['scripts']['post-package-update']) || !in_array("axenox\\PackageManager\\StaticInstaller::composer_finish_package_update", $root_composer_json['scripts']['post-package-update'])){
			$root_composer_json['scripts']['post-package-update'][] = "axenox\\PackageManager\\StaticInstaller::composer_finish_package_update";
			$changes++;
		}
		
		// Overall install/update scripts
		if (!is_array($root_composer_json['scripts']['post-update-cmd']) || !in_array("axenox\\PackageManager\\StaticInstaller::composer_finish_update", $root_composer_json['scripts']['post-update-cmd'])){
			$root_composer_json['scripts']['post-update-cmd'][] = "axenox\\PackageManager\\StaticInstaller::composer_finish_update";
			$changes++;
		}
		if (!is_array($root_composer_json['scripts']['post-install-cmd']) || !in_array("axenox\\PackageManager\\StaticInstaller::composer_finish_install", $root_composer_json['scripts']['post-install-cmd'])){
			$root_composer_json['scripts']['post-install-cmd'][] = "axenox\\PackageManager\\StaticInstaller::composer_finish_install";
			$changes++;
		}
		
		if ($changes > 0){
			$this->get_workbench()->filemanager()->dumpFile($root_composer_json_path, json_encode($root_composer_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			$result .= "\n Configured root composer.json for automatic app installation";
		} else {
			$result .= "\n Checked root composer.json";
		}
		
		return $result;
	}
	
	public function update($source_absolute_path){
		return $this->install();
	}
	
	public function uninstall(){
		return 'Uninstall not implemented for' . $this->get_name_resolver()->get_alias_with_namespace() . '!'; 
	}
	
	public function backup($destination_absolute_path){
		return 'Backup not implemented for' . $this->get_na()->get_alias_with_namespace() . '!';
	}
	
	protected function parse_composer_json($root_composer_json_path){
		$root_composer_json = json_decode(file_get_contents($root_composer_json_path), true);
		if (!is_array($root_composer_json)){
			switch (json_last_error()) {
				case JSON_ERROR_NONE:
					$error = 'No errors';
					break;
				case JSON_ERROR_DEPTH:
					$error = 'Maximum stack depth exceeded';
					break;
				case JSON_ERROR_STATE_MISMATCH:
					$error = 'Underflow or the modes mismatch';
					break;
				case JSON_ERROR_CTRL_CHAR:
					$error = 'Unexpected control character found';
					break;
				case JSON_ERROR_SYNTAX:
					$error = 'Syntax error, malformed JSON';
					break;
				case JSON_ERROR_UTF8:
					$error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
					break;
				default:
					$error = 'Unknown error';
					break;
			}
			throw new UnexpectedValueException('Cannot parse root composer.json under "' . $root_composer_json_path . '": ' . $error);
		}
		return $root_composer_json;
	}
}
?>