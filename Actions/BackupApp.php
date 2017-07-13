<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\PackageManagerApp;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\NameResolverInterface;
use exface\Core\Factories\AppFactory;
use exface\Core\Exceptions\DirectoryNotFoundError;
use axenox\PackageManager\MetaModelInstaller;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\Constants\Icons;

/**
 * This action installs one or more apps including their meta model, custom installer, etc.
 *
 * @method PackageManagerApp getApp()
 *        
 * @author Andrej Kabachnik
 *        
 */
class BackupApp extends AbstractAction
{

    private $target_app_aliases = array();

    private $backup_path = '';

    protected function init()
    {
        $this->setIconName(Icons::WRENCH);
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }

    protected function perform()
    {
        $exface = $this->getWorkbench();
        $backup_counter = 0;
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $this->addResultMessage("Creating Backup for " . $app_alias . "...\n");
            $app_name_resolver = NameResolver::createFromString($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
            try {
                $backup_counter ++;
                $this->backup($app_name_resolver);
            } catch (\Exception $e) {
                $backup_counter --;
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
            $this->addResultMessage("\n Sucessfully created backup for " . $app_alias . " .\n");
        }
        
        if (count($this->getTargetAppAliases()) == 0) {
            $this->addResultMessage('No apps had been selected for backup!');
        } elseif ($backup_counter == 0) {
            $this->addResultMessage('No backups have been created');
        }
        
        // Save the result
        $this->setResult('');
        
        return;
    }

    /**
     * Get all affected apps
     *
     * @return array
     * @throws ActionInputInvalidObjectError
     */
    public function getTargetAppAliases()
    {
        if (count($this->target_app_aliases) < 1 && $this->getInputDataSheet()) {
            
            if ($this->getInputDataSheet()->getMetaObject()->isExactly('exface.Core.APP')) {
                $this->getInputDataSheet()->getColumns()->addFromExpression('ALIAS');
                if (! $this->getInputDataSheet()->isEmpty()) {
                    if (! $this->getInputDataSheet()->isFresh()) {
                        $this->getInputDataSheet()->dataRead();
                    }
                } elseif (! $this->getInputDataSheet()->getFilters()->isEmpty()) {
                    $this->getInputDataSheet()->dataRead();
                }
                $this->target_app_aliases = array_unique($this->getInputDataSheet()->getColumnValues('ALIAS', false));
            } elseif ($this->getInputDataSheet()->getMetaObject()->isExactly('axenox.PackageManager.PACKAGE_INSTALLED')) {
                $this->getInputDataSheet()->getColumns()->addFromExpression('app_alias');
                if (! $this->getInputDataSheet()->isEmpty()) {
                    if (! $this->getInputDataSheet()->isFresh()) {
                        $this->getInputDataSheet()->dataRead();
                    }
                } elseif (! $this->getInputDataSheet()->getFilters()->isEmpty()) {
                    $this->getInputDataSheet()->dataRead();
                }
                $this->target_app_aliases = array_filter(array_unique($this->getInputDataSheet()->getColumnValues('app_alias', false)));
            } else {
                throw new ActionInputInvalidObjectError($this, 'The action "' . $this->getAliasWithNamespace() . '" can only be called on the meta objects "exface.Core.App" or "axenox.PackageManager.PACKAGE_INSTALLED" - "' . $this->getInputDataSheet()->getMetaObject()->getAliasWithNamespace() . '" given instead!');
            }
        }
        
        return $this->target_app_aliases;
    }

    public function setTargetAppAliases(array $values)
    {
        $this->target_app_aliases = $values;
        return $this;
    }

    /**
     *
     * @param NameResolverInterface $app_name_resolver            
     * @return string
     */
    public function backup(NameResolverInterface $app_name_resolver)
    {
        $result = '';
        
        $app = AppFactory::create($app_name_resolver);
        
        $installer = $app->getInstaller(new MetaModelInstaller($app_name_resolver));
        $appAlias = $app_name_resolver->getAlias();
        $directory = $app_name_resolver->getClassDirectory();
        if ($this->getBackupPath() == '') {
            $backupDir = $app->getWorkbench()->filemanager()->getPathToBackupFolder();
            $sDirName = $appAlias . "_backup_" . date('Y_m_d_H');
            $backupDir .= $directory . DIRECTORY_SEPARATOR . $sDirName;
        } else {
            $backupDir = $app->getWorkbench()->filemanager()->getPathToBaseFolder();
            $backupDir .= DIRECTORY_SEPARATOR . $this->getBackupPath() . $directory;
        }
        $backupDir = $app->getWorkbench()->filemanager()->pathNormalize($backupDir, DIRECTORY_SEPARATOR);
        
        $installer_result = $installer->backup($backupDir);
        $result .= $installer_result . (substr($installer_result, - 1) != '.' ? '.' : '');
        
        // Save the result
        $this->addResultMessage($result);
        return $result;
    }

    /**
     *
     * @param NameResolverInterface $app_name_resolver            
     * @throws DirectoryNotFoundError
     * @return string
     */
    public function getAppAbsolutePath(NameResolverInterface $app_name_resolver)
    {
        $app_path = $this->getApp()->filemanager()->getPathToVendorFolder() . $app_name_resolver->getClassDirectory();
        if (! file_exists($app_path) || ! is_dir($app_path)) {
            throw new DirectoryNotFoundError('"' . $app_path . '" does not point to an installable app!', '6T5TZN5');
        }
        return $app_path;
    }

    /**
     * Set path to backup to different location
     *
     * @uxon-property backup_path
     * @uxon-type string
     */
    public function setBackupPath($value)
    {
        $this->backup_path = $value;
    }

    public function getBackupPath()
    {
        return $this->backup_path;
    }
}
?>