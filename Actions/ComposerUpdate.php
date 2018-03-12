<?php
namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;

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
        $this->setIcon(Icons::WRENCH);
    }

    protected function performComposerAction(ComposerAPI $composer, TaskInterface $task)
    {
        return $composer->update();
    }
}
?>