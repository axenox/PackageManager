<?php
namespace axenox\PackageManager;

use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\AppInterface;
use exface\Core\CommonLogic\Model\Aggregator;
use exface\Core\DataTypes\AggregatorFunctionsDataType;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\QueryBuilder\RowDataArrayFilter;
use exface\Core\Exceptions\Installers\InstallerRuntimeError;
use exface\Core\Behaviors\ModelValidatingBehavior;
use exface\Core\DataTypes\UxonDataType;
use exface\Core\Interfaces\Model\ConditionGroupInterface;
use exface\Core\DataTypes\JsonDataType;

/**
 * Saves all model entities as JSON files in the `Model` subfolder of the app.
 * 
 * ## Export folder and file structure
 * 
 * The folder structure is outlined below. Each file is a data sheet UXON containing
 * all the model entities of it's type except for pages. Since the page installer logic
 * is more complex than simple replacement (and also for historical reasons), each page 
 * is stored in a separate file as it's UXON-export.
 * 
 * - app.alias.object_alias_1 <- object-bound model entities are saved in subfolder per object
 *      - 02_OBJECT.json
 *      - 03_OBJECT_BEHAVIORS.json
 *      - 04_ATTRIBUTE.json
 *      - 08_OBJECT_ACTION.json
 * - app.alias.object_alias_2
 * - ...
 * - 00_APP.json <- entities without an object binding are stored as data sheet UXON
 * - 01_DATATYPE.json
 * - ...
 * - 99_PAGES
 *      - app.alias.page_alias_1.json
 *      - app.alias.page_alias_2.json
 *      - ... 
 * 
 * In contrast to the regular UXON-export of a data sheet where the value of each column is
 * stored as a string, the model sheet columns containing UXON have prettyprinted JSON values.
 * This makes it easier to identify changes in larger UXON objects like default editors, aciton
 * configurations, etc.
 * 
 * Object-bound entities like attributes, behaviors, etc. are saved per object in subfolders.
 * This simplifies change management greatly as it is difficult to diff large JSON files properly.
 * 
 * When installing, the files are processed in alphabetical order - more precisely in the order
 * of the numerc filename prefixes. For each entity type a data sheet is instantiated and 
 * `DataSheetInterface::dataReplaceByFilters()` is preformed filtered by the app - this makes
 * sure all possibly existing entities bound to this app are completely replaced by the contents
 * of the data sheet.
 * 
 * Object-bound entities are first collected into a single large data sheet to make sure all
 * data is replaced at once.
 * 
 * Pages are installed last by calling the dedicated `PageInstaller`.
 * 
 * ## Behaviors
 * 
 * NOTE: The `TimeStampingBehavior` of the model objects is disabled before install, so the
 * create/update stamps of the exported model are saved correctly.
 * 
 * ## Backwards compatibilty issues
 * 
 * Keep in mind, that the metamodel installer requires the current model in the DB to be
 * compatible with the model files it installs. In other words, if the exported attribute-sheet
 * has certain columns, the currently deployed metamodel for attributes themselves MUST have
 * attributes for each of these columns. The same goes for any other metamodel entity.
 * 
 * Thus, when attributes of model entities change, the compatibility between files and current
 * model must be restored at the time of installation. Here is a typical approach:
 * 
 * - Changes to the model (e.g. adding new attributes to objects, attributes, etc.) need to be applied 
 * in the core SQL migrations to make sure they are already there when loading this installer.
 * - Legacy model files (exported from older models) may need some transformation. This can be
 * applied in `applyCompatibilityFixesToFileContent()` or `applyCompatibilityFixesToDataSheet()`
 * depending on where the changes are easier to implement.
 * 
 * @author Andrej Kabachnik
 *
 */
class MetaModelInstaller extends AbstractAppInstaller
{
    const FOLDER_NAME_MODEL = 'Model';
    
    const FOLDER_NAME_PAGES = '99_PAGE';
    
    private $objectSheet = null;

