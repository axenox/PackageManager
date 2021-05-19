<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Generator;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use Symfony\Component\Process\Process;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Factories\ActionFactory;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;

class InstallPayloadPackage extends AbstractActionDeferred implements iCanBeCalledFromCLI {
    
    private $target_package_names = null;
    
    protected function init()
    {
        $this->setIcon(Icons::HDD_O);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }
    
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result): array
    {
        return [$this->getTargetAppAliases($task)];
    }

    protected function performDeferred(array $packageNames = []): \Generator
    {
        $workbench = $this->getWorkbench();
        $filemanager = $workbench->filemanager();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.PackageManager.PAYLOAD_PACKAGES');
        $filters = ConditionGroupFactory::createForDataSheet($ds, EXF_LOGICAL_OR);
        foreach ($packageNames as $package) {
            $filters->addConditionFromString('NAME', $package, EXF_COMPARATOR_EQUALS);
        }
        $ds->getFilters()->addNestedGroup($filters);
        $ds->getColumns()->addMultiple(['URL', 'VERSION', 'TYPE', 'NAME']);
        $ds->dataRead();
        if ($ds->isEmpty()) {
            yield 'No installable apps had been selected!';
        }        
        $path = $filemanager->getPathToDataFolder() . DIRECTORY_SEPARATOR . "_payloadPackages";
        if (!is_dir($path)) {
            mkdir($path);
        }
        $filepath = $path . DIRECTORY_SEPARATOR . 'composer.json';
        if (file_exists($filepath)) {
            $json = file_get_contents($filepath);
            $composerJson = json_decode($json, true);
        } else {
            $composerJson = [
                "require" => [],
                "replace" => ["exface/core" => "*"],
                "repositories" => [],
                "minimum-stability" => "dev",
                "prefer-stable"=> true,
                "config" => [
                    "secure-http"=> false,
                    "cache-dir"=> "/dev/null"
                ]
            ];
        }
        $appNames = [];
        foreach($ds->getRows() as $row) {
            if ($row['VERSION'] == null) {
                $version = 'dev-master';
            } else {
                $version = $row['VERSION'];
            }
            $name = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $row['NAME']);
            $appNames[] = $name;
            //TODO define type from row['TYPE'] value, possible DataType?
            $type = 'composer';
            $url = $row['URL'];
            $composerJson['require'][$name] = $version;
            $composerJson['repositories'][$name] = [
                "type" => $type,
                "url" => $url
            ];
            $path = $filemanager->getPathToDataFolder() . DIRECTORY_SEPARATOR . "_payloadPackages";
            if (!is_dir($path)) {
                mkdir($path);
            }
            $filemanager->dumpFile($filepath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }        
        copy($filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar', $path . DIRECTORY_SEPARATOR . 'composer.phar');
        // TODO
        $envVars = [];
        $envVars['COMPOSER_HOME'] = $path . DIRECTORY_SEPARATOR . '_composer';
        $oldDir = getcwd();
        $cmd = 'php composer.phar update';
        chdir($path);
        $process = Process::fromShellCommandline($cmd, null, $envVars, null, 600);
        $process->start();
        while ($process->isRunning()) {
            //wait for process to finish
        }
        chdir($oldDir);
        //yield $process->getOutput();
        $payloadVendorPath = $path . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $vendorPath = $filemanager->getPathToVendorFolder() . DIRECTORY_SEPARATOR;
        $installed_counter = 0;
        foreach ($appNames as $name) {
            $name = str_replace('/', DIRECTORY_SEPARATOR, $name);
            if (is_dir($payloadVendorPath . $name)) {
                $filemanager->deleteDir($vendorPath . $name);
                $filemanager->copyDir($payloadVendorPath . $name, $vendorPath . $name);
                $app_alias = str_replace(DIRECTORY_SEPARATOR, AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, $name);
                $app_selector = new AppSelector($this->getWorkbench(), $app_alias);
                $action = ActionFactory::createFromString($workbench, 'axenox.PackageManager.InstallApp');
                try {
                    $installed_counter ++;
                    yield from $action->installApp($app_selector);
                    yield "..." . $app_alias . " successfully installed." . PHP_EOL;
                } catch (\Exception $e) {
                    $installed_counter --;
                    $this->getWorkbench()->getLogger()->logException($e);
                    yield PHP_EOL . "ERROR: " . ($e instanceof ExceptionInterface ? $e->getMessage() . ' see log ID ' . $e->getId() : $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()) . PHP_EOL;
                    yield "...{$app_alias} installation failed!" . PHP_EOL;
                }
            }            
        }
        if ($installed_counter == 0) {
            yield  'No apps have been installed';
        }
        
        $this->getWorkbench()->getCache()->clear();
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
        if (empty($this->target_package_names) === false) {
            if (count($this->target_package_names) === 1 && ($this->target_package_names[0] === '*' || strcasecmp($this->target_package_names[0], 'all') === 0)) {
                $getAll === true;
            } else {
                return $this->target_package_names;
            }
        }
        
        try {
            $input = $this->getInputDataSheet($task);
        } catch (ActionInputMissingError $e) {
            if ($getAll) {
                $input = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.PackageManager.PAYLOAD_PACKAGES');
            } else {
                throw $e;
            }
        }
        
        if ($input->getMetaObject()->isExactly('axenox.PackageManager.PAYLOAD_PACKAGES')) {
            $input->getColumns()->addFromExpression('NAME');
            if (! $input->isEmpty()) {
                if (! $input->isFresh()) {
                    $input->dataRead();
                }
            } elseif ($getAll === true || ! $input->getFilters()->isEmpty()) {
                $input->dataRead();
            }
            $this->target_package_names = array_unique($input->getColumnValues('NAME', false));
        } else {
            throw new ActionInputInvalidObjectError($this, 'The action "' . $this->getAliasWithNamespace() . '" can only be called on the meta objects "axenox.PackageManager.PAYLOAD_PACKAGES" - "' . $input->getMetaObject()->getAliasWithNamespace() . '" given instead!');
        }
        
        return $this->target_package_names;
    }
    
    public function getCliArguments(): array
    {}

    public function getCliOptions(): array
    {}
}