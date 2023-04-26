<?php
namespace axenox\PackageManager;

use Composer\Installer\PackageEvent;
use exface\Core\CommonLogic\Workbench;
use Composer\Script\Event;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\ActionFactory;
use axenox\PackageManager\Actions\ListApps;

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

    const PACKAGE_MANAGER_INSTALL_ACTION_ALIAS = 'axenox.PackageManager.InstallApp';

    const PACKAGE_MANAGER_BACKUP_ACTION_ALIAS = 'axenox.PackageManager.BackupApp';

    const PACKAGE_MANAGER_UNINSTALL_ACTION_ALIAS = 'axenox.PackageManager.UninstallApp';
    
    const PACAKGE_MANAGER_GENERATE_LIC_BOM_ALIAS = 'axenox.PackageManger.GenerateLicenseBOM';

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
        try {
            self::printToStdout('-> Installing "' . self::getCoreAppAlias() . '": ' . PHP_EOL . PHP_EOL);
            $result = self::install(self::getCoreAppAlias());
            self::printToStdout(($result ? $result : 'Nothing to do') . "." . PHP_EOL);
        } catch (\Throwable $e) {
            self::printToStdout('FAILED to install "' . self::getCoreAppAlias() . '": ' . $result . "." . PHP_EOL);
            self::printException($e);
        }
        
        self::printToStdout("Searching for apps in vendor-folder...");
        $vendorBase = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..';
        $installedAppAliases = ListApps::findAppAliasesInVendorFolders($vendorBase);
        self::printToStdout("found " . count($installedAppAliases) . " apps" . PHP_EOL);
        
        foreach ($installedAppAliases as $app_alias) {
            self::printToStdout('-> Installing app "' . $app_alias . '": ' . PHP_EOL . PHP_EOL);
            $result = self::install($app_alias);
            self::printToStdout(($result ? trim($result, ".") : 'Nothing to do') . PHP_EOL);
        }
        
        self::setTempFile([]);
        
        self::generateLicenseBOM();
        
        return empty($installedAppAliases) === true ? "No apps to update.\n" : "Installed " . count($installedAppAliases) . " apps.\n";
    }

    /**
     *
     * @param Event $composer_event
     * @return string
     */
    public static function composerFinishUpdate(Event $composer_event = null)
    {
        self::printToStdout("Running installers for newly installed and updated apps...\n");
        
        $processed_aliases = array();
        $temp = self::getTempFile();
        
        $appAliases = array_key_exists('update', $temp) ? $temp['update'] : [];
        if (array_key_exists('install', $temp) && is_array($temp['install']) === true) {
            // If the package manager is being installed for the first time, run
            // install instead of update
            if (in_array('axenox.PackageManager', $temp['install'])) {
                return self::composerFinishInstall($composer_event);
            }
            $appAliases = array_merge($appAliases, $temp['install']);
        }
        
        // Run installers for updated apps
        if (empty($appAliases) === false) {
            // First of all check, if the core needs to be updated. If so, do that before updating other apps
            if (in_array(self::getCoreAppAlias(), $appAliases)) {
                if (! in_array(self::getCoreAppAlias(), $processed_aliases)) {
                    $processed_aliases[] = self::getCoreAppAlias();
                    self::printToStdout('-> Updating app "' . self::getCoreAppAlias() . '": ' . PHP_EOL . PHP_EOL);
                    $result = self::install(self::getCoreAppAlias());
                    self::printToStdout(($result ? $result : 'Nothing to do') . PHP_EOL);
                }
            }
            // Now that the core is up to date, we can update the others
            foreach ($appAliases as $app_alias) {
                if (! in_array($app_alias, $processed_aliases)) {
                    $processed_aliases[] = $app_alias;
                } else {
                    continue;
                }
                self::printToStdout('-> Updating app "' . $app_alias . '": ' . PHP_EOL . PHP_EOL);
                $result = self::install($app_alias);
                self::printToStdout(($result ? $result : 'Nothing to do') . "." . PHP_EOL);
            }
        }
        if (array_key_exists('update', $temp) && is_array($temp['update'])){
            $updatedPackages = $temp['update'];
        } else {
            $updatedPackages = [];
        }
        
        // Cleanup backup
        if (array_key_exists('backupTime', $temp)) {
            self::printToStdout("Delete unused backup components:" . PHP_EOL);
            $installer = new self();
            $apps = ListApps::findAppAliasesInModel($installer->getWorkbench());
            $backupTime = $temp['backupTime'];
            $unlinkResult = array();
    
            foreach($apps as $app){
    
                if (! in_array($app, $updatedPackages)){
                    $unlinkResult[] = $installer->unlinkBackup($app,$backupTime);
                }
            }
            $installer->copyTempFile($backupTime);
            if (!in_array(false,$unlinkResult)){
                self::printToStdout("Cleared backup from excess data." . PHP_EOL);
            } else {
                self::printToStdout("Could not clear backup." . PHP_EOL);
            }
        }
        
        unset($temp['update']);
        unset($temp['install']);
        self::setTempFile($temp);
        
        self::generateLicenseBOM();

        return empty($processed_aliases) === true ? "No apps to update.\n" : "Updated/installed " . count($processed_aliases) . " apps.\n";
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
            $link = $exface->filemanager()->getPathToBackupFolder().DIRECTORY_SEPARATOR."autobackup".DIRECTORY_SEPARATOR.$backupTime.DIRECTORY_SEPARATOR.str_replace(".",DIRECTORY_SEPARATOR,$app_alias);
            if ($exface->filemanager()->exists($link)){
                $exface->filemanager()->deleteDir($link);
                // Delete Parent folders to avoid clutter, provided that they are in fact empty
                $parentLink = explode(".",$app_alias);
                $parentLink = $app->getWorkbench()->filemanager()->getPathToBackupFolder().DIRECTORY_SEPARATOR."autobackup".DIRECTORY_SEPARATOR.$backupTime.DIRECTORY_SEPARATOR.$parentLink[0].DIRECTORY_SEPARATOR;
                if ($exface->filemanager()->isDirEmpty($parentLink)){
                    $exface->filemanager()->deleteDir($parentLink);
                }
                self::printToStdout('-> '.$app_alias. "Delete unused backups" . PHP_EOL);
            }
            else {

                $text = '-> '.$app_alias. " - Directory can't be found at ".$link.". Check your database for old app definitions that have since been uninstalled.\n\n";
                self::printToStdout($text);
            }

        } catch (\Throwable $e){
            static::printException($e);
            $exface->getLogger()->logException($e);
            return false;
        }
        return true;
    }
    /**
     * Backup all apps
     * since they have no own backup function to call to
     *
     * @param Event $composer_event
     * @return Event $composer_event
     */
    public static function composerBackupEverything(Event $composer_event = null){
        $installer = new self();
        $apps = ListApps::findAppAliasesInModel($installer->getWorkbench());
        //write consistent backuptime to delete excess data after update run
        $backupTime = date('Y_m_d_H_i');
        $temp = self::getTempFile();
        $temp['backupTime'] = $backupTime;
        self::setTempFile($temp);
        $backupPath = $installer->getWorkbench()->filemanager()->getPathToBackupFolder();
        $backupPath = "autobackup".DIRECTORY_SEPARATOR.$backupTime;
        self::printToStdout("Starting automatic backup to ".$backupPath);
        foreach($apps as $app){
            $installer->backup($app, $backupPath);
        }
        return $backupPath;
    }
    
    /**
     * Call backup function on app, install at specified backup folder, folder name is defined by backupTime-String
     * @param string $app_alias
     * @param string $backupTime
     * @return string
     */
    public function backup($app_alias, $backupPath){
        $exface = $this->getWorkbench();
        $text = "-> {$app_alias} being backed up to {$backupPath}...";
        
        try {
            self::printToStdout($text);
            $app_selector = new AppSelector($exface, $app_alias);
            $backupAction = ActionFactory::createFromString($exface, self::PACKAGE_MANAGER_BACKUP_ACTION_ALIAS);
            $backupDir = $exface->filemanager()->getPathToBaseFolder();
            $backupDir .= DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR. str_replace(".",DIRECTORY_SEPARATOR,$app_alias);
            if ($exface->filemanager()->exists($backupDir)){
                $backupAction->setBackupPath($backupPath);
                $backupAction->backup($app_selector);
                $text .= " DONE!";
            }
            else {
                $text .= ' SKIPPED - app not installed correctly?';
                $exface->getLogger()->error("No folder for app {$app_alias} can be found at {$backupDir}. Check your database for old app definitions that have since been uninstalled.");
            }
            self::printToStdout($text);

        } catch (\Throwable $e){
            $text .= ' FAILED!';
            self::printToStdout($text);
            self::printException($e);
            $exface->getLogger()->logException($e);
        }
        return $text;
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
            $app_selector = new AppSelector($exface, $app_alias);
            $action = ActionFactory::createFromString($exface, self::PACKAGE_MANAGER_INSTALL_ACTION_ALIAS);
            $installerResult = $action->installApp($app_selector);
            foreach ($installerResult as $msg) {
                $result .= $msg;
            }
        } catch (\Throwable $e) {
            $result = 'FAILED - ' . $e->getMessage() . '!';
            $this::printToStdout('FAILED installing ' . $app_alias . '!');
            $this::printException($e);
            $exface->getLogger()->logException($e);
        }
        return $result;
    }

    public function uninstallApp($app_alias)
    {
        $result = '';
        try {
            $exface = $this->getWorkbench();
            $app_selector = new AppSelector($exface, $app_alias);
            $action = ActionFactory::createFromString($exface, self::PACKAGE_MANAGER_INSTALL_ACTION_ALIAS);
            $result = $action->uninstall($app_selector);
        } catch (\Throwable $e) {
            $result = 'FAILED - ' . $e->getMessage() . '!';
            $this::printToStdout('FAILED uninstalling ' . $app_alias . '!');
            $this::printException($e);
            $exface->getLogger()->logException($e);
        }
        return $result;
    }

    /**
     *
     * @return Workbench
     */
    public function getWorkbench()
    {
        $this->importSources();
        if (is_null($this->workbench)) {
            error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
            try {
                $this->workbench = Workbench::startNewInstance();
            } catch (\Throwable $e) {
                $this::printToStdout('FAILED to start workbench!');
                $this::printException($e);
                try {
                    $workbench = new Workbench();
                    $workbench->getLogger()->logException($e);
                    return $workbench;
                } catch (\Throwable $e2) {
                    $this::printToStdout('FAILED to start logger!');
                    $this::printException($e2);
                }
            }
        }
        return $this->workbench;
    }
    
    public static function generateLicenseBOM()
    {
        $installer = new self();
        try {
            $exface = $installer->getWorkbench();
            $action = ActionFactory::createFromString($exface, self::PACAKGE_MANAGER_GENERATE_LIC_BOM_ALIAS);
            
            self::printToStdout('Generating license BOM' . PHP_EOL . PHP_EOL);
            
            foreach ($action->generateMarkdownBOM() as $output) {
                self::printToStdout($output);
            }
        } catch (\Throwable $e) {
            $installer::printToStdout('FAILED generating license BOM!');
            $installer::printException($e);
            $exface->getLogger()->logException($e);
        }
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
        if (defined('STDOUT') === true && is_resource(STDOUT) === true) {
            fwrite(STDOUT, $text);
            return true;
        } else {
            echo $text;
        }
    }
    
    protected static function printException(\Throwable $e, $prefix = 'ERROR ') 
    {
        if ($e instanceof ExceptionInterface){
            $log_hint = 'See log ID ' . $e->getId();
        }
        self::printToStdout(PHP_EOL . PHP_EOL . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL . "-> " . $log_hint . PHP_EOL);
        
        if ($p = $e->getPrevious()) {
            self::printException($p);
        }
    }

    public static function getCoreAppAlias()
    {
        return 'exface.Core';
    }
    
    protected static function importSources()
    {
        require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'exface' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'CommonLogic' . DIRECTORY_SEPARATOR . 'Workbench.php';
    }
}