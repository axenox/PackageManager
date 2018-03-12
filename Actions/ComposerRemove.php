<?php
namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;
use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * This action runs one or more selected test steps
 *
 * @author Andrej Kabachnik
 *        
 */
class ComposerRemove extends AbstractComposerAction implements iModifyData
{

    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::UNINSTALL);
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \axenox\PackageManager\Actions\AbstractComposerAction::performComposerAction()
     */
    protected function performComposerAction(ComposerAPI $composer, TaskInterface $task)
    {
        $input = $this->getInputDataPreset($task);
        
        if (! $input->getMetaObject()->isExactly('axenox.PackageManager.PACKAGE_INSTALLED')) {
            throw new ActionInputInvalidObjectError($this, 'Wrong input object for action "' . $this->getAliasWithNamespace() . '" - "' . $input->getMetaObject()->getAliasWithNamespace() . '"! This action requires input data based based on "axenox.PackageManager.PACKAGE_INSTALLED"!', '6T5TRFE');
        }
        
        $packages = array();
        foreach ($input->getRows() as $nr => $row) {
            if (! isset($row['name']) || ! $row['name']) {
                throw new ActionInputMissingError($this, 'Missing package name in row ' . $nr . ' of input data for action "' . $this->getAliasWithNamespace() . '"!', '6T5TRR1');
            }
            $packages[] = $row['name'];
        }
        
        return $composer->remove($packages);
    }
}
?>