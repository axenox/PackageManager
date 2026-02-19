<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Log\Processors\DebugWidgetProcessor;
use exface\Core\DataTypes\MessageTypeDataType;
use exface\Core\Factories\AppFactory;
use exface\Core\Exceptions\DirectoryNotFoundError;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\Events\Installer\OnBeforeAppInstallEvent;
use exface\Core\Events\Installer\OnAppInstallEvent;
use exface\Core\Interfaces\WidgetInterface;

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
 * @triggers \exface\Core\Events\Installer\OnBeforeAppInstallEvent
 * @triggers \exface\Core\Events\Installer\OnAppInstallEvent
 *
 * @method \axenox\PackageManager\PackageManagerApp getApp()
 *        
 * @author Andrej Kabachnik
 *        
 */
class InstallApp extends AbstractActionDeferred implements iCanBeCalledFromCLI, iModifyData
{
    private const COL_CLI_OUTPUT = 'CLI_OUTPUT';
    private const COL_ERROR_LOG_ID = 'ERROR_LOG_ID';
    private const COL_ERROR_MESSAGE = 'ERROR_MESSAGE';
    private const COL_ERROR_DEBUG_WIDGET = 'ERROR_DEBUG_WIDGET';
    private const COL_APP_OID = 'APP_OID';
    private const COL_MESSAGE_TYPE = 'MESSAGE_TYPE';
    
    private $target_app_aliases = [];
    
    protected array $outputLog = [];
    
    private DebugWidgetProcessor $debugWidgetProcessor;

    public function __construct(AppInterface $app, WidgetInterface $trigger_widget = null)
    {
        parent::__construct($app, $trigger_widget);
        
        $this->debugWidgetProcessor = new DebugWidgetProcessor(
            $this->getWorkbench(),
            'sender',
            'message'
        );
    }


    protected function init()
    {
        $this->setIcon(Icons::WRENCH);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [$this->getTargetAppAliases($task)];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $aliases = []) : \Generator
    {
        $installed_counter = 0;

        foreach ($aliases as $app_alias) {
            $this->resetOutputLog();
            $app_selector = new AppSelector($this->getWorkbench(), $app_alias);
            
            yield $this->logOutputLine(PHP_EOL . "Installing " . $app_alias . "..." . PHP_EOL);

            try {
                $installed_counter ++;
                foreach ($this->installApp($app_selector) as $result) {
                    if(is_string($result)) {
                        yield $this->logOutputLine($result);
                    } else {
                        yield $result;
                    }
                }
                
                yield $this->logOutputLine("..." . $app_alias . " successfully installed." . PHP_EOL);
                yield $this->commitOutputLog($app_selector);
            } catch (\Exception $e) {
                $installed_counter --;
                $this->getWorkbench()->getLogger()->logException($e);

                yield $this->logOutputLine(PHP_EOL . "ERROR: " . ($e instanceof ExceptionInterface ? 
                        $e->getMessage() . ' see log ID ' . $e->getId() : 
                        $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()) . PHP_EOL
                );
                yield $this->logOutputLine("...{$app_alias} installation failed!" . PHP_EOL);
                yield $this->commitOutputLog($app_selector, $e);
            }
        }
        
        if (count($aliases) == 0) {
            yield $this->logOutputLine('No installable apps had been selected!');
        } elseif ($installed_counter == 0) {
            yield $this->logOutputLine('No apps have been installed');
        }
        
        $this->getWorkbench()->getCache()->clear();
    }
    
    protected function resetOutputLog() : array
    {
        $this->outputLog = [];
        return $this->outputLog;
    }
    
    protected function logOutputLine(string $output) : string
    {
        $this->outputLog[] = $output;
        return $output;
    }
    
    protected function commitOutputLog(
        AppSelectorInterface $appSelector, 
        \Throwable $exception = null
    ) : string
    {
        if(empty($this->outputLog)) {
            return '';
        }

        try {
            $dataSheet = DataSheetFactory::createFromObjectIdOrAlias(
                $this->getWorkbench(),
                'axenox.PackageManager.APP_INSTALL_LOG'
            );
            
            $cliOutput = '';
            $errorText = '';
            $errorLogId = null;
            $debugWidget = null;
            
            // Get error data from exception, if possible.
            if($exception !== null) {
                $errorText = $exception->getMessage();
                if($exception instanceof ExceptionInterface) {
                    $errorLogId = $exception->getLogId();
                    $debugWidget = call_user_func($this->debugWidgetProcessor, ['context' => ['sender' => $exception]]);
                    $debugWidget = $debugWidget['message'];
                }
            } 
            
            foreach ($this->outputLog as $outputLine) {
                $cliOutput .= $outputLine;
                if($exception === null && str_starts_with(trim($outputLine), 'ERROR')) {
                    $errorText .= $outputLine . (str_ends_with($outputLine, PHP_EOL) ? '' : PHP_EOL);
                }
            }


            $dataSheet->addRow([
                self::COL_CLI_OUTPUT => $cliOutput,
                self::COL_ERROR_LOG_ID => $errorLogId,
                self::COL_ERROR_MESSAGE => $errorText,
                self::COL_ERROR_DEBUG_WIDGET => $debugWidget,
                self::COL_APP_OID => AppFactory::create($appSelector)->getUid(),
                self::COL_MESSAGE_TYPE => empty($errorText) ? MessageTypeDataType::SUCCESS : MessageTypeDataType::ERROR
            ]);
            
            $dataSheet->dataCreate();
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            $logId = $e instanceof ExceptionInterface ? ' See Log-ID ' . $e->getLogId() : '';
            return PHP_EOL . 'WARNING: Could not save installation log! ' . $e->getMessage() . $logId . PHP_EOL;
        }

        return '';
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
            if (count($this->target_app_aliases) === 1 && ($this->target_app_aliases[0] === '*' || strcasecmp($this->target_app_aliases[0], 'all') === 0)) {
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
        $installer = $app->getInstaller();
        $path = $this->getAppAbsolutePath($app_selector);
        
        $event = new OnBeforeAppInstallEvent($app_selector, $path);
        $this->getWorkbench()->eventManager()->dispatch($event);
        foreach ($event->getPreprocessors() as $proc) {
            yield from $proc;
        }
        
        $installer_result = $installer->install($path);
        if ($installer_result instanceof \Traversable) {
            yield from $installer_result;
        } else {
            yield $installer_result . (substr($installer_result, - 1) != '.' ? '.' : '');
        
        }
        
        $event = new OnAppInstallEvent($app_selector, $path);
        $this->getWorkbench()->eventManager()->dispatch($event);
        foreach ($event->getPostprocessors() as $proc) {
            yield from $proc;
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
        $vendor_folder = $this->getWorkbench()->filemanager()->getPathToVendorFolder();
        $app_path = $vendor_folder . DIRECTORY_SEPARATOR;
        $app_path .= $this->getWorkbench()->getAppFolder($app_selector);
        if (! is_dir($app_path)) {
            if (! is_dir($vendor_folder)) {
                throw new DirectoryNotFoundError('Vendor folder not found in "' . $vendor_folder . '"!', '6T5TZN5');
            }
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