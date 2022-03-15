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
use axenox\PackageManager\DataTypes\RepoTypeDataType;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
/**
 * Action to install a package from another system or another source composer can handle.
 * IMPORTANT: Right now dependencies of a package will not be installed!
 * 
 * @author ralf.mulansky
 *
 */

class InstallPayload extends AbstractActionDeferred implements iCanBeCalledFromCLI {
    
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
        $config = $this->getApp()->getConfig();
        $filemanager = $workbench->filemanager();
        
        yield 'Setting up basic installation requirements...' . PHP_EOL;
        //create payload folder and copy composer.phar from base installation folder to payload folder
        $payloadPath = $filemanager->getPathToDataFolder() . DIRECTORY_SEPARATOR . $this->getApp()->getConfig()->getOption('PAYLOAD.INSTALLED_DATA_FOLDER_NAME');
        $composerTempPath = $payloadPath . DIRECTORY_SEPARATOR . '.composer';
        if (! is_dir($payloadPath)) {
            mkdir($payloadPath);
        }
        if (! is_file($payloadPath . DIRECTORY_SEPARATOR . 'composer.phar')) {
            if (! is_file( $filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar')) {
                yield "Can not set up basic installation requirements, no composer.phar file existing in '{$filemanager->getPathToBaseFolder()}'!" . PHP_EOL;
                return;
            }
            $filemanager->copyFile($filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar', $payloadPath . DIRECTORY_SEPARATOR . 'composer.phar');
        }
        if (! is_file($payloadPath . DIRECTORY_SEPARATOR . 'composer.phar')) {
            yield "Can not set up basic installation requirements, composer.phar file could not be copied to '{$payloadPath}'!" . PHP_EOL;
            return;
        }
        if (is_dir($composerTempPath)) {
            $filemanager->deleteDir($composerTempPath);
        }
        
        //create base composer.json in payload folder
        $composerJsonPath = $payloadPath . DIRECTORY_SEPARATOR . 'composer.json';
        $basicComposerJson = [
            "require" => (object)[],
            "replace" => ["exface/core" => "*"],
            "repositories" => [],
            "minimum-stability" => "dev",
            "prefer-stable"=> true,
            "config" => [
                "secure-http"=> false,
                "preferred-install" => ["*" => "dist"]
            ]
        ];
        if ($config->getOption('PAYLOAD.COMPOSER_USE_PACKAGIST') === false) {
            $basicComposerJson['repositories']['packagist'] = ['packagist.org' => false];
        }
        if ($config->getOption('PAYLOAD.COMPOSER_USE_ASSET_PACKAGIST') === true) {
            $basicComposerJson['repositories']["asset-packagist"] = [
                "type" => "composer",
                "url" => "https://asset-packagist.org"
            ];
        }
        
        if (!is_file($composerJsonPath)) {
            $filemanager->dumpFile($composerJsonPath, json_encode($basicComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }        
        yield 'Basic installation requirements are set up!' . PHP_EOL;
        yield $this->printLineDelimiter();
        
        if (! empty($packageNames)) {
            //read informations for all apps to install from the DB
            $ds = $this->getPackagesData($workbench);   
        }
        if (! $ds || $ds->isEmpty()) {
            yield "No packages to install/update specified". PHP_EOL;
            yield $this->printLineDelimiter();
            yield "To install a package call the action with a package name added to it as parameter like:". PHP_EOL;
            yield "'action axenox.PackageManager:InstallPayload powerui/test'" . PHP_EOL;
            yield "" . PHP_EOL;
            yield "To add authentification for a domain call the command" . PHP_EOL;
            yield "'php composer.phar config -a <Authentification Type>.<Domain> <Credentials>': e.g." . PHP_EOL;
            yield "'php composer.phar config -a http-basic.localhost username password'" . PHP_EOL;
            yield "'php composer.phar config -a gitlab-token.mygitsrv.mycompany.com accesstoken'" . PHP_EOL;
            yield "Credentials can be a username and password (seperated by a space) or a single API token." . PHP_EOL;
            return;
        }
        
        //define environment variables for the command line composer calls
        $envVars = [];
        $envVars['COMPOSER_HOME'] = $composerTempPath;
        $baseDir = getcwd();        
        
        //check if composer.lock file exists, if not run composer with the basic composer.json
        //should only occur when payload packages are installed for the first time
        if (! is_file($payloadPath . DIRECTORY_SEPARATOR . 'composer.lock')) {
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
            yield $this->printLineDelimiter();
            chdir($baseDir);
        }
        
        //build composer.json
        $composerJson = json_decode(json_encode($basicComposerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), true);
        foreach($ds->getRows() as $row) {           
            $url = $row['URL'];
            $gitlabDomains = $composerJson['config']['gitlab-domains'] ?? [];
            $name = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $row['NAME']);            
            $type = $row['TYPE'];
            switch ($type) {
                case RepoTypeDataType::COMPOSER:
                case RepoTypeDataType::PUPLISHED_PACKAGE:
                    $type = 'composer';
                    break;
                case RepoTypeDataType::BITBUCKET:
                case RepoTypeDataType::FOSSIL:
                case RepoTypeDataType::GIT:
                case RepoTypeDataType::GITHUB:
                case RepoTypeDataType::GITLAB:
                    if (! in_array($domain = $this->getDomainFromUrl($url), $gitlabDomains)) {
                        $gitlabDomains[] = $domain;
                    }
                    
                case RepoTypeDataType::MERCURIAL:
                case RepoTypeDataType::VCS:
                    $type = 'vcs';
                    break;
                case RepoTypeDataType::BOWER:
                    //$name = 'bower-asset' . '/' . $name;
                    break;
                case RepoTypeDataType::NPM:
                    //$name = 'npm-asset' . '/' . $name;
                    break;
                default:
                    yield "Package type '{$type}' for package '{$name}' is not supported. Installation cancelled!";
                    return;
                    
            }
            $composerJson['require'][$name] = $row['VERSION'];
            if ($url && $type !== RepoTypeDataType::BOWER && $type !== RepoTypeDataType::NPM) {
                $composerJson['repositories'][$name] = [
                    "type" => $type,
                    "url" => $url,
                    "only" => [$name]
                ];
            }
            //for authentification via auth.json to work on gitlab hosted packages
            //the gitlab domains have to be added to the config
            $composerJson['config']['gitlab-domains'] = $gitlabDomains;
        }        
        $filemanager->dumpFile($composerJsonPath, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        // run the composer to  download packages to payload vendor folder
        $cmd = 'php composer.phar update';
        foreach ($packageNames as $name) {
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
        yield $this->printLineDelimiter();
        $payloadVendorPath = $payloadPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
        $vendorPath = $filemanager->getPathToVendorFolder() . DIRECTORY_SEPARATOR;
        $installed_counter = 0;
        foreach ($packageNames as $name) {
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
                    yield "..." . $app_alias . " successfully installed." . PHP_EOL . PHP_EOL;
                } catch (\Exception $e) {
                    $installed_counter --;
                    $this->getWorkbench()->getLogger()->logException($e);
                    yield PHP_EOL . "ERROR: " . ($e instanceof ExceptionInterface ? $e->getMessage() . ' see log ID ' . $e->getId() : $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()) . PHP_EOL;
                    yield "...{$app_alias} installation failed!" . PHP_EOL . PHP_EOL;
                }
            }            
        }
        if ($installed_counter == 0) {
            yield  'No packages have been installed';
        } else {
            yield  "Installation done. {$installed_counter} Package(s) installed";
        }
        //remove composer temporary folder so it doesnt interfere with later installations
        $filemanager->deleteDir($composerTempPath);
        $workbench->getCache()->clear();
    }
    
    /**
     * Returns datasheet with all data for every db payload package entry
     * 
     * @param WorkbenchInterface $workbench
     * @param array $packageNames
     * @return DataSheetInterface
     */
    protected function getPackagesData(WorkbenchInterface $workbench) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.PackageManager.PAYLOAD_PACKAGES');        
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
                $getAll = true;
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
                $this->targetPackageNames = [];
                return $this->targetPackageNames;
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
     * @return \axenox\PackageManager\Actions\InstallPayload
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
    
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
}