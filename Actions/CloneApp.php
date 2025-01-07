<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\UUIDDataType;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Saves the metamodel for the selected apps as JSON files in the corresponding vendor folders.
 *
 * @triggers \exface\Core\Events\Installer\OnBeforeAppBackupEvent
 * @triggers \exface\Core\Events\Installer\OnAppBackupEvent
 *
 * @author Andrej Kabachnik
 *        
 */
class CloneApp extends ExportAppModel implements iCanBeCalledFromCLI
{

    private $export_to_path_relative = null;

    protected function init()
    {
        $this->setIcon(Icons::COPY);
        $this->setInputRowsMin(1);
        $this->setInputRowsMax(1);
        $this->setInputObjectAlias('exface.Core.APP');
    }
    
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [$this->getInputAppsDataSheet($task), $this->getNewAppAlias($task)];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(DataSheetInterface $apps = null, string $appAliasNew = null) : \Generator
    {
        yield from parent::performDeferred($apps);
        
        $exported_counter = 0;
        foreach ($apps->getRows() as $row) {
            $uidCounter = 0;
            $fileCounter = 0;
            yield 'Copying app ' . $row['ALIAS'] . '...' . PHP_EOL;
            
            $app = $this->getWorkbench()->getApp($row['ALIAS']);
            if (! file_exists($app->getDirectoryAbsolutePath())) {
                $this->getApp()->createAppFolder($app);
            }
            
            $appDirOld = $this->getModelFolderPathAbsolute($app);
            $modelFilesOld = $this->readModelFiles($appDirOld . DIRECTORY_SEPARATOR . 'Model');
            $composerJsonPathOld = $appDirOld . DIRECTORY_SEPARATOR . 'composer.json';
            if (file_exists($composerJsonPathOld)) {
                $modelFilesOld[$composerJsonPathOld] = str_replace(
                    [
                        mb_strtolower(str_replace('.', '/', $appAliasOld)), // package in composer.json
                    ], [
                        mb_strtolower(str_replace('.', '/', $appAliasNew)), 
                    ],
                    file_get_contents($composerJsonPathOld)
                );
            }
            $appFilePathOld = $appDirOld . DIRECTORY_SEPARATOR . $app->getAlias() . 'App.php';
            if (file_exists($appFilePathOld)) {
                $modelFilesOld[$appFilePathOld] = str_replace(
                    [
                        $app->getAlias() . 'App',
                        str_replace('.', '\\', $appAliasOld)
                    ], [
                        StringDataType::substringAfter($appAliasNew, '.', $appAliasNew) . 'App',
                        str_replace('.', '\\', $appAliasNew)
                    ],
                    file_get_contents($appFilePathOld),
                );
            }
            $appAliasOld = $app->getAliasWithNamespace();
            $modelFilesNew = [];
            foreach ($modelFilesOld as $path => $file) {
                $pathNew = str_replace([
                        $appAliasOld . '.', // model aliases
                        mb_strtolower($appAliasOld) . '.', // page aliases
                        mb_strtolower(str_replace('.', DIRECTORY_SEPARATOR, $appAliasOld)), // file paths
                        $app->getAlias() . 'App'
                    ], [
                        $appAliasNew . '.', 
                        $appAliasNew . '.',
                        mb_strtolower(str_replace('.', DIRECTORY_SEPARATOR, $appAliasNew)), 
                        StringDataType::substringAfter($appAliasNew, '.', $appAliasNew) . 'App'
                    ], FilePathDataType::normalize($path, DIRECTORY_SEPARATOR)
                );
                $modelFilesNew[$pathNew] = $file;
            }
            $fileCounter = count($modelFilesNew);

            $this->replaceInFiles($appAliasOld . '.', $appAliasNew . '.', $modelFilesNew); // Aliases with app namespace
            $this->replaceInFiles('"' . $appAliasOld . '"', '"' . $appAliasNew . '"', $modelFilesNew); // App alias explicitly
            $this->replaceInFiles(mb_strtolower($appAliasOld) . '.', mb_strtolower($appAliasNew) . '.', $modelFilesNew); // page aliases

            foreach ($modelFilesOld as $path => $json) {
                switch (true) {
                    case StringDataType::endsWith($path, 'App.php'):
                    case StringDataType::endsWith($path, 'composer.json'):
                        break;
                    case stripos($path, '99_PAGE') !== false:
                        $uxon = UxonObject::fromJson($json);
                        $uidOld = $uxon->getProperty('uid');
                        if ($uidOld) {
                            $uidNew = UUIDDataType::generateSqlOptimizedUuid();
                            $uidCounter++;
                            $this->replaceInFiles($uidOld, $uidNew, $modelFilesNew);
                        }
                        break;
                    default:
                        $sheet = DataSheetFactory::createFromUxon($this->getWorkbench(), UxonObject::fromJson($json));
                        if (! $sheet->hasUidColumn()) {
                            return;
                        }
                        foreach ($sheet->getUidColumn()->getValues() as $uidOld) {
                            $uidNew = UUIDDataType::generateSqlOptimizedUuid();
                            $uidCounter++;
                            $this->replaceInFiles($uidOld, $uidNew, $modelFilesNew);
                        }
                }
            }

            $fm = $this->getWorkbench()->filemanager();
            foreach ($modelFilesNew as $path => $file) {
                $folder = FilePathDataType::findFolderPath($path);
                $fm::pathConstruct($folder);
                $fm->dumpFile($path, $file);
            }
            
            $exportPathNew = mb_strtolower(str_replace('.', '/', $appAliasNew));
            yield '... exported app ' . $appAliasNew . ' into ' . $exportPathNew . '.' . PHP_EOL;
        }
    }

