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
use exface\Core\Facades\HttpFileServerFacade;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\StringDataType;

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
            yield 'No installable apps selected!';
        }        
        $payloadPath = $filemanager->getPathToDataFolder() . DIRECTORY_SEPARATOR . "_payloadPackages";
        if (! is_dir($payloadPath)) {
            mkdir($payloadPath);
        }
        if (! is_file($payloadPath . DIRECTORY_SEPARATOR . 'composer.phar')) {
            if (! is_file( $filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar')) {
                yield "Can not install apps, no composer-phar file existing in '{$filemanager->getPathToBaseFolder()}'";
                return;
            }
            $filemanager->copyFile($filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar', $payloadPath . DIRECTORY_SEPARATOR . 'composer.phar');
        }
        $envVars = [];
        $envVars['COMPOSER_HOME'] = $payloadPath . DIRECTORY_SEPARATOR . '_composer';
        $baseDir = getcwd();
        $composerJsonPath = $payloadPath . DIRECTORY_SEPARATOR . 'composer.json';
        $basicComposerJson = [
            "require" => (object)[],
            "replace" => ["exface/core" => "*"],
            "repositories" => (object)[],
            "minimum-stability" => "dev",
            "prefer-stable"=> true,
            "config" => [
                "secure-http"=> false,
                "cache-dir"=> "/dev/null"
            ]
        ];
        //check if composer.lock file exists, if not run composer with the basic composer.json
        //should only occur when payload packages are installed for the first time
        if (! file_exists($payloadPath . DIRECTORY_SEPARATOR . 'composer.lock')) {
            $filemanager->dumpFile($composerJsonPath, json_encode($basicComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $cmd = 'php composer.phar update';
            yield "Creating base composer.lock file" . PHP_EOL;
            chdir($payloadPath);
            $process = Process::fromShellCommandline($cmd, null, $envVars, null, 600);
            $process->start();
            $buffer = '';
            foreach ($process as $msg) {
                $buffer .= $msg;
                if (StringDataType::endsWith($msg, "\r", false) || StringDataType::endsWith($msg, "\n", false)) {
                    yield 'composer> ' . $this->escapeCliMessage($this->replaceFilePathsWithHyperlinks($buffer));
                    $buffer = '';
                }
            }
            if ($process->isSuccessful() === false) {
                yield 'Creating base composer.lock fiel failed, can not install packages';
                $this->getWorkbench()->getCache()->clear();
                return;
            }
            yield 'Base composer.lock file created. Installing packages now' . PHP_EOL;
            yield '--------------------------------' . PHP_EOL;
            chdir($baseDir);
        }
        if (file_exists($composerJsonPath)) {
            $json = file_get_contents($composerJsonPath);
            $composerJson = json_decode($json, true);
        } else {            
            $composerJson = $basicComposerJson;
            $baseDir = getcwd();
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
            $filemanager->dumpFile($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        // TODO        
        
        $cmd = 'php composer.phar update';
        foreach ($appNames as $name) {
            $cmd .= " {$name}";
        }
        yield "Calling cli command '{$cmd}'";
        chdir($payloadPath);
        $process = Process::fromShellCommandline($cmd, null, $envVars, null, 600);
        $process->start();
        /*while ($process->isRunning()) {
            //wait for process to finish
        }*/
        $buffer = '';
        foreach ($process as $msg) {
            $buffer .= $msg;
            if (StringDataType::endsWith($msg, "\r", false) || StringDataType::endsWith($msg, "\n", false)) {
                yield 'composer> ' . $this->escapeCliMessage($this->replaceFilePathsWithHyperlinks($buffer));
                $buffer = '';
            }
        }
        if ($process->isSuccessful() === false) {
            yield 'Composer failed, no apps have been installed';
            $this->getWorkbench()->getCache()->clear();
            return;
        }
        chdir($baseDir);
        //yield $process->getOutput();
        $payloadVendorPath = $payloadPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
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
                    yield "Installing " . $app_alias . '...' . PHP_EOL;
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
        if ($task->hasParameter('packages')) {
            $this->setTargetPackages($task->getParameter('packages'));
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
    
    /**
     * Force to work with these apps instead of searching them in the input data.
     *
     * @uxon-property target_package_names
     * @uxon-type metamodel:payload_packages[]
     * @uxon-template [""]
     *
     * @param string|array|UxonObject $values
     * @return \axenox\PackageManager\Actions\InstallPayloadPackage
     */
    public function setTargetPackages($values)
    {
        if ($values instanceof UxonObject) {
            $this->target_package_names = $values->toArray();
        } elseif (is_string($values)) {
            $this->target_package_names = array_map('trim', explode(',', $values));
        } elseif (is_array($values)) {
            $this->target_package_names = $values;
        } else {
            throw new ActionConfigurationError($this, 'Invalid value for property "target_package_names" of action ' . $this->getAliasWithNamespace() . ': "' . $values . '". Expecting string, array or UXON');
        }
        return $this;
    }
    
    /**
     * 
     * @param string $msg
     * @return string
     */
    protected function escapeCliMessage(string $msg) : string
    {
        // TODO handle strange empty spaces in composer output
        return str_replace(["\r", "\n"], PHP_EOL, $msg);
    }
    
    /**
     * Replaces C:\... with http://... links for files within the project folder
     *
     * @param string $msg
     * @return string
     */
    protected function replaceFilePathsWithHyperlinks(string $msg) : string
    {
        $basePath = $this->getWorkbench()->filemanager()->getPathToBaseFolder() . DIRECTORY_SEPARATOR;
        $urlMatches = [];
        if (preg_match_all('/' . preg_quote($basePath, '/') . '[^ "]*/', $msg, $urlMatches) !== false) {
            foreach ($urlMatches[0] as $urlPath) {
                $url = HttpFileServerFacade::buildUrlForDownload($this->getWorkbench(), $urlPath, false);
                $msg = str_replace($urlPath, $url, $msg);
            }
        }
        return $msg;
    }
    
    public function getCliArguments(): array
    {
        return [
            (new ServiceParameter($this))->setName('packages')->setDescription('Comma-separated list of payload package names to install/update. Use * for all payload packages.')
        ];
    }

    public function getCliOptions(): array
    {
        return [];
    }
}