    /**
     *
     * @param string $source_absolute_path
     * @return string
     */
    public function install(string $source_absolute_path) : \Iterator 
    {
        yield from $this->installModel($this->getSelectorInstalling(), $source_absolute_path);
    }

    /**
     *
     * @param string $destination_absolute_path
     *            Destination folder for meta model backup
     * @return string
     */
    public function backup(string $destination_absolute_path) : \Iterator
    {
        yield from $this->backupModel($destination_absolute_path);
    }

    /**
     *
     * @return string
     */
    public function uninstall() : \Iterator
    {
        $idt = $this->getOutputIndentation();
        $sheets = $this->getModelDataSheets();
        
        $transaction = $this->getWorkbench()->data()->startTransaction();
        
        $pageInstaller = $this->getPageInstaller();
        $pageInstaller->setOutputIndentation($idt);
        $pageInstaller->setTransaction($transaction);
        yield from $pageInstaller->uninstall();
        
        yield $idt . 'Uninstalling model:' . PHP_EOL;
        
        $counter = 0;
        if (! empty($sheets)){            
            foreach ($sheets as $sheet) {
                if ($sheet->getMetaObject()->is('exface.Core.APP') === true) {
                    $appSheet = $sheet;
                    break;
                }
            }
            
            $appSheet->getFilters()->removeAll();
            $counter = $appSheet->dataDelete($transaction);
            $transaction->commit();
            
            if ($counter === 0) {
                yield $idt.$idt . 'Nothing to do.' . PHP_EOL;
            } else {
                yield $idt.$idt . 'Removed app model successfully!' . PHP_EOL; 
            }
        } else {
            yield $idt.$idt . 'App model not found!' . PHP_EOL;  
        }
    }

    /**
     * Analyzes model data sheet and writes json files to the model folder
     *
     * @param string $destinationAbsolutePath
     * @return string
     */
    protected function backupModel($destinationAbsolutePath) : \Iterator
    {
        $idt = $this->getOutputIndentation();
        $app = $this->getApp();
        $dir = $destinationAbsolutePath . DIRECTORY_SEPARATOR . self::FOLDER_NAME_MODEL;
        
        // Fetch all model data in form of data sheets
        $sheets = $this->getModelDataSheets();
        
        // Make sure, the destination folder is there and empty (to remove 
        // files, that are not neccessary anymore)
        $app->getWorkbench()->filemanager()->pathConstruct($dir);
        // Remove any old files AFTER the data sheets were read successfully
        // in order to keep old data on errors.
        Filemanager::emptyDir($dir);
        
        // Save each data sheet as a file and additionally compute the modification date of the last modified model instance and
        // the MD5-hash of the entire model definition (concatennated contents of all files). This data will be stored in the composer.json
        // and used in the installation process of the package
        $last_modification_time = '0000-00-00 00:00:00';
        $model_string = '';
        foreach ($sheets as $nr => $ds) {
            $model_string .= $this->exportModelFile($dir, $ds, str_pad($nr, 2, '0', STR_PAD_LEFT) . '_');
            $time = $ds->getColumns()->getByAttribute($ds->getMetaObject()->getAttribute('MODIFIED_ON'))->aggregate(new Aggregator($this->getWorkbench(), AggregatorFunctionsDataType::MAX));
            $last_modification_time = $time > $last_modification_time ? $time : $last_modification_time;
        }
        
        // Save some information about the package in the extras of composer.json
        $package_props = array(
            'app_uid' => $app->getUid(),
            'app_alias' => $app->getAliasWithNamespace(),
            'model_md5' => md5($model_string),
            'model_timestamp' => $last_modification_time
        );
        
        $packageManager = $this->getWorkbench()->getApp("axenox.PackageManager");
        $composer_json = $packageManager->getComposerJson($app);
        $composer_json['extra']['app'] = $package_props;
        $packageManager->setComposerJson($app, $composer_json);
        
        yield $idt . 'Created meta model backup for "' . $app->getAliasWithNamespace() . '".' . PHP_EOL;
        
        // Backup pages.
        $pageInstaller = $this->getPageInstaller();
        $pageInstaller->setOutputIndentation($idt);
        yield from $pageInstaller->backup($destinationAbsolutePath);
    }

