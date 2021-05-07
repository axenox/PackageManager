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
use kabachello\ComposerAPI\ComposerAPI;

class InstallPayloadPackage extends AbstractActionDeferred implements iCanBeCalledFromCLI {
    
    private $target_app_aliases = null;
    
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

    protected function performDeferred(array $aliases = []): \Generator
    {
        $workbench = $this->getWorkbench();
        $filemanager = $workbench->filemanager();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'exface.core.PAYLOAD_PACKAGES');
        $filters = ConditionGroupFactory::createForDataSheet($ds, EXF_LOGICAL_OR);
        foreach ($aliases as $alias) {
            $filters->addConditionFromString('APP_ALIAS', $alias, EXF_COMPARATOR_EQUALS);
        }
        $ds->getFilters()->addNestedGroup($filters);
        $ds->getColumns()->addMultiple(['URL', 'VERSION', 'TYPE', 'APP_ALIAS']);
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
            $composerJson = json_decode($json);
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
            $name = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $row['APP_ALIAS']);
            $appNames[] = name;
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
            $file = fopen($filepath, "w");
            fwrite($file, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fclose($file);
        }        
        copy($filemanager->getPathToBaseFolder() . DIRECTORY_SEPARATOR . 'composer.phar', $path . DIRECTORY_SEPARATOR . 'composer.phar');
        // TODO
        // set Composer Home Environment Variable putenv('COMPOSER_HOME=' . $this->get_path_to_composer_home());
        // call composer via something like this:
        /*$envVars = array_merge($envVars, $widget->getEnvironmentVars());
        $process = Process::fromShellCommandline($cmd, null, $envVars, null, $widget->getCommandTimeout());
        $process->start();
        $generator = function ($process) {
            foreach ($process as $output) {
                yield $output;
            }
        };
        
        $stream = new IteratorStream($generator($process));
        */
        /*
        $composer = new ComposerAPI($filepath);
        $composer->set_path_to_composer_home($path . DIRECTORY_SEPARATOR . 'composer.phar');        
        $output = $composer->update($appNames);
        */
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
                $input = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.PAYLOAD_PACKAGES');
            } else {
                throw $e;
            }
        }
        
        if ($input->getMetaObject()->isExactly('exface.Core.PAYLOAD_PACKAGES')) {
            $input->getColumns()->addFromExpression('APP_ALIAS');
            if (! $input->isEmpty()) {
                if (! $input->isFresh()) {
                    $input->dataRead();
                }
            } elseif ($getAll === true || ! $input->getFilters()->isEmpty()) {
                $input->dataRead();
            }
            $this->target_app_aliases = array_unique($input->getColumnValues('APP_ALIAS', false));
        } else {
            throw new ActionInputInvalidObjectError($this, 'The action "' . $this->getAliasWithNamespace() . '" can only be called on the meta objects "exface.Core.PAYLOAD_PACKAGES" - "' . $input->getMetaObject()->getAliasWithNamespace() . '" given instead!');
        }
        
        return $this->target_app_aliases;
    }
    
    public function getCliArguments(): array
    {}

    public function getCliOptions(): array
    {}
}