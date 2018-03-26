<?php
namespace axenox\PackageManager;

use exface\Core\Interfaces\AppInterface;
use exface\Core\Factories\AppFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\InstallerInterface;
use exface\Core\CommonLogic\Model\App;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;

class PackageManagerApp extends App
{

    const FOLDER_NAME_MODEL = 'Model';

    public function filemanager()
    {
        return $this->getWorkbench()->filemanager();
    }

    public function createAppFolder(AppSelectorInterface $app_selector)
    {
        
        // Make sure the vendor folder exists
        $app_vendor_folder = $this->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR . $app_selector->getVendorAlias();
        if (! is_dir($app_vendor_folder)) {
            mkdir($app_vendor_folder);
        }
        
        // Create the app folder
        $app_folder = $this->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR . $app_selector->getAlias();
        if (! is_dir($app_folder)) {
            mkdir($app_folder);
        }
        
        $app = AppFactory::create($app_selector);
        
        $this->createComposerJson($app);
    }

    /**
     *
     * @param AppInterface $app            
     * @return array
     */
    protected function createComposerJson(AppInterface $app)
    {
        $json = array(
            "name" => $app->getVendor() . '/' . str_replace($app->getVendor() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '', $app->getAliasWithNamespace()),
            "require" => array(
                "exface/core" => '~0.1'
            ),
            "autoload" => [
                "psr-4" => [
                    "\\" . str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, "\\", $app->getAliasWithNamespace()) . "\\" => ""
                ],
                "exclude-from-classmap" => [
                    "/Config/",
                    "/Translations/",
                    "/Model/"
                ]
            ]
        );
        
        return $json;
    }

    /**
     *
     * @param AppInterface $app            
     * @return array
     */
    public function getComposerJson(AppInterface $app)
    {
        $file_path = $this->getPathToComposerJson($app);
        if (file_exists($file_path)) {
            $json = json_decode(file_get_contents($file_path), true);
        } else {
            $json = $this->createComposerJson($app);
            $this->setComposerJson($app, $json);
        }
        return $json;
    }

    /**
     *
     * @param AppInterface $app            
     * @param array $json_object            
     * @return \axenox\PackageManager\PackageManagerApp
     */
    public function setComposerJson(AppInterface $app, array $json_object)
    {
        $file_path = $this->getPathToComposerJson($app);
        $this->filemanager()->dumpFile($file_path, json_encode($json_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $this;
    }

    public function getPathToComposerJson(AppInterface $app)
    {
        return $this->getPathToAppAbsolute($app) . DIRECTORY_SEPARATOR . 'composer.json';
    }

    /**
     * Returns the path to the
     *
     * @param AppInterface $app            
     * @return string
     */
    public function getPathToAppRelative(AppInterface $app = null, $base_path = '')
    {
        if (! $base_path) {
            $base_path = Filemanager::FOLDER_NAME_VENDOR . DIRECTORY_SEPARATOR;
        }
        return $base_path . ($app ? $app->getVendor() . DIRECTORY_SEPARATOR . str_replace($app->getVendor() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '', $app->getAlias()) : '');
    }

    public function getPathToAppAbsolute(AppInterface $app = null, $base_path = '')
    {
        return $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $this->getPathToAppRelative($app, $base_path);
    }

    public function getInstalledVersion($app_alias)
    {
        $package_object = $this->getWorkbench()->model()->getObject('axenox.PackageManager.PACKAGE_INSTALLED');
        $data_sheet = DataSheetFactory::createFromObject($package_object);
        $data_sheet->getColumns()->addFromExpression('version');
        $data_sheet->addFilterFromString('name', $this->getPackageNameFromAppAlias($app_alias));
        $data_sheet->dataRead();
        return $data_sheet->getCellValue('version', 0);
    }

    public static function getPackageNameFromAppAlias($app_alias)
    {
        return str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $app_alias);
    }

    public static function getAppAliasFromPackageName($package_name)
    {
        return str_replace('/', AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, $package_name);
    }

    public function getCurrentAppVersion($app_alias)
    {
        $exface = $this->getWorkbench();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($exface, 'Axenox.PackageManager.PACKAGE_INSTALLED');
        $ds->getFilters()->addConditionsFromString($ds->getMetaObject(), 'name', $this->getPackageNameFromAppAlias($app_alias));
        $ds->getColumns()->addFromExpression('version');
        $ds->dataRead();
        return $ds->getCellValue('version', 0);
    }

    /**
     * The installer of the package manager app will perform some additional actions like setting up composer.json to run the
     * required postprocessing, etc.
     *
     * {@inheritdoc}
     *
     * @see App::getInstaller($injected_installer)
     */
    public function getInstaller(InstallerInterface $injected_installer = null)
    {
        $installer = parent::getInstaller($injected_installer);
        $installer->addInstaller(new PackageManagerInstaller($this->getSelector()));
        return $installer;
    }
}
?>