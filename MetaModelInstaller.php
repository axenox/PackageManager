<?php
namespace axenox\PackageManager;

use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\AppInterface;
use exface\Core\CommonLogic\Model\ConditionGroup;
use exface\Core\CommonLogic\Model\Aggregator;
use exface\Core\DataTypes\AggregatorFunctionsDataType;

class MetaModelInstaller extends AbstractAppInstaller
{

    const FOLDER_NAME_MODEL = 'Model';

    /**
     *
     * @param string $source_absolute_path
     * @return string
     */
    public function install($source_absolute_path)
    {
        return $this->installModel($this->getNameResolver(), $source_absolute_path);
    }

    /**
     *
     * @param string $source_absolute_path
     * @return string
     */
    public function update($source_absolute_path)
    {
        return $this->installModel($this->getNameResolver(), $source_absolute_path);
    }

    /**
     *
     * @param string $destination_absolute_path
     *            Destination folder for meta model backup
     * @return string
     */
    public function backup($destination_absolute_path)
    {
        return $this->backupModel($destination_absolute_path);
    }

    /**
     *
     * @return string
     */
    public function uninstall()
    {
        $result = '';
        $sheets = $this->getModelDataSheets();
        if (! empty($sheets)){
            array_reverse($sheets);
            
            $transaction = $this->getWorkbench()->data()->startTransaction();
            
            foreach ($sheets as $ds){
                $counter = $ds->dataDelete($transaction);
                if ($counter > 0) {
                    $result .= ($result ? "; " : "") . $ds->getMetaObject()->getName() . " - " . $counter;
                }
            }
            
            $transaction->commit();
            
            if (! $result) {
                $result .= 'Nothing to uninstall';
            }
        } else {
            $result .= 'Nothing to uninstall';
        }
        return "\nModel changes: " . $result;
    }

    /**
     * Analyzes model data sheet and writes json files to the model folder
     *
     * @param string $destinationAbsolutePath
     * @return string
     */
    protected function backupModel($destinationAbsolutePath)
    {
        $result = '';
        $app = $this->getApp();
        $dir = $destinationAbsolutePath . DIRECTORY_SEPARATOR . self::FOLDER_NAME_MODEL;
        
        // Fetch all model data in form of data sheets
        $sheets = $this->getModelDataSheets();
        
        // Make sure, the destination folder is there and empty (to remove 
        // files, that are not neccessary anymore)
        $app->getWorkbench()->filemanager()->pathConstruct($dir);
        // Remove any old files AFTER the data sheets were read successfully
        // in order to keep old data on errors.
        $app->getWorkbench()->filemanager()->emptyDir($dir);
        
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
        
        $result .= "\n" . 'Created meta model backup for "' . $app->getAliasWithNamespace() . '".';
        
        // Backup pages.
        $pageInstaller = new PageInstaller($this->getNameResolver());
        $result .= ' ' . $pageInstaller->backup($destinationAbsolutePath);
        
        return $result;
    }

    /**
     * Writes JSON File of a $data_sheet to a specific location
     *
     * @param string $backupDir            
     * @param DataSheetInterface $data_sheet
     * @param string $filename_prefix            
     * @return string
     */
    protected function exportModelFile($backupDir, DataSheetInterface $data_sheet, $filename_prefix = null)
    {
        $contents = $data_sheet->exportUxonObject()->toJson(true);
        if (! $data_sheet->isEmpty()) {
            $fileManager = $this->getWorkbench()->filemanager();
            $fileManager->dumpFile($backupDir . DIRECTORY_SEPARATOR . $filename_prefix . $data_sheet->getMetaObject()->getAlias() . '.json', $contents);
            return $contents;
        }
        
        return '';
    }

    /**
     *
     * @param AppInterface $app            
     * @return DataSheetInterface[]
     */
    public function getModelDataSheets()
    {
        $sheets = array();
        $app = $this->getApp();        
        $sheets = array();
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.APP'), 'UID');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.DATATYPE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT_BEHAVIORS'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.ATTRIBUTE'), 'OBJECT__APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.DATASRC'), 'APP', array(
            'CONNECTION',
            'CUSTOM_CONNECTION',
            'QUERYBUILDER',
            'CUSTOM_QUERY_BUILDER'
        ));
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.CONNECTION'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.ERROR'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT_ACTION'), 'APP');
        return $sheets;
    }

    /**
     *
     * @param AppInterface $app            
     * @param MetaObjectInterface $object            
     * @param string $app_filter_attribute_alias   
     * @param array $exclude_attribute_aliases         
     * @return DataSheetInterface
     */
    protected function getObjectDataSheet($app, MetaObjectInterface $object, $app_filter_attribute_alias, array $exclude_attribute_aliases = array())
    {
        $ds = DataSheetFactory::createFromObject($object);
        foreach ($object->getAttributeGroup('~WRITABLE')->getAttributes() as $attr) {
            if (in_array($attr->getAlias(), $exclude_attribute_aliases)){
               continue;
            }
            $ds->getColumns()->addFromExpression($attr->getAlias());
        }
        $ds->addFilterFromString($app_filter_attribute_alias, $app->getUid());
        $ds->getSorters()->addFromString('CREATED_ON', 'ASC');
        $ds->getSorters()->addFromString($object->getUidAttributeAlias(), 'ASC');
        $ds->dataRead();
        return $ds;
    }

    /**
     *
     * @param NameResolverInterface $app_name_resolver            
     * @param string $source_absolute_path            
     * @return string
     */
    protected function installModel(NameResolverInterface $app_name_resolver, $source_absolute_path)
    {
        $result = '';
        $exface = $this->getWorkbench();
        $model_source = $source_absolute_path . DIRECTORY_SEPARATOR . self::FOLDER_NAME_MODEL;
        
        if (is_dir($model_source)) {
            $transaction = $this->getWorkbench()->data()->startTransaction();
            foreach (scandir($model_source) as $file) {
                if ($file == '.' || $file == '..')
                    continue;
                $data_sheet = DataSheetFactory::createFromUxon($exface, UxonObject::fromJson(file_get_contents($model_source . DIRECTORY_SEPARATOR . $file)));
                
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
                if ($behavior = $data_sheet->getMetaObject()->getBehaviors()->getByAlias('exface.Core.Behaviors.TimeStampingBehavior')) {
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
                    $result .= ($result ? "; " : "") . $data_sheet->getMetaObject()->getName() . " - " . $counter;
                }
            }
            // Install pages.
            $pageInstaller = new PageInstaller($this->getNameResolver());
            $result .= ($result ? '; ' : '') . $pageInstaller->install($source_absolute_path);
            // Commit the transaction
            $transaction->commit();
            
            if (! $result) {
                $result .= 'No changes found';
            }
        } else {
            $result .= 'No model files to install';
        }
        return "\nModel changes: " . $result;
    }
    
    protected function checkFiltersMatchModel(ConditionGroup $condition_group)
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
}