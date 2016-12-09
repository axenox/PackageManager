<?php namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;
use exface\Core\Exceptions\ActionRuntimeException;
use exface\Core\Interfaces\Actions\iModifyData;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class ComposerRemove extends AbstractComposerAction implements iModifyData {
	
	protected function init(){
		parent::init();
		$this->set_icon_name('uninstall');
	}	
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\PackageManager\Actions\AbstractComposerAction::perform_composer_action()
	 */
	protected function perform_composer_action(ComposerAPI $composer){
		if (!$this->get_input_data_sheet()->get_meta_object()->is_exactly('axenox.PackageManager.PACKAGE_INSTALLED')){
			throw new ActionRuntimeException('Wrong input object for action "' . $this->get_alias_with_namespace() . '" - "' . $this->get_input_data_sheet()->get_meta_object()->get_alias_with_namespace() . '"! This action requires input data based based on "axenox.PackageManager.PACKAGE_INSTALLED"!');
		}
				
		$packages = array();
		foreach ($this->get_input_data_sheet()->get_rows() as $nr => $row){
			if (!isset($row['name']) || !$row['name']){
				throw new ActionRuntimeException('Missing package name in row ' . $nr . ' of input data for action "' . $this->get_alias_with_namespace() . '"!');
			}
			$packages[] = $row['name'];
		}
		
		return $composer->remove($packages);
	}
	
}
?>