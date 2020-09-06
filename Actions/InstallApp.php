<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\AbstractActionDeferred;
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
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\Tasks\ResultMessageStream;

/**
 * Installs/updates one or more apps including their meta model, custom installer, etc.
 * 
 * **NOTE:** Any changes made to the model in the current installation, that were not previously
 * exported via `ExportAppModel` will be overwritten by this action!
 * 
 * The action requires one or more apps to be explicitly selected for installing. This can
 * be either done 
 * 
 * - by passing input data based on `exface.Core.APP` or `axenox.PackageManager.PACKAGE_INSTALLED`
 * - or passing a comma-separated list of action aliases via the `apps` parameter
 * - or by specifying a static alias list in the config of the action via `target_app_aliases`
 * 
 * ## Command line usage
 * 
 * Repair all apps currently installed
 * 
 * ```
 * vendor/bin/action axenox.packagemanager:installapp *
 * 
 * ```
 * 
 * Install/repair selected apps (new apps will be installed, those alread installed - updated)
 * 
 * ```
 * vendor/bin/action axenox.packagemanager:installapp exface.Core,axenox.PackageManager
 * 
 * ```
 *
 * @method PackageManagerApp getApp()
 *        
 * @author Andrej Kabachnik
 *        
 */
class InstallApp extends AbstractActionDeferred implements iCanBeCalledFromCLI
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
        $aliases = $this->getTargetAppAliases($task);
        $result = new ResultMessageStream($task);
        
        $generator = function() use ($aliases, $exface, $result, $transaction) {
            $installed_counter = 0;
            
            foreach ($aliases as $app_alias) {
                yield  PHP_EOL . "Installing " . $app_alias . "..." . PHP_EOL;
                $app_selector = new AppSelector($exface, $app_alias);
                try {
                    $installed_counter ++;
                    yield from $this->installApp($app_selector);
                    yield "..." . $app_alias . " successfully installed." . PHP_EOL;
                } catch (\Exception $e) {
                    $installed_counter --;
                    $this->getWorkbench()->getLogger()->logException($e);
                    yield "ERROR: " . ($e instanceof ExceptionInterface ? ' see log ID ' . $e->getId() : $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()) . PHP_EOL;
                    yield "...$app_alias installation failed!" . PHP_EOL;
                }
            }
            
            if (count($aliases) == 0) {
                yield 'No installable apps had been selected!';
            } elseif ($installed_counter == 0) {
                yield  'No apps have been installed';
            }
            
            $this->getWorkbench()->getCache()->clear();
            
            // Trigger regular action post-processing as required by AbstractActionDeferred.
            $this->performAfterDeferred($result, $transaction);
        };
        
        $result->setMessageStreamGenerator($generator);
        return $result;
    }

    /**
     * 
     * @param TaskInterface $task
     * @throws ActionInputInvalidObjectError
     * @return string[]
     */
    protected function getTargetAppAliases(TaskInterface $task) : array
    {
        if ($task->hasParameter('apps')) {
            $this->setTargetAppAliases($task->getParameter('apps'));
        }
        
        $getAll = false;
        if (empty($this->target_app_aliases) === false) {
            if (count($this->target_app_aliases) === 1 && $this->target_app_aliases[0] === '*') {
                $getAll === true;
            } else {
                return $this->target_app_aliases;
            }
        }
        
        try {
            $input = $this->getInputDataSheet($task);
        } catch (ActionInputMissingError $e) {
            if ($getAll) {
                $input = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.APP');
            } else {
                throw $e;
            }
        }
            
        if ($input->getMetaObject()->isExactly('exface.Core.APP')) {
            $input->getColumns()->addFromExpression('ALIAS');
            if (! $input->isEmpty()) {
                if (! $input->isFresh()) {
                    $input->dataRead();
                }
            } elseif ($getAll === true || ! $input->getFilters()->isEmpty()) {
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
        
        return $this->target_app_aliases;
    }

    /**
     * Force to work with these apps instead of searching them in the input data.
     * 
     * @uxon-property target_app_aliases
     * @uxon-type metamodel:app[]
     * @uxon-template [""]
     * 
     * @param string|array|UxonObject $values
     * @return \axenox\PackageManager\Actions\InstallApp
     */
    public function setTargetAppAliases($values)
    {
        if ($values instanceof UxonObject) {
            $this->target_app_aliases = $values->toArray();
        } elseif (is_string($values)) {
            $this->target_app_aliases = array_map('trim', explode(',', $values));
        } elseif (is_array($values)) {
            $this->target_app_aliases = $values;
        } else {
            throw new ActionConfigurationError($this, 'Invalid value for property "target_app_aliases" of action ' . $this->getAliasWithNamespace() . ': "' . $values . '". Expecting string, array or UXON');
        }
        return $this;
    }
    
    /**
     * @deprecated use installApp() instead!
     * 
     * This method ensures backwards compatibility with older versions of the StaticInstaller, which
     * cannot work with generators. If updating from an old version of the package manager, the
     * StaticInstaller old StaticInstaller attempts to call newer versions of this action. This
     * is why the install() method still returns a string and ensures the generator is iterade over,
     * while newer versions of StaticInstaller call installApp() as does the perform() method of the
     * action.
     * 
     * @param AppSelectorInterface $app_selector
     * @return string
     */
    public function install(AppSelectorInterface $app_selector) : string
    {
        $result = '';
        foreach ($this->installApp($app_selector) as $msg) {
            $result .= $msg;
        }
        return $result;
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @return string
     */
    public function installApp(AppSelectorInterface $app_selector) : \Traversable
    {
        $app = AppFactory::create($app_selector);
        $installer = $app->getInstaller(new MetaModelInstaller($app_selector));
        $installer_result = $installer->install($this->getAppAbsolutePath($app_selector));
        if ($installer_result instanceof \Traversable) {
            yield from $installer_result;
        } else {
            yield $installer_result . (substr($installer_result, - 1) != '.' ? '.' : '');
        
        }
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @throws DirectoryNotFoundError
     * @return string
     */
    protected function getAppAbsolutePath(AppSelectorInterface $app_selector) : string
    {
        $app_path = $this->getWorkbench()->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR;
        $app_path .= $this->getWorkbench()->getAppFolder($app_selector);
        if (! file_exists($app_path) || ! is_dir($app_path)) {
            throw new DirectoryNotFoundError('"' . $app_path . '" does not point to an installable app!', '6T5TZN5');
        }
        return $app_path;
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments() : array
    {
        return [
            (new ServiceParameter($this))->setName('apps')->setDescription('Comma-separated list of app aliases to install/update. Use * for all apps.')
        ];
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
   public function getCliOptions() : array
   {
       return [];
   }
}