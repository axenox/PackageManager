<?php
namespace axenox\PackageManager;

use Composer\Installer\PackageEvent;
use exface\Core\CommonLogic\DataSheets\DataSheet;
use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\NameResolver;
use Composer\Script\Event;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
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

    private $workbench = null;

    const EXCLUDE_BACKUP_PACKAGES = [
        //"axenox.RedmineConnector"
    ];

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
        $updatedPackages = array();
        $temp = self::getTempFile();
        if (array_key_exists('update', $temp)) {
            // First of all check, if the core needs to be updated. If so, do that before updating other apps
            if (in_array(self::getCoreAppAlias(), $temp['update'])) {
                if (! in_array(self::getCoreAppAlias(), $processed_aliases)) {
                    $processed_aliases[] = self::getCoreAppAlias();
                    $result = self::install(self::getCoreAppAlias());
                    $text .= '-> Updating app "' . self::getCoreAppAlias() . '": ' . ($result ? $result : 'Nothing to do') . ".\n";
                    self::printToStdout($text);
                }
            }
            // Now that the core is up to date, we can update the others
            foreach ($temp['update'] as $app_alias) {
                if (! in_array($app_alias, $processed_aliases)) {
                    $processed_aliases[] = $app_alias;
                } else {
                    continue;
                }
                $result = self::install($app_alias);
                $text .= '-> Updating app "' . $app_alias . '": ' . ($result ? $result : 'Nothing to do') . ".\n";
                self::printToStdout($text);
            }
        }
        if ($temp['update']!=null){
            $updatedPackages = $temp['update'];
        }
        self::printToStdout("Unlink unused backup components:\n");
        $installer = new self();
        $apps = $installer->getAllApps();
        $backupTime = $temp['backupTime'];
        $unlinkResult = array();

        foreach($apps as $app){

            if (!in_array($app,self::EXCLUDE_BACKUP_PACKAGES) &&
                !in_array($app,$updatedPackages)){
                $unlinkResult[] = $installer->unlinkBackup($app,$backupTime);
            }
        }
        $installer->copyTempFile($backupTime);
        unset($temp['update']);
        if (!in_array(false,$unlinkResult)){
            self::printToStdout("Cleared backup from excess data. \n");
            self::setTempFile($temp);
        }
        else {
            self::printToStdout("Could not clear backup. Please unlink manually. Check LastInstall.temp.json for list of apps to keep. \n");
        }
        // If composer is performing an update operation, it will install new packages, but will not trigger the post-install-cmd
        // As a workaround, we just trigger finish_install() here by hand
        if (array_key_exists('install', $temp)) {
            $text .= self::composerFinishInstall();
        }
        
        return $text ? $text : 'No apps to update' . ".\n";
    }
    /**
     * Unlink backup from specified backup folder, folder name is defined by backupTime-String
     *
     * @param string $app_alias
     * @param string $backupTime
     * @return boolean return TRUE if unlinking BackUp was successful, return FALSE if it was not
     */
    public function unlinkBackup($app_alias, $backupTime){
        $exface = $this->getWorkbench();
        $app = $exface->getApp(self::PACKAGE_MANAGER_APP_ALIAS);
        try {
            $link = $app->getWorkbench()->filemanager()->getPathToBaseFolder().DIRECTORY_SEPARATOR."autobackup".DIRECTORY_SEPARATOR.$backupTime.DIRECTORY_SEPARATOR.str_replace(".",DIRECTORY_SEPARATOR,$app_alias);
            Filemanager::deleteDir($link);
            // Delete Parent folders to avoid clutter, provided that they are in fact empty
            $parentLink = explode(".",$app_alias);
            $parentLink = $app->getWorkbench()->filemanager()->getPathToBaseFolder().DIRECTORY_SEPARATOR."autobackup".DIRECTORY_SEPARATOR.$backupTime.DIRECTORY_SEPARATOR.$parentLink[0].DIRECTORY_SEPARATOR;
            if (Filemanager::isDirEmpty($parentLink)){
                Filemanager::deleteDir($parentLink);
            }
            self::printToStdout("Delete unused ".$app_alias." app backup\n");
        }
        catch(\Exception $e){
            self::printToStdout("Could not delete ".$app_alias.". ".$e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine() . ".\n");
            return false;
        }
        return true;
    }
    /**
     * Backup all apps that are listen in composer.json but not listed in EXCLUDE_BACKUP_PACKAGES
     * since they have no own backup function to call to
     *
     * @param Event $composer_event
     * @return Event $composer_event
     */
    public static function composerBackupEverything(Event $composer_event = null){
        $installer = new self();
        $apps = $installer->getAllApps();
         //write consistent backuptime to delete excess data after update run
        $backupTime = date('Y_m_d_H_i');
        $temp = self::getTempFile();
        $temp['backupTime'] = $backupTime;
        self::setTempFile($temp);
        foreach( $apps as $app){
            if (!in_array($app,self::EXCLUDE_BACKUP_PACKAGES)){
                $installer->backup($app, $backupTime);
            }
        }
        return $composer_event;
    }

    public function getAllApps(){
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.APP');
        $result = $ds->dataRead();
        $row = $ds->getRows();
        $apps = array();
        foreach ($row as $fld => $val) {
            $app = $this->getWorkbench()->getApp($val['UID']);
            $apps[] = $app->getAliasWithNamespace();
        }
        return $apps;
    }
    /**
     * Call backup function on app, install at specified backup folder, folder name is defined by backupTime-String
     * @param string $app_alias
     * @param string $backupTime
     * @return string
     */
    public function backup($app_alias, $backupTime){
        $exface = $this->getWorkbench();
        $result = '';
        try {
            $text = '-> Creating backup of app ' . $app_alias . "\n";
            self::printToStdout($text);
            $app_name_resolver = NameResolver::createFromString($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
            $backupAction = $exface->getApp(self::PACKAGE_MANAGER_APP_ALIAS)->getAction(self::PACKAGE_MANAGER_BACKUP_ACTION_ALIAS);
            $backupAction->setBackupPath("autobackup".DIRECTORY_SEPARATOR.$backupTime);
            $backupAction->backup($app_name_resolver);
        }
        catch(\Exception $e){
            if ($e instanceof ExceptionInterface){
                $log_hint = ' (see log ID ' . $e->getId() . ')';
            }
            self::printToStdout('-> Error in ' . $app_alias . "! \n");
            self::printToStdout($e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine() . $log_hint."\n");
            //$exface->getLogger()->logException($e);
        }
      return $result;
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
            if ($e instanceof ExceptionInterface){
                $log_hint = ' (see log ID ' . $e->getId() . ')';
            }
            $result .= $e->getMessage() . ' in ' . $e->getFile() . ' at line ' . $e->getLine() . $log_hint;
            $exface->getLogger()->logException($e);
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
            if ($e instanceof ExceptionInterface){
                $log_hint = ' (see log ID ' . $e->getId() . ')';
            }
            $result .= $e->getMessage() . $log_hint;
            $exface->getLogger()->logException($e);
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
    protected function copyTempFile($backuptime){
        $exface = $this->getWorkbench();
        $exface->filemanager()->copy(self::getTempFilePathAbsolute(),$exface->filemanager()->getPathToBaseFolder().DIRECTORY_SEPARATOR."autobackup".DIRECTORY_SEPARATOR.$backuptime.DIRECTORY_SEPARATOR."LastInstall.json");
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