    /**
     * Writes JSON File of a $data_sheet to a specific location
     *
     * @param string $backupDir            
     * @param DataSheetInterface $data_sheet
     * @param string $filename_prefix            
     * @return string
     */
    protected function exportModelFile($backupDir, DataSheetInterface $data_sheet, $filename_prefix = null, $split_by_object = true) : string
    {
        if ($data_sheet->isEmpty()) {
            return '';
        }
        
        if (! file_exists($backupDir)) {
            Filemanager::pathConstruct($backupDir);
        }
        
        if ($split_by_object === true) {
            switch (true) {
                case $data_sheet->getMetaObject()->isExactly('exface.Core.OBJECT'): 
                    $col = $data_sheet->getUidColumn();
                    $objectUids = $col->getValues(false);
                    break;
                case $data_sheet->getMetaObject()->isExactly('exface.Core.ATTRIBUTE_COMPOUND'):
                    $col = $data_sheet->getColumns()->addFromExpression('COMPOUND_ATTRIBUTE__OBJECT');
                    $removeObjectCol = true;
                    $data_sheet->dataRead();
                    $objectUids = array_unique($col->getValues(false));
                    break;
                default: 
                    foreach ($data_sheet->getColumns() as $col) {
                        if ($attr = $col->getAttribute()) {
                            if ($attr->isRelation() && $attr->getRelation()->getRightObject()->isExactly('exface.Core.OBJECT') && $attr->isRequired()) {
                                $objectUids = array_unique($col->getValues(false));
                                break;
                            }
                        }
                    }
            }
        }
        
        $fileManager = $this->getWorkbench()->filemanager();
        $fileName = $filename_prefix . $data_sheet->getMetaObject()->getAlias() . '.json';
        if (! empty($objectUids)) {
            $rows = $data_sheet->getRows();
            if ($removeObjectCol === true) {
                $data_sheet->getColumns()->remove($col);
            }
            $uxon = $data_sheet->exportUxonObject();
            $objectColumnName = $col->getName();
            foreach ($objectUids as $objectUid) {
                $filteredRows = array_values($this->filterRows($rows, $objectColumnName, $objectUid));
                if ($removeObjectCol === true) {
                    for ($i = 0; $i < count($filteredRows); $i++) {
                        unset($filteredRows[$i][$objectColumnName]);
                    }
                }
                $uxon->setProperty('rows', $this->exportModelRowsPrettified($data_sheet, $filteredRows));
                $subfolder = $backupDir . DIRECTORY_SEPARATOR . $this->getObjectSubfolder($objectUid);
                $fileManager->dumpFile($subfolder . DIRECTORY_SEPARATOR . $fileName, $uxon->toJson(true));
            }
        } else {
            $uxon = $data_sheet->exportUxonObject();
            $uxon->setProperty('rows', $this->exportModelRowsPrettified($data_sheet));
            $contents = $uxon->toJson(true);
            $fileManager->dumpFile($backupDir . DIRECTORY_SEPARATOR . $fileName, $contents);
            return $contents;
        }
        
        return '';
    }
    
    protected function exportModelRowsPrettified(DataSheetInterface $sheet, array $rows = null) : array
    {
        $rows = $rows ?? $sheet->getRows();
        foreach ($sheet->getColumns() as $col) {
            if ($col->getDataType() instanceof UxonDataType) {
                $colName = $col->getName();
                foreach ($rows as $i => $row) {
                    $val = $row[$colName];
                    if ($val !== null && $val !== '') {
                        try {
                            $valUxon = UxonObject::fromAnything($val);
                            $rows[$i][$colName] = $valUxon->toArray();
                        } catch (\Throwable $e) {
                            // Ignore errors
                        }
                    }
                }
            }
        }
        return $rows;
    }
    
