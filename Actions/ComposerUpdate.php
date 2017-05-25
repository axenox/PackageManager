<?php
namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;

/**
 * This action runs one or more selected test steps
 *
 * @author Andrej Kabachnik
 *        
 */
class ComposerUpdate extends AbstractComposerAction
{

    protected function init()
    {
        parent::init();
        $this->setIconName('repair');
    }

    protected function performComposerAction(ComposerAPI $composer)
    {
        return $composer->update();
    }
}
?>