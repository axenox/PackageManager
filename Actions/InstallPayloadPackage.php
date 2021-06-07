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
use axenox\PackageManager\DataTypes\PackageDataType;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

class InstallPayloadPackage extends AbstractActionDeferred implements iCanBeCalledFromCLI {
    
    private $targetPackageNames = null;
    
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
        
        //read informations for all apps to install from the DB
        $ds = $this->getPackagesData($workbench, $packageNames);
        if ($ds->isEmpty()) {
            yield 'No installable packages selected!';
        }

        //create payload folder and copy composer.phar from base installation folder to payload folder
        $payloadPath = $filemanager->getPathToDataFolder() . DIRECTORY_SEPARATOR . "_payloadPackages";
        $composerTempPath = $payloadPath . DIRECTORY_SEPARATOR . '_composer';
        if (! is_dir($payloadPath)) {
            mkdir($payloadPath);
        }
        if (! is_file($payloadPath . DIRECTORY_SEPARATOR . 'composer.phar')) {
            if (! is_file( $filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar')) {
                yield "Can not install packages, no composer.phar file existing in '{$filemanager->getPathToBaseFolder()}'";
                return;
            }
            $filemanager->copyFile($filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar', $payloadPath . DIRECTORY_SEPARATOR . 'composer.phar');
        }
        if (! is_file($payloadPath . DIRECTORY_SEPARATOR . 'composer.phar')) {            
            yield "Can not install packages, composer.phar file could not be copied to '{$payloadPath}'";
            return;
        }
        
        //define environment variables for the command line composer calls
        $envVars = [];
        $envVars['COMPOSER_HOME'] = $composerTempPath;
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
                "preferred-install" => ["*" => "dist"]
            ]
        ];
        
