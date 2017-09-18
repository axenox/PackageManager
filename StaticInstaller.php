<?php
namespace axenox\PackageManager;

use Composer\Installer\PackageEvent;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\NameResolver;
use axenox\PackageManager\MetaModelInstaller;
use axenox\PackageManager\Actions\BackupApp;
use Composer\Script\Event;
use Symfony\Component\Config\Definition\Exception\Exception;

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'exface' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'CommonLogic' . DIRECTORY_SEPARATOR . 'Workbench.php';

/**
 * The app installer is a simplified wrapper for the package manager actions, which simplifies installing apps from outside of
 * ExFace - in particular StaticInstaller::composerFinishUpdate() can be used as a script in composer to perform the app specific
 * installatiom automatically once composer is done installing or updating all the files.
 *
 * @author Andrej Kabachnik
 *
 */
class StaticInstaller
{

    const PACKAGE_MANAGER_APP_ALIAS = 'axenox.PackageManager';

    const PACKAGE_MANAGER_INSTALL_ACTION_ALIAS = 'InstallApp';

    const PACKAGE_MANAGER_UNINSTALL_ACTION_ALIAS = 'UninstallApp';

    const PACKAGE_MANAGER_BACKUP_ACTION_ALIAS = 'BackupApp';

    const EXCLUDE_BACKUP_PACKAGES = ["composer","symfony"];

    private $workbench = null;

    /**
     *
     * @param PackageEvent $composer_event
     * @return void
     */
    public static function composerFinishPackageInstall(PackageEvent $composer_event)
    {
        $app_alias = self::composerGetAppAliasFromExtras($composer_event->getOperation()->getPackage()->getExtra());
        if ($app_alias) {
            self::addAppToTempFile('install', $app_alias);
        }
    }

    public static function composerBackupEverything(Event $composer_event = null){
        $composerContent = file_get_contents('composer.json');
        $obj = json_decode($composerContent);
        $installer = new self();

        //write consistent backuptime to delete excess data after update run
        $backupTime = date('Y_m_d_H_i');
        $temp = self::getTempFile();
        $temp['backupTime'] = $backupTime;
        self::setTempFile($temp);


        foreach($obj->require as $requiredPackage => $packageInfo){
            $appIdentifier = explode("/",$requiredPackage);

            if (!in_array($appIdentifier[0],self::EXCLUDE_BACKUP_PACKAGES)){
                try {
                    $installer->backup(implode(".",$appIdentifier), $backupTime);
                }
                catch(\Exception $e){
                    echo "Could not backup ".implode(".",$appIdentifier).". ".$e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine();
                }
            }
        }
        return $composer_event;
    }

