<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Exceptions\AppNotFoundError;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\Model\Object;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;

/**
 * This Action saves alle elements of the meta model assotiated with an app as JSON files in the Model subfolder of the current
 * installations folder of this app.
 *
 * @author Andrej Kabachnik
 *        
 */
class ExportAppModel extends AbstractAction
{

    private $export_to_path_relative = null;

    protected function init()
    {
        $this->setIconName('export-data');
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }

    protected function perform()
    {
        $apps = $this->getInputAppsDataSheet();
        
        $exported_counter = 0;
        foreach ($apps->getRows() as $row) {
            try {
                $app = $this->getWorkbench()->getApp($row['ALIAS']);
            } catch (AppNotFoundError $e) {
                $workbench = $this->getWorkbench();
                $name_resolver = NameResolver::createFromString($row['ALIAS'], NameResolver::OBJECT_TYPE_APP, $workbench);
                $this->getApp()->createAppFolder($name_resolver);
                $app = $this->getWorkbench()->getApp($row['ALIAS']);
            }
            $this->exportModel($app);
            $exported_counter ++;
        }
        
        // Save the result and output a message for the user
        $this->setResult('');
        $this->setResultMessage('Exported model files for ' . $exported_counter . ' apps to app-folders in "' . $this->getExportToPathRelative() . '".');
        
        return;
    }

    /**
     *
     * @throws ActionInputInvalidObjectError
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function getInputAppsDataSheet()
    {
        if ($this->getInputDataSheet() && ! $this->getInputDataSheet()->isEmpty() && ! $this->getInputDataSheet()->getMetaObject()->isExactly('exface.Core.APP')) {
            throw new ActionInputInvalidObjectError($this, 'Action "' . $this->getAlias() . '" exprects an exface.Core.APP as input, "' . $this->getInputDataSheet()->getMetaObject()->getAliasWithNamespace() . '" given instead!', '6T5TUR1');
        }
        
        $apps = $this->getInputDataSheet()->copy();
        $apps->getColumns()->addFromExpression('ALIAS');
        if (! $apps->isFresh()) {
            if (! $apps->isEmpty()) {
                $apps->addFilterFromColumnValues($apps->getUidColumn()->getValues(false));
            }
            $apps->dataRead();
        }
        return $apps;
    }

    protected function exportModel(AppInterface $app)
    {
        $this->getApp()->filemanager()->mkdir($this->getModelFolderPathAbsolute($app));
        $this->getApp()->filemanager()->emptyDir($this->getModelFolderPathAbsolute($app));
        
        // Fetch all model data in form of data sheets
        $sheets = $this->getModelDataSheets($app);
        
        // Save each data sheet as a file and additionally compute the modification date of the last modified model instance and
        // the MD5-hash of the entire model definition (concatennated contents of all files). This data will be stored in the composer.json
        // and used in the installation process of the package
        $last_modification_time = '0000-00-00 00:00:00';
        $model_string = '';
        foreach ($sheets as $nr => $ds) {
            $model_string .= $this->exportModelFile($app, $ds, $nr . '_');
            $time = $ds->getColumns()->getByAttribute($ds->getMetaObject()->getAttribute('MODIFIED_ON'))->aggregate(EXF_AGGREGATOR_MAX);
            $last_modification_time = $time > $last_modification_time ? $time : $last_modification_time;
        }
        
        // Save some information about the package in the extras of composer.json
        $package_props = array(
            'app_uid' => $app->getUid(),
            'app_alias' => $app->getAliasWithNamespace(),
            'model_md5' => md5($model_string),
            'model_timestamp' => $last_modification_time
        );
        $composer_json = $this->getApp()->getComposerJson($app);
        $composer_json['extra']['app'] = $package_props;
        $this->getApp()->setComposerJson($app, $composer_json);
        
        return $this;
    }

    /**
     *
     * @param AppInterface $app            
     * @return DataSheetInterface[]
     */
    public function getModelDataSheets(AppInterface $app)
    {
        $sheets = array();
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.APP'), 'UID');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT_BEHAVIORS'), 'OBJECT__APP');
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
     * @param Object $object            
     * @param string $app_filter_attribute_alias            
     * @return DataSheetInterface
     */
    protected function getObjectDataSheet(AppInterface $app, Object $object, $app_filter_attribute_alias, array $exclude_attribute_aliases = array())
    {
        $ds = DataSheetFactory::createFromObject($object);
        foreach ($object->getAttributeGroup('~ALL')->getAttributes() as $attr) {
            if (in_array($attr->getAlias(), $exclude_attribute_aliases))
                continue;
            $ds->getColumns()->addFromAttribute($attr);
        }
        $ds->addFilterFromString($app_filter_attribute_alias, $app->getUid());
        $ds->getSorters()->addFromString('CREATED_ON', 'ASC');
        $ds->getSorters()->addFromString($object->getUidAlias(), 'ASC');
        $ds->dataRead();
        return $ds;
    }

    /**
     * Writes the UXON representation of a data sheet, that contains all instances of the given object to a file and
     * returns the file contents.
     * The $app_filter_attribute_alias is used to create a filter for the data sheet that makes sure only those instances
     * assotiated with the given app are exported.
     *
     * @param AppInterface $app            
     * @param Object $object            
     * @param string $app_filter_attribute_alias            
     * @return string
     */
    protected function exportModelFile(AppInterface $app, DataSheetInterface $data_sheet, $filename_prefix = null)
    {
        $contents = $data_sheet->toUxon();
        if (! $data_sheet->isEmpty()) {
            $this->getApp()->filemanager()->dumpFile($this->getModelFolderPathAbsolute($app) . DIRECTORY_SEPARATOR . $filename_prefix . $data_sheet->getMetaObject()->getAlias() . '.json', $contents);
            return $contents;
        }
        return '';
    }

    protected function getModelFolderPathAbsolute(AppInterface $app)
    {
        return $this->getApp()->getPathToAppAbsolute($app, $this->getExportToPathRelative()) . DIRECTORY_SEPARATOR . PackageManagerApp::FOLDER_NAME_MODEL;
    }

    public function getExportToPathRelative()
    {
        return $this->export_to_path_relative;
    }

    public function setExportToPathRelative($value)
    {
        $this->export_to_path_relative = $value;
        return $this;
    }

    /**
     *
     * @return PackageManagerApp
     * @see \exface\Core\Interfaces\Actions\ActionInterface::getApp()
     */
    public function getApp()
    {
        return parent::getApp();
    }
}
?>