        //check if composer.lock file exists, if not run composer with the basic composer.json
        //should only occur when payload packages are installed for the first time
        if (! file_exists($payloadPath . DIRECTORY_SEPARATOR . 'composer.lock')) {
            $filemanager->dumpFile($composerJsonPath, json_encode($basicComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $cmd = 'php composer.phar update';
            yield "Creating base composer.lock file..." . PHP_EOL;
            chdir($payloadPath);
            $process = Process::fromShellCommandline($cmd, null, $envVars, null, 600);
            $process->start();
            $buffer = '';
            foreach ($process as $msg) {$buffer .= $msg;
                // writing output for this command line disabled as it might confuse the user more than help him
                /*if (StringDataType::endsWith($msg, "\r", false) || StringDataType::endsWith($msg, "\n", false)) {
                    yield 'composer> ' . $this->escapeCliMessage($this->replaceFilePathsWithHyperlinks($buffer));
                    $buffer = '';
                }*/
            }
            if ($process->isSuccessful() === false) {
                yield 'Creating base composer.lock file failed, can not install packages!';
                //remove composer temporary folder so it doesnt interfere with later installations
                $filemanager->deleteDir($composerTempPath);
                $workbench->getCache()->clear();
                return;
            }
            yield 'Base composer.lock file created. Loading packages now.' . PHP_EOL;
            yield '--------------------------------' . PHP_EOL;
            chdir($baseDir);
        }
        
        //build composer.json
        if (file_exists($composerJsonPath)) {
            $json = file_get_contents($composerJsonPath);
            $composerJson = json_decode($json, true);
        } else {            
            $composerJson = json_decode(json_encode($basicComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), true);
        }        
        $appNames = [];
        foreach($ds->getRows() as $row) {           
            $url = $row['URL'];
            $gitlabDomains = [];
            $name = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $row['NAME']);
            $appNames[] = $name;
            $type = $row['TYPE'];
            switch ($type) {
                case PackageDataType::COMPOSER:
                case PackageDataType::PUPLISHED_PACKAGE:
                    $type = 'composer';
                    break;
                case PackageDataType::BITBUCKET:
                case PackageDataType::FOSSIL:
                case PackageDataType::GIT:
                case PackageDataType::GITHUB:
                case PackageDataType::GITLAB:
                    $gitlabDomains[] = $this->getDomainFromUrl($url);
                case PackageDataType::MERCURIAL:
                case PackageDataType::VCS:
                    $type = 'vcs';
                    break;
                default:
                    yield "Package type '{$type}' for package '{$name}' is not supported. Installation cancelled!";
                    return;
                    
            }
            $composerJson['require'][$name] = $row['VERSION'];
            $composerJson['repositories'][$name] = [
                "type" => $type,
                "url" => $url
            ];
            //for authentification via auth.json to work on gitlab hosted packages
            //the gitlab domains have to be added to the config
            $composerJson['config']['gitlab-domains'] = $gitlabDomains;
        }        
        $filemanager->dumpFile($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // run the composer to  download packages to payload vendor folder
        $cmd = 'php composer.phar update';
        foreach ($appNames as $name) {
            $cmd .= " {$name}";
        }
        yield "Loading packages via composer cli command '{$cmd}' ..." . PHP_EOL;
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
            yield 'Composer failed, no packages have been installed!';
            //remove composer temporary folder so it doesnt interfere with later installations
            $filemanager->deleteDir($composerTempPath);
            $workbench->getCache()->clear();
            return;
        }
        chdir($baseDir);
        
        //copy packages from payload vednor folder to normal vendor folder and install them
        yield 'Composer finished loading packages. Packages will be installed now.' . PHP_EOL;
        yield '--------------------------------' . PHP_EOL;
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
            yield  'No packages have been installed';
        }
        //remove composer temporary folder so it doesnt interfere with later installations
        $filemanager->deleteDir($composerTempPath);
        $workbench->getCache()->clear();
    }
    
    /**
     * Returns datasheet with all information from database for all given package names in array
     * 
     * @param WorkbenchInterface $workbench
     * @param array $packageNames
     * @return DataSheetInterface
     */
    protected function getPackagesData(WorkbenchInterface $workbench, array $packageNames) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.PackageManager.PAYLOAD_PACKAGES');
        $filters = ConditionGroupFactory::createForDataSheet($ds, EXF_LOGICAL_OR);
        foreach ($packageNames as $package) {
            $filters->addConditionFromString('NAME', $package, EXF_COMPARATOR_EQUALS);
        }
        $ds->getFilters()->addNestedGroup($filters);
        $ds->getColumns()->addMultiple(['URL', 'VERSION', 'TYPE', 'NAME']);
        $ds->dataRead();
        return $ds;
    }
    
    /**
     * Gets the domain from an url by removing `https://` or `http://` from the url and then cuts of everything after (and including) the first slash
     * 
     * @param string $url
     * @return string
     */
    protected function getDomainFromUrl(string $url) : string
    {
        if (StringDataType::startsWith($url, 'https://')) {
            $url = StringDataType::substringAfter($url, 'https://');
        } elseif (StringDataType::startsWith($url, 'http://')) {
            $url = StringDataType::substringAfter($url, 'http://');
        }
        $url = StringDataType::substringBefore($url, '/');
        return $url;
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
        if (empty($this->targetPackageNames) === false) {
            if (count($this->targetPackageNames) === 1 && ($this->targetPackageNames[0] === '*' || strcasecmp($this->targetPackageNames[0], 'all') === 0)) {
                $getAll === true;
            } else {
                return $this->targetPackageNames;
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
            $this->targetPackageNames = array_unique($input->getColumnValues('NAME', false));
        } else {
            throw new ActionInputInvalidObjectError($this, 'The action "' . $this->getAliasWithNamespace() . '" can only be called on the meta objects "axenox.PackageManager.PAYLOAD_PACKAGES" - "' . $input->getMetaObject()->getAliasWithNamespace() . '" given instead!');
        }
        
        return $this->targetPackageNames;
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
            $this->targetPackageNames = $values->toArray();
        } elseif (is_string($values)) {
            $this->targetPackageNames = array_map('trim', explode(',', $values));
        } elseif (is_array($values)) {
            $this->targetPackageNames = $values;
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