    public function backup($app_alias, $backupTime){
        $exface = $this->getWorkbench();
        $app_name_resolver = NameResolver::createFromString($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
        $backupAction = $exface->getApp(self::PACKAGE_MANAGER_APP_ALIAS)->getAction(self::PACKAGE_MANAGER_BACKUP_ACTION_ALIAS);

        $backupAction->setBackupPath(Filemanager::FOLDER_NAME_BACKUP.DIRECTORY_SEPARATOR.$backupTime);
        $backupAction->backup($app_name_resolver);
    }
    /**
     *
     * @param PackageEvent $composer_event
     * @return void
     */
    public static function composerFinishPackageUpdate(PackageEvent $composer_event)
    {
        $app_alias = self::composerGetAppAliasFromExtras($composer_event->getOperation()->getTargetPackage()->getExtra());
        if ($app_alias) {
            self::addAppToTempFile('update', $app_alias);
        }
    }

    /**
     *
     * @param Event $composer_event
     * @return string
     */
    public static function composerFinishInstall(Event $composer_event = null)
    {
        $text = '';
        $processed_aliases = array();
        $temp = self::getTempFile();
        if (array_key_exists('install', $temp)) {
            foreach ($temp['install'] as $app_alias) {
                if (! in_array($app_alias, $processed_aliases)) {
                    $processed_aliases[] = $app_alias;
                } else {
                    continue;
                }
                $result = self::install($app_alias);
                $text .= '-> Installing app "' . $app_alias . '": ' . ($result ? trim($result, ".") : 'Nothing to do') . ".\n";
                self::printToStdout($text);
            }
            unset($temp['install']);
            self::setTempFile($temp);
        }
        return $text ? $text : 'No apps to install' . ".\n";
    }


    /**
     *
     * @param Event $composer_event
     * @return string
     */
    public static function composerFinishUpdate(Event $composer_event = null)
    {
        $text = '';
        $processed_aliases = array();
        $temp = self::getTempFile();

        if (array_key_exists('update', $temp)) {
            // First of all check, if the core needs to be updated. If so, do that before updating other apps
            if (in_array(self::getCoreAppAlias(), $temp['update'])) {
                if (! in_array(self::getCoreAppAlias(), $processed_aliases)) {
                    $processed_aliases[] = self::getCoreAppAlias();
                    $result = self::install(self::getCoreAppAlias());
                    $text .= '-> Updating app "' . self::getCoreAppAlias() . '": ' . ($result ? $result : 'Nothing to do') . ".\n";
                    //self::printToStdout($text);
                }
            }

            // Now that the core is up to date, we can update the others
            foreach ($temp['update'] as $app_alias) {
                if (! in_array($app_alias, $processed_aliases)) {
                    $processed_aliases[] = $app_alias;
                } else {
                    continue;
                }
                //@todo This part breaks the process when it tries to install apps that have no working installer. It desperately needs to be fixed.
//                try {
//                    $result = self::install($app_alias);
//                    print_r($result);
//                    $text .= '-> Updating app "' . $app_alias . '": ' . ($result ? $result : 'Nothing to do') . ".\n";
//                    self::printToStdout($text);
//                }
//                catch(\Exception $e){
//                    echo "Could not install ".$app_alias.". ".$e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine();
//                }
            }
            $composerContent = file_get_contents('composer.json');
            $obj = json_decode($composerContent);
            $backupTime = $temp['backupTime'];
            $installer = new self();
            foreach($obj->require as $requiredPackage => $packageInfo){
                $appIdentifier = explode("/",$requiredPackage);

                if (!in_array($appIdentifier[0],self::EXCLUDE_BACKUP_PACKAGES) &&
                    !in_array(implode(".",$appIdentifier),$temp['update'])){
                        print_r("Delete unused ".implode(".",$appIdentifier)." app backup\n");
                    try {
                        $result = $installer->unlinkBackup(implode(".",$appIdentifier),$backupTime);
                    }
                    catch(\Exception $e){
                        echo "Could not delete ".implode(".",$appIdentifier).". ".$e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine() . ".\n";
                    }
                }
            }
            unset($temp['update']);
            if ($result){
                echo "Cleared backup from excess data. \n";
                self::setTempFile($temp);
            }
            else {
                echo "Could not clear backup. Please unlink manually. Check LastInstall.temp.json for list of apps to keep. \n";
            }
        }

        // If composer is performing an update operation, it will install new packages, but will not trigger the post-install-cmd
        // As a workaround, we just trigger finish_install() here by hand
        if (array_key_exists('install', $temp)) {
            $text .= self::composerFinishInstall();
        }

        return $text ? $text : 'No apps to update' . ".\n";
    }
    public function unlinkBackup($app_alias, $backupTime){
        $exface = $this->getWorkbench();
        $app = $exface->getApp(self::PACKAGE_MANAGER_APP_ALIAS);
        $link = $app->getWorkbench()->filemanager()->getPathToBaseFolder().DIRECTORY_SEPARATOR.Filemanager::FOLDER_NAME_BACKUP.DIRECTORY_SEPARATOR.$backupTime.DIRECTORY_SEPARATOR.str_replace(".",DIRECTORY_SEPARATOR,$app_alias);
        self::deleteDir($link);
        $parentLink = explode(".",$app_alias);
        $parentLink = $app->getWorkbench()->filemanager()->getPathToBaseFolder().DIRECTORY_SEPARATOR.Filemanager::FOLDER_NAME_BACKUP.DIRECTORY_SEPARATOR.$backupTime.DIRECTORY_SEPARATOR.$parentLink[0].DIRECTORY_SEPARATOR;
        if (self::is_dir_empty($parentLink)){
            self::deleteDir($parentLink);
        }
        return true;
    }
    public static function deleteDir($dirPath) {
        if (is_dir($dirPath)) {
            $objects = scandir($dirPath);
            foreach ($objects as $object) {
                if ($object != "." && $object !="..") {
                    if (filetype($dirPath . DIRECTORY_SEPARATOR . $object) == "dir") {
                        self::deleteDir($dirPath . DIRECTORY_SEPARATOR . $object);
                    } else {
                        unlink($dirPath . DIRECTORY_SEPARATOR . $object);
                    }
                }
            }
            reset($objects);
            rmdir($dirPath);
        }
    }
    public static function is_dir_empty($dir) {
        if (!is_readable($dir)) return NULL;
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                return FALSE;
            }
        }
        return TRUE;
    }
    public static function composerPrepareUninstall(PackageEvent $composer_event)
    {
        return self::uninstall($composer_event->getOperation()->getPackage()->getName());
    }

    protected static function composerGetAppAliasFromExtras($extras_array)
    {
        if (is_array($extras_array) && array_key_exists('app', $extras_array) && is_array($extras_array['app']) && array_key_exists('app_alias', $extras_array['app'])) {
            return $extras_array['app']['app_alias'];
        }
        return false;
    }

    public static function install($app_alias)
    {
        $installer = new self();
        return $installer->installApp($app_alias);
    }

    public static function uninstall($app_alias)
    {
        // TODO
    }

    public function installApp($app_alias)
    {
        $result = '';
        try {
            $exface = $this->getWorkbench();
            $app_name_resolver = NameResolver::createFromString($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
            $result = $exface->getApp(self::PACKAGE_MANAGER_APP_ALIAS)->getAction(self::PACKAGE_MANAGER_INSTALL_ACTION_ALIAS)->install($app_name_resolver);
        } catch (\Exception $e) {
            print_r($e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine());
        }
        return $result;
    }

    public function uninstallApp($app_alias)
    {
        $result = '';
        try {
            $exface = $this->getWorkbench();
            $app_name_resolver = NameResolver::createFromString($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
            $result = $exface->getApp(self::PACKAGE_MANAGER_APP_ALIAS)->getAction(self::PACKAGE_MANAGER_UNINSTALL_ACTION_ALIAS)->uninstall($app_name_resolver);
        } catch (\Exception $e) {
            $result = $e->getMessage();
        }
        return $result;
    }

    /**
     *
     * @return Workbench
     */
    protected function getWorkbench()
    {
        if (is_null($this->workbench)) {
            error_reporting(E_ALL ^ E_NOTICE);
            $this->workbench = Workbench::startNewInstance();
        }
        return $this->workbench;
    }

    protected static function getTempFilePathAbsolute()
    {
        return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'LastInstall.temp.json';
    }

    /**
     *
     * @return array
     */
    protected static function getTempFile()
    {
        $json_array = array();
        $filename = self::getTempFilePathAbsolute();
        if (file_exists($filename)) {
            $json_array = json_decode(file_get_contents($filename), true);
        }
        return $json_array;
    }

    /**
     *
     * @param array $json_array
     */
    protected static function setTempFile(array $json_array)
    {
        if (count($json_array) > 0) {
            return file_put_contents(self::getTempFilePathAbsolute(), json_encode($json_array, JSON_PRETTY_PRINT));
        } elseif (file_exists(self::getTempFilePathAbsolute())) {
            return unlink(self::getTempFilePathAbsolute());
        }
    }

    /**
     *
     * @param string $operation
     * @param string $app_alias
     * @return array
     */
    protected static function addAppToTempFile($operation, $app_alias)
    {
        $temp_file = self::getTempFile();
        $temp_file[$operation][] = $app_alias;
        self::setTempFile($temp_file);
        return $temp_file;
    }

    protected static function printToStdout($text)
    {
        if (is_resource(STDOUT)) {
            fwrite(STDOUT, $text);
            return true;
        }
        return false;
    }

    public static function getCoreAppAlias()
    {
        return 'exface.Core';
    }
}
?>