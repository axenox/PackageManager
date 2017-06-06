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

/**
 * This action installs one or more apps including their meta model, custom installer, etc.
 *
 * @method PackageManagerApp get_app()
 *        
 * @author Andrej Kabachnik
 *        
 */
class InstallApp extends AbstractAction
{

    private $target_app_aliases = array();

    protected function init()
    {
        $this->setIconName('repair');
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
    }

    protected function perform()
    {
        $exface = $this->getWorkbench();
        $installed_counter = 0;
        foreach ($this->getTargetAppAliases() as $app_alias) {
            $this->addResultMessage("Installing " . $app_alias . "...\n");
            $app_name_resolver = NameResolver::createFromString($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
            try {
                $installed_counter ++;
                $this->install($app_name_resolver);
            } catch (\Exception $e) {
                $installed_counter --;
                // FIXME Log the error somehow instead of throwing it. Otherwise the user will not know, which apps actually installed OK!
                throw $e;
            }
            $this->addResultMessage("\n" . $app_alias . " successfully installed.\n");
        }
        
        if (count($this->getTargetAppAliases()) == 0) {
            $this->addResultMessage('No installable apps had been selected!');
        } elseif ($installed_counter == 0) {
            $this->addResultMessage('No apps have been installed');
        }
        
        $this->getWorkbench()->clearCache();
        
        // Save the result
        $this->setResult('');
        
        return;
    }

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
    public function install(NameResolverInterface $app_name_resolver)
    {
        $result = '';
        
        $app = AppFactory::create($app_name_resolver);
        $installer = $app->getInstaller(new MetaModelInstaller($app_name_resolver));
        $installer_result = $installer->install($this->getAppAbsolutePath($app_name_resolver));
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
}
?>