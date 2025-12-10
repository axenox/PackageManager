<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\AppInstallers\DataInstaller;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Events\DataSheet\OnBeforeDeleteDataEvent;
use exface\Core\Factories\AppFactory;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\AppInstallerInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Events\Installer\OnBeforeAppUninstallEvent;
use exface\Core\Events\Installer\OnAppUninstallEvent;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * This action uninstalls one or more apps
 *
 * By default, this action will delete all data, that has explicit relations to this app - in particular also
 * master data with app relations (e.g. master data handled by the `DataInstaller`). While being technically
 * correct (if the data is associated with an app, it needs to be removed when the app is uninstalled), this
 * feature is known to cause problems on dev-installations, where apps can be installed and uninstalled for
 * testing purposes. In these cases, related data from other apps does not need to be removed. To keep
 * related data, simply set `keep_data_in_other_apps` to TRUE in the action config or in the respective CLI
 * option: `vendor/bin/action axenox.PackageManager:UninstallApp my.APP keep_data_in_other_apps=true`.
 *
 * @triggers \exface\Core\Events\Installer\OnBeforeAppUninstallEvent
 * @triggers \exface\Core\Events\Installer\OnAppUninstallEvent
 *
 * @author Andrej Kabachnik
 *
 */
class UninstallApp extends InstallApp
{
    const TASK_PARAM_KEEP_DATA_IN_OTHER_APPS = 'keep_data_in_other_apps';

    private $keepDataInOtherApps = false;

    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::UNINSTALL);
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [$this->getTargetAppAliases($task), $this->willKeepDataInOtherAppas($task)];
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $aliases = [], bool $keepDataInOtherApps = false) : \Generator
    {
        $installed_counter = 0;
        foreach ($aliases as $app_alias) {
            yield  PHP_EOL . "Uninstalling " . $app_alias . "..." . PHP_EOL;
            $keptDataOfObjects = [];
            $app_selector = new AppSelector($this->getWorkbench(), $app_alias);
            try {
                $installed_counter ++;

                if ($keepDataInOtherApps === true) {
                    $onDeleteFilterThisAppOnly = function(OnBeforeDeleteDataEvent $event) use ($app_alias, &$keptDataOfObjects) {
                        $eventSheet = $event->getDataSheet();
                        if (strcasecmp($eventSheet->getMetaObject()->getApp()->getAliasWithNamespace(), $app_alias) !== 0) {
                            $keptDataOfObjects[] = $eventSheet->getMetaObject()->__toString();
                            $event->preventDelete(true);
                        }
                    };
                    $this->getWorkbench()->eventManager()->addListener(OnBeforeDeleteDataEvent::class, $onDeleteFilterThisAppOnly);
                }

                $event = new OnBeforeAppUninstallEvent($app_selector);
                $this->getWorkbench()->eventManager()->dispatch($event);
                foreach ($event->getPreprocessors() as $proc) {
                    yield from $proc;
                }

                yield from $this->uninstallApp($app_selector);

                if (! empty($keptDataOfObjects)) {
                    yield 'Skipped deleting data from the following objects due to `keep_data_in_other_apps`:'
                        . implode(PHP_EOL . "  - ", $keptDataOfObjects)
                        . PHP_EOL;
                }

                if ($keepDataInOtherApps === true) {
                    $this->getWorkbench()->eventManager()->removeListener(OnBeforeAppUninstallEvent::class, $onDeleteFilterThisAppOnly);
                }

                $event = new OnAppUninstallEvent($app_selector);
                $this->getWorkbench()->eventManager()->dispatch($event);
                foreach ($event->getPostprocessors() as $proc) {
                    yield from $proc;
                }

                yield "..." . $app_alias . " successfully uninstalled." . PHP_EOL;
            } catch (\Exception $e) {
                $installed_counter --;
                $this->getWorkbench()->getLogger()->logException($e);
                yield "ERROR: " . ($e instanceof ExceptionInterface ? ' see log ID ' . $e->getId() : $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()) . PHP_EOL;
                yield "...$app_alias could not be uninstalled!" . PHP_EOL;
            }
        }

        if (count($aliases) == 0) {
            yield 'No uninstallable apps had been selected!';
        } elseif ($installed_counter == 0) {
            yield  'No apps have been uninstalled';
        }

        $this->getWorkbench()->getCache()->clear();
    }

    /**
     * @deprecated use installApp() instead!
     *
     * This method ensures backwards compatibility with older versions of the StaticInstaller, which
     * cannot work with generators. If updating from an old version of the package manager, the
     * StaticInstaller old StaticInstaller attempts to call newer versions of this action. This
     * is why the install() method still returns a string and ensures the generator is iterade over,
     * while newer versions of StaticInstaller call uninstallApp() as does the perform() method of the
     * action.
     *
     * @param AppSelectorInterface $app_selector
     * @return string
     */
    public function uninstall(AppSelectorInterface $app_selector) : string
    {
        $result = '';
        foreach ($this->uninstallApp($app_selector) as $msg) {
            $result .= $msg;
        }
        return $result;
    }

    /**
     *
     * @param AppSelectorInterface $app_selector
     * @return string
     */
    public function uninstallApp(AppSelectorInterface $app_selector, bool $cascading = true) : \Traversable
    {
        $app = AppFactory::create($app_selector);
        if ($cascading === false) {
            // For non-cascading uninstalls get a copy of the installer container with non-cascading
            // installers
            $installer = $app->getInstaller()->extract(function(AppInstallerInterface $inst){
                if ($inst instanceof DataInstaller) {
                    $inst->setUninstallCascading(false);
                }
                return true;
            });
        } else {
            $installer = $app->getInstaller();
        }
        $installer_result = $installer->uninstall();
        if ($installer_result instanceof \Traversable) {
            yield from $installer_result;
        } else {
            yield $installer_result . (substr($installer_result, - 1) != '.' ? '.' : '');
        }
    }

    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() : array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::TASK_PARAM_KEEP_DATA_IN_OTHER_APPS)
                ->setDescription('Do not use cascading delete on related data outside the app')
        ];
    }

    protected function willKeepDataInOtherAppas(TaskInterface $task) : bool
    {
        if ($task->hasParameter(self::TASK_PARAM_KEEP_DATA_IN_OTHER_APPS)) {
            return BooleanDataType::cast($task->getParameter(self::TASK_PARAM_KEEP_DATA_IN_OTHER_APPS));
        }

        return $this->keepDataInOtherApps;
    }

    /**
     * Set to TRUE to prevent cascading deletes for related data outside the explicitly uninstalled app
     *
     * @uxon-property keep_data_in_other_apps
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param bool $trueOrFalse
     * @return $this
     */
    public function setKeepDataInOtherApps(bool $trueOrFalse = true) : UninstallApp
    {
        $this->keepDataInOtherApps = $trueOrFalse;
        return $this;
    }
}