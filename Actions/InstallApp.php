<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Factories\AppFactory;
use exface\Core\Exceptions\DirectoryNotFoundError;
use axenox\PackageManager\MetaModelInstaller;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\CommonLogic\Tasks\ResultMessage;

/**
 * This action installs one or more apps including their meta model, custom installer, etc.
 *
 * @method PackageManagerApp getApp()
 *        
 * @author Andrej Kabachnik
 *        
 */
class InstallApp extends AbstractAction
{

    private $target_app_aliases = [];

    protected function init()
    {
        $this->setIcon(Icons::WRENCH);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $exface = $this->getWorkbench();
        $installed_counter = 0;
        $message = '';
        
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $message .= "Installing " . $app_alias . "...\n";
            $app_selector = new AppSelector($exface, $app_alias);
            try {
                $installed_counter ++;
                $message .= $this->install($app_selector);
                $message .= "\n" . $app_alias . " successfully installed.\n";
            } catch (\Exception $e) {
                $installed_counter --;
                $this->getWorkbench()->getLogger()->logException($e);
                $message .= "\n" . $app_alias . " could not be installed" . ($e instanceof ExceptionInterface ? ' (see log ID ' . $e->getId() . ')' : '') . ".\n";
            }
        }
        
        if (count($this->getTargetAppAliases()) == 0) {
            $message .= 'No installable apps had been selected!';
        } elseif ($installed_counter == 0) {
            $message .= 'No apps have been installed';
        }
        
        $this->getWorkbench()->clearCache();
        
        return new ResultMessage($task, $message);
    }

    /**
     * 
     * @param TaskInterface $task
     * @throws ActionInputInvalidObjectError
     * @return string[]
     */
    protected function getTargetAppAliases(TaskInterface $task) : array
    {
        $input = $this->getInputDataSheet($task);
        if (empty($this->target_app_aliases) && $input) {
            
            if ($input->getMetaObject()->isExactly('exface.Core.APP')) {
                $input->getColumns()->addFromExpression('ALIAS');
                if (! $input->isEmpty()) {
                    if (! $input->isFresh()) {
                        $input->dataRead();
                    }
                } elseif (! $input->getFilters()->isEmpty()) {
                    $input->dataRead();
                }
                $this->target_app_aliases = array_unique($input->getColumnValues('ALIAS', false));
            } elseif ($input->getMetaObject()->isExactly('axenox.PackageManager.PACKAGE_INSTALLED')) {
                $input->getColumns()->addFromExpression('app_alias');
                if (! $input->isEmpty()) {
                    if (! $input->isFresh()) {
                        $input->dataRead();
                    }
                } elseif (! $input->getFilters()->isEmpty()) {
                    $input->dataRead();
                }
                $this->target_app_aliases = array_filter(array_unique($input->getColumnValues('app_alias', false)));
            } else {
                throw new ActionInputInvalidObjectError($this, 'The action "' . $this->getAliasWithNamespace() . '" can only be called on the meta objects "exface.Core.App" or "axenox.PackageManager.PACKAGE_INSTALLED" - "' . $input->getMetaObject()->getAliasWithNamespace() . '" given instead!');
            }
        }
        
        return $this->target_app_aliases;
    }

    /**
     * 
     * @param array $values
     * @return \axenox\PackageManager\Actions\InstallApp
     */
    public function setTargetAppAliases(array $values)
    {
        $this->target_app_aliases = $values;
        return $this;
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @return string
     */
    public function install(AppSelectorInterface $app_selector) : string
    {
        $result = '';
        
        $app = AppFactory::create($app_selector);
        $installer = $app->getInstaller(new MetaModelInstaller($app_selector));
        $installer_result = $installer->install($this->getAppAbsolutePath($app_selector));
        $result .= $installer_result . (substr($installer_result, - 1) != '.' ? '.' : '');
        
        // Save the result
        return $result;
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @throws DirectoryNotFoundError
     * @return string
     */
    protected function getAppAbsolutePath(AppSelectorInterface $app_selector) : string
    {
        $app_path = $app_selector->getFolderAbsolute();
        if (! file_exists($app_path) || ! is_dir($app_path)) {
            throw new DirectoryNotFoundError('"' . $app_path . '" does not point to an installable app!', '6T5TZN5');
        }
        return $app_path;
    }
}
?>