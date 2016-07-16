<?php namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class ComposerShow extends AbstractComposerAction {
	
	protected function init(){
		$this->set_icon_name('info');
	}	
	
	protected function perform_composer_action(ComposerAPI $composer){
		return $composer->show();
	}
	
}
?>