<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Exceptions\AppNotFoundError;
use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use axenox\PackageManager\MetaModelInstaller;

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
        
        $workbench = $this->getWorkbench();
        $exported_counter = 0;
        foreach ($apps->getRows() as $row) {
            try {
                $app = $this->getWorkbench()->getApp($row['ALIAS']);
            } catch (AppNotFoundError $e) {
                $name_resolver = NameResolver::createFromString($row['ALIAS'], NameResolver::OBJECT_TYPE_APP, $workbench);
                $this->getApp()->createAppFolder($name_resolver);
                $app = $this->getWorkbench()->getApp($row['ALIAS']);
            }
            
            $app_name_resolver = NameResolver::createFromString($row['ALIAS'], NameResolver::OBJECT_TYPE_APP, $workbench);
            $installer = new MetaModelInstaller($app_name_resolver);
            $backupDir = $this->getModelFolderPathAbsolute($app);
            $installer->backup($backupDir);
            
            $exported_counter ++;
        }
        
        // Save the result and output a message for the user
        $this->setResult('');
        $this->setResultMessage('Exported model files for ' . $exported_counter . ' apps to app-folders into ' . ($this->getExportToPathRelative() ? '"' . $this->getExportToPathRelative() . '"' : ' the respecitve app folders') . '.');
        
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

    protected function getModelFolderPathAbsolute(AppInterface $app)
    {
        return $this->getApp()->getPathToAppAbsolute($app, $this->getExportToPathRelative());
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