<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\CommonLogic\AbstractAction;
use axenox\PackageManager\StaticInstaller;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;

/**
 * This action cleans up all remains of previous composer actions if something went wrong.
 * Thus, if composer
 * exits with an exception, the temp downloaded apps do not get installed. This action will install them.
 *
 * @author Andrej Kabachnik
 *        
 */
class ComposerCleanupPreviousActions extends AbstractAction implements iModifyData
{

    /**
     *
     * {@inheritdoc}
     *
     * @see \axenox\PackageManager\Actions\AbstractComposerAction::init()
     */
    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::WRENCH);
    }

    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $installer = new StaticInstaller();
        $result = '';
        $result .= $installer::composerFinishInstall();
        $result .= $installer::composerFinishUpdate();
        return ResultFactory::createMessageResult($task, $result);
    }
}
?>