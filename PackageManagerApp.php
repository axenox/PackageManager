<?php
namespace axenox\PackageManager;

class PackageManagerApp extends \exface\Core\CommonLogic\AbstractApp {
	
	const FOLDER_NAME_MODEL = 'Model';
	
	public function filemanager(){
		return $this->get_workbench()->filemanager();
	}
	
}
?>