    protected function replaceInFiles(string $search, string $replace, array &$files) : array
    {
        foreach ($files as $p => $content) {
            $files[$p] = str_replace($search, $replace, $content);
        }
        return $files;
    }

    protected function readModelFiles(string $folderPath): array {
        $filesArray = [];
    
        // Check if the folder exists
        if (!is_dir($folderPath)) {
            throw new InvalidArgumentException("The provided path is not a directory: $folderPath");
        }
    
        // Use RecursiveDirectoryIterator to scan the folder and subfolders
        $directoryIterator = new \RecursiveDirectoryIterator($folderPath);
        $iterator = new \RecursiveIteratorIterator($directoryIterator);
    
        foreach ($iterator as $fileInfo) {
            // Check if the current item is a file and has .json extension
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'json') {
                $absolutePath = $fileInfo->getRealPath();
                if ($absolutePath !== false) {
                    // Get the file contents
                    $contents = file_get_contents($absolutePath);
                    if ($contents !== false) {
                        $filesArray[$absolutePath] = $contents;
                    }
                }
            }
        }
    
        return $filesArray;
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments() : array
    {
        return [
            (new ServiceParameter($this))->setName('app_to_copy')->setDescription('Alias the the app to be copied'),
            (new ServiceParameter($this))->setName('new_app_alias')->setDescription('New app alias to create')
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

    protected function getInputAppsDataSheet(TaskInterface $task)
    {
        if ($task->hasInputData()) {
            $input = $this->getInputDataSheet($task);
        } elseif ($task->hasParameter('app_to_copy')) {
            $input = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.APP');
            $input->getFilters()->addConditionFromString('ALIAS', $task->getParameter('app_to_copy'), ComparatorDataType::EQUALS);     
        }
        
        $input->getColumns()->addFromExpression('ALIAS');
        if (! $input->isFresh()) {
            if (! $input->isEmpty()) {
                $input->getFilters()->addConditionFromColumnValues($input->getUidColumn());
            }
            $input->dataRead();
        }
        return $input;
    }

    protected function getNewAppAlias(TaskInterface $task) : string
    {
        return $task->getParameter('new_app_alias');
    }
}