    protected function filterRows(array $rows, string $filterRowName, string $filterRowValue)
    {
        $filter = new RowDataArrayFilter();
        $filter->addAnd($filterRowName, $filterRowValue, EXF_COMPARATOR_EQUALS);
        return $filter->filter($rows);
    }

    /**
     *
     * @param AppInterface $app            
     * @return DataSheetInterface[]
     */
    public function getModelDataSheets() : array
    {
        $sheets = array();
        $app = $this->getApp();        
        $sheets = array();
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.APP'), 'UID');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.DATATYPE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT_BEHAVIORS'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.ATTRIBUTE'), 'OBJECT__APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.DATASRC'), 'APP', [
            'CONNECTION',
            'CUSTOM_CONNECTION',
            'QUERYBUILDER',
            'CUSTOM_QUERY_BUILDER'
        ]);
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.CONNECTION'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.MESSAGE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT_ACTION'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.UXON_PRESET'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.PAGE_TEMPLATE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.ATTRIBUTE_COMPOUND'), 'COMPOUND_ATTRIBUTE__OBJECT__APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.PAGE_GROUP'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.PAGE_GROUP_PAGES'), ['PAGE__APP', 'PAGE_GROUP__APP']);
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.USER_ROLE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.AUTHORIZATION_POINT'), 'APP', [
            'DEFAULT_EFFECT',
            'DEFAULT_EFFECT_LOCAL',
            'COMBINING_ALGORITHM',
            'COMBINING_ALGORITHM_LOCAL',
            'DISABLED_FLAG'
        ]);
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.AUTHORIZATION_POLICY'), 'APP', [
            'DISABLED_FLAG'
        ]);
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.QUEUE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.SCHEDULER'), 'APP');
        
        return $sheets;
    }

    /**
     *
     * @param AppInterface $app            
     * @param MetaObjectInterface $object            
     * @param string|string[] $app_filter_attribute_alias   
     * @param array $exclude_attribute_aliases         
     * @return DataSheetInterface
     */
    protected function getObjectDataSheet($app, MetaObjectInterface $object, $app_filter_attribute_alias, array $exclude_attribute_aliases = array()) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObject($object);
        foreach ($object->getAttributeGroup('~WRITABLE')->getAttributes() as $attr) {
            if (in_array($attr->getAlias(), $exclude_attribute_aliases)){
               continue;
            }
            $ds->getColumns()->addFromExpression($attr->getAlias());
        }
        
        $filterAttrs = is_array($app_filter_attribute_alias) ? $app_filter_attribute_alias : [$app_filter_attribute_alias];
        
        foreach ($filterAttrs as $filterAttr) {
            $ds->getFilters()->addConditionFromString($filterAttr, $app->getUid());
        }
        
        $ds->getSorters()->addFromString('CREATED_ON', 'ASC');
        $ds->getSorters()->addFromString($object->getUidAttributeAlias(), 'ASC');
        
        $ds->dataRead();
        return $ds;
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @param string $source_absolute_path            
     * @return string
     */
    protected function installModel(AppSelectorInterface $app_selector, $source_absolute_path) : \Iterator
    {
        $modelChanged = false;
        $indent = $this->getOutputIndentation();
        yield $indent . "Model changes:" . PHP_EOL;
        
        $model_source = $source_absolute_path . DIRECTORY_SEPARATOR . self::FOLDER_NAME_MODEL;
        
        if (is_dir($model_source)) {
            $transaction = $this->getWorkbench()->data()->startTransaction();
            foreach ($this->readModelSheets($model_source) as $data_sheet) {
                try {
                    
                    // Remove columns, that are not attributes. This is important to be able to import changes on the meta model itself.
                    // The trouble is, that after new properties of objects or attributes are added, the export will already contain them
                    // as columns, which would lead to an error because the model entities for these columns are not there yet.
                    foreach ($data_sheet->getColumns() as $column) {
                        if (! $column->getMetaObject()->hasAttribute($column->getAttributeAlias()) || ! $column->getAttribute()) {
                            $data_sheet->getColumns()->remove($column);
                        }
                    }
                    
                    if ($mod_col = $data_sheet->getColumns()->getByExpression('MODIFIED_ON')) {
                        $mod_col->setIgnoreFixedValues(true);
                    }
                    if ($user_col = $data_sheet->getColumns()->getByExpression('MODIFIED_BY_USER')) {
                        $user_col->setIgnoreFixedValues(true);
                    }
                    
                    // Disable timestamping behavior because it will prevent multiple installations of the same
                    // model since the first install will set the update timestamp to something later than the
                    // timestamp saved in the model files
                    foreach ($data_sheet->getMetaObject()->getBehaviors()->getByPrototypeClass(TimeStampingBehavior::class) as $behavior) {
                        $behavior->disable();
                    }
                    // Disable model validation because it would instantiate all objects when the object sheet is being saved,
                    // which will attempt to load an inconsistent model (e.g. because the attributes were not yet updated
                    // at this point.
                    foreach ($data_sheet->getMetaObject()->getBehaviors()->getByPrototypeClass(ModelValidatingBehavior::class) as $behavior) {
                        $behavior->disable();
                    }
                    
                    // There were cases, when the attribute, that is being filtered over was new, so the filters
                    // did not work (because the attribute was not there). The solution is to run an update
                    // with create fallback in this case. This will cause filter problems, but will not delete
                    // obsolete instances. This is not critical, as the probability of this case is extremely
                    // low in any case and the next update will turn everything back to normal.
                    if (! $this->checkFiltersMatchModel($data_sheet->getFilters())) {
                        $data_sheet->getFilters()->removeAll();
                        $counter = $data_sheet->dataUpdate(true, $transaction);
                    } else {
                        $counter = $data_sheet->dataReplaceByFilters($transaction);
                    }
                    
                    if ($counter > 0) {
                        $modelChanged = true;
                        yield $indent . $indent . $data_sheet->getMetaObject()->getName() . " - " . $counter . PHP_EOL;
                    }
                } catch (\Throwable $e) {
                    throw new InstallerRuntimeError($this, 'Failed to install ' . $data_sheet->getMetaObject()->getAlias() . '-sheet: ' . $e->getMessage(), null, $e);
                }
            }
            
            if ($modelChanged === false) {
                yield $indent.$indent."No changes found" . PHP_EOL;
            }
            
            // Install pages.
            $pageInstaller = $this->getPageInstaller();
            $pageInstaller->setOutputIndentation($indent);
            $pageInstaller->setTransaction($transaction);
            yield from $pageInstaller->install($source_absolute_path);
            
            // Commit the transaction
            $transaction->commit();
        } else {
            yield $indent . "No model files to install" . PHP_EOL;
        }
    }
    
    protected function getPageInstaller() : PageInstaller
    {
        return new PageInstaller($this->getSelectorInstalling(), self::FOLDER_NAME_MODEL . DIRECTORY_SEPARATOR . self::FOLDER_NAME_PAGES);
    }
    
    /**
     * Generates data sheets from the Model folder.
     * 
     * It is important, that this is a generator as there are sheets for things like page groups,
     * where the meta object may not even exist at the time of reading - the objects and attributes
     * sheets must be read and processed first to read the other sheets.
     * 
     * @param string $absolutePath
     * @return DataSheetInterface[]
     */
    protected function readModelSheets($absolutePath) : \Generator
    {
        $uxons = [];
        $folderSheetUxons = $this->readDataSheetUxonsFromFolder($absolutePath);
        
        ksort($folderSheetUxons);
        
        // Organize the UXON objects in an array like [object_alias => [uxon1, uxon2, ...]]
        foreach ($folderSheetUxons as $key => $uxon) {
            $type = StringDataType::substringBefore($key, '@');
            $uxons[$type][] = $uxon;
        }
        
        // For each object, combine it's UXONs into a single data sheet
        foreach ($uxons as $array) {
            $cnt = count($array);
            // Init the data sheet from the first UXON, but without any rows. We will preprocess
            // the rows later and transform expanded UXON values into strings.
            $baseUxon = $array[0];
            // Save the rows for later processing
            $rows = $baseUxon->getProperty('rows')->toArray();
            $baseUxon->unsetProperty('rows');
            $baseSheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $baseUxon);
            $baseColCount = $baseSheet->getColumns()->count();
            // Add rows from all the other UXONs
            if ($cnt > 1) {
                for ($i = 1; $i < $cnt; $i++) {
                    $partUxon = $array[$i];
                    $partRows = $partUxon->hasProperty('rows') ? $partUxon->getProperty('rows')->toArray() : [];
                    $partUxon->unsetProperty('rows');
                    // Instantiate an empty data sheet to check, if it's compatible!
                    $sheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $partUxon);
                    if (! $baseSheet->getMetaObject()->isExactly($sheet->getMetaObject())) {
                        throw new InstallerRuntimeError($this, 'Model sheet type mismatch: model sheets with same name must have the same structure in all subfolders of the model!');
                    }
                    if ($sheet->getColumns()->count() !== $baseColCount) {
                        throw new InstallerRuntimeError($this, 'Corrupted model data: all model sheets of the same type must have the same columns!');
                    }
                    $rows = array_merge($rows, $partRows);
                }
            }
            
            // Preprocess row values
            foreach ($baseSheet->getColumns() as $col) {
                // UXON values are normally transformed into JSON when exporting the model to
                // increase readability of diffs. Need to transform them back to strings here.
                // The check for JsonDataType is a fix upgrading older installations where the
                // UxonDataType was not a PHP class yet.
                if ($col->getDataType() instanceof UxonDataType || $col->getDataType() instanceof JsonDataType) {
                    $colName = $col->getName();
                    foreach ($rows as $i => $row) {
                        $val = $row[$colName];
                        if (is_array($val)) {
                            $rows[$i][$colName] = (UxonObject::fromArray($val))->toJson();
                        }
                    }
                }
            }
            
            // Add all the rows to the sheet.
            $baseSheet->addRows($rows, false, false);
            
            $baseSheet = $this->applyCompatibilityFixesToDataSheet($baseSheet);
            
            yield $baseSheet;
        }
    }
    
    /**
     * 
     * @param string $absolutePath
     * @return UxonObject[]
     */
    protected function readDataSheetUxonsFromFolder(string $absolutePath) : array
    {
        $folderUxons = [];
        
        foreach (scandir($absolutePath) as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            if ($file === self::FOLDER_NAME_PAGES) {
                continue;
            }
            $path = $absolutePath . DIRECTORY_SEPARATOR . $file;
            $key = $file . '@' . $absolutePath;
            if (is_dir($path)) {
                $folderUxons = array_merge($folderUxons, $this->readDataSheetUxonsFromFolder($path));
            } else {
                $folderUxons[$key] = $this->readDataSheetUxonFromFile($path);
            }
        }
        
        return $folderUxons;
    }
    
    /**
     * 
     * @param string $path
     * @return UxonObject
     */
    protected function readDataSheetUxonFromFile(string $path) : UxonObject
    {
        $contents = file_get_contents($path);
        $contents = $this->applyCompatibilityFixesToFileContent($path, $contents);
        
        return UxonObject::fromJson($contents);
    }
    
    /**
     * 
     * @param string $path
     * @param string $contents
     * @return string
     */
    protected function applyCompatibilityFixesToFileContent(string $path, string $contents) : string
    {
        // upgrade to 0.28: Translate old error model to message model
        $filename = mb_strtolower(basename($path));
        if ($filename === '07_error.json') {
            $replaceFrom = [
                'exface.Core.ERROR',
                'ERROR_CODE',
                'ERROR_TEXT'
            ];
            $replaceTo = [
                'exface.Core.MESSAGE',
                'CODE',
                'TITLE'
            ];
            $contents = str_replace($replaceFrom, $replaceTo, $contents);
        }
        
        // upgrade to 0.29: The LABEL attribute of object and attributes was replaced by NAME.
        if ($filename === '02_object.json' || $filename === '04_attribute.json') {
            $objObject = $this->getWorkbench()->model()->getObject('exface.Core.OBJECT');
            
            // Make older model files work with new model (0.29+)
            // If there is no NAME-column, rename the LABEL column to NAME.
            if ($objObject->hasAttribute('NAME') === true && strpos($contents, '{
            "name": "NAME",
            "attribute_alias": "NAME"
        }') === false) {
                
                // Replace the columns entry
                $contents = str_replace('{
            "name": "LABEL",
            "attribute_alias": "LABEL"
        }', '{
            "name": "NAME",
            "attribute_alias": "NAME"
        }', $contents);
                
                // Replace the row data
                $contents = str_replace('"LABEL": "', '"NAME": "', $contents);
                
            }
            // Make older models work with new model files (needed to upagrade to new model)
            // Replace things right the other way around.
            elseif ($objObject->hasAttribute('NAME') === false) {
                // If there is no NAME-column, rename the LABEL column to NAME.
                if (strpos($contents, '{
            "name": "LABEL",
            "attribute_alias": "LABEL"
        }') === false) {
        
                    // Replace the columns entry
                    $contents = str_replace('{
            "name": "NAME",
            "attribute_alias": "NAME"
        }', '{
            "name": "LABEL",
            "attribute_alias": "LABEL"
        }', $contents);
                
                    // Replace the row data
                    $contents = str_replace('"NAME": "', '"LABEL": "', $contents);
                
                }
            }
        }
        
        return $contents;
    }
    
    /**
     * 
     * @param DataSheetInterface $sheet
     * @return DataSheetInterface
     */
    protected function applyCompatibilityFixesToDataSheet(DataSheetInterface $sheet) : DataSheetInterface
    {
        // Upgrade to 1.2: add copyable-attribute to any legacy attribute sheet and make it
        // get the values from the editable-attribute. This ensures backwards compatibility
        // because the CopyData action copied editable attributes before a dedicated copyable
        // flag was introduced.
        if ($sheet->getMetaObject()->isExactly('exface.Core.ATTRIBUTE')) {
            if (! $sheet->getColumns()->getByExpression('COPYABLEFLAG') && $editableCol = $sheet->getColumns()->getByExpression('EDITABLEFLAG')) {
                $sheet->getColumns()->addFromExpression('COPYABLEFLAG')->setValues($editableCol->getValues(false));
            }
        }
        
        return $sheet;
    }
    
    /**
     * 
     * @param ConditionGroupInterface $condition_group
     * @return bool
     */
    protected function checkFiltersMatchModel(ConditionGroupInterface $condition_group) : bool
    {
        foreach ($condition_group->getConditions() as $condition){
            if(! $condition->getExpression()->isMetaAttribute()){
                return false;
            }
        }
        
        foreach ($condition_group->getNestedGroups() as $subgroup){
            if (! $this->checkFiltersMatchModel($subgroup)){
                return false;
            }
        }
        return true;
    }
    
    /**
     * 
     * @param string $uid
     * @return string
     */
    protected function getObjectSubfolder(string $uid) : string
    {
        if ($this->objectSheet !== null) {
            $row = $this->objectSheet->getRow($this->objectSheet->getUidColumn()->findRowByValue($uid));
            $alias = $this->getApp()->getAliasWithNamespace() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER . $row['ALIAS'];
        }
        
        if (! $alias) {
            $alias = $this->getWorkbench()->model()->getObject($uid)->getAliasWithNamespace();
        }
        
        return trim($alias);
    }
}