<?php namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\CommonLogic\AbstractAction;
use axenox\PackageManager\StaticInstaller;

/**
 * This action cleans up all remains of previous composer actions if something went wrong. Thus, if composer
 * exits with an exception, the temp downloaded apps do not get installed. This action will install them.
 * 
 * @author Andrej Kabachnik
 *
 */
class ComposerCleanupPreviousActions extends AbstractAction implements iModifyData {
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\PackageManager\Actions\AbstractComposerAction::init()
	 */
	protected function init(){
		parent::init();
		$this->set_icon_name('repair');
	}	
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \axenox\PackageManager\Actions\AbstractComposerAction::perform()
	 */
	protected function perform(){
		$installer = new StaticInstaller();
		$result = '';
		$result .= $installer::composer_finish_install();
		$result .= $installer::composer_finish_update();
		$this->set_result_message($result);
	}
}
?>