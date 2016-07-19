<?php namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class ComposerShow extends AbstractComposerAction {
	
	private $show_latest_versions = false;
	
	protected function init(){
		parent::init();
		$this->set_icon_name('info');
	}	
	
	protected function perform_composer_action(ComposerAPI $composer){
		$options = array();
		if ($this->get_show_latest_versions()){
			$options[] = '--latest';
		}
		return $composer->show($options);
	}
	
	public function get_show_latest_versions() {
		return $this->show_latest_versions;
	}
	
	public function set_show_latest_versions($value) {
		$this->show_latest_versions = $value;
		return $this;
	}  
	
}
?>