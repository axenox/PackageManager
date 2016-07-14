<?php namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\AbstractAction;
use axenox\PackageManager\ComposerApi;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class ComposerUpdate extends AbstractAction {
	
	protected function init(){
		$this->set_icon_name('repair');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		$composer = new ComposerApi(
				$this->get_workbench()->get_installation_path() 
				. DIRECTORY_SEPARATOR . 'vendor'
				. DIRECTORY_SEPARATOR . 'bin'
				. DIRECTORY_SEPARATOR . 'composer');
		
		$output = $composer->update();
		$this->set_result_message($composer->dump_output($output));
		return;
	}
}
?>