<?php namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class ComposerUpdate extends AbstractComposerAction {
	
	protected function init(){
		parent::init();
		$this->set_icon_name('repair');
	}	
	
	protected function perform_composer_action(ComposerAPI $composer){
		return $composer->update();
	}

}
?>