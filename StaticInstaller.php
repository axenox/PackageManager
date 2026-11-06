<?php
namespace axenox\PackageManager;

use Composer\Installer\PackageEvent;
use exface\Core\CommonLogic\Workbench;
use Composer\Script\Event;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Facades\ConsoleFacade\CliOutputPrinter;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Factories\ActionFactory;
use axenox\PackageManager\Actions\ListApps;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * The static installer allows Composer (or anything else outside of the Workbench) to launch app installers.
 * 
 * The following methods are run on composer events. Thy are registered as composer scripts.
 *
 * - `StaticInstaller::composerFinishInstall()`
 * - `StaticInstaller::composerFinishUpdate()`
 * - `StaticInstaller::composerFinishPackageInstall()`
 * - `StaticInstaller::composerFinishPackageUpdate()`
 * - `StaticInstaller::composerPrepareUninstall()`
 * 
 * ## Why some entry points run in a separate PHP process
 * 
 * Composer scripts run inside the Composer process. Composer ships its own copies of several libraries and loads
 * them before calling any script. A PHP process can only hold one definition of a class or interface, so whenever
 * the workbench needs a different version of one of these libraries, it fails to load inside Composer. For example,
 * Composer bundles the psr/log 1 interfaces, while the workbench's logger (Monolog 3) implements psr/log 3. Other
 * bundled libraries (e.g. symfony/console, symfony/process, react/promise) could clash the same way in the future.
 * Starting the workbench in its own process isolates it from Composer's dependencies altogether.
 * 
 * The entry points that start a workbench (`composerFinishInstall()`, `composerFinishUpdate()` and
 * `composerBackupEverything()`) can therefore restart themselves in a new PHP process, if they are running inside
 * Composer. The `$composer_event` tells them: Composer always passes an event to its scripts, while all other
 * callers pass none - the new process itself, actions and manual calls like
 * `php -r "require 'vendor/autoload.php'; axenox\PackageManager\StaticInstaller::composerFinishInstall();"`.
 * Without an event, they always run the installers right away. See `shouldRunInChildProcess()`.
 * 
 * Whether Composer calls are moved to a child process is controlled by `COMPOSER.CHILD_PROCESS_MODE`:
 * 
 * - `migration` (default) - only if Composer bundles a package known to break the workbench in another major
 * version than the one in the `vendor` folder (currently psr/log: 1 in Composer vs. 3 for Monolog 3 in the 
 * workbench). In this case, the workbench would fail to load inside Composer. See 
 * `findComposerDependencyConflicts()`.
 * - `always` - every Composer call starts a child process.
 * - `false` - installers always run inside the Composer process (the behavior before child processes were
 * introduced). A warning is printed if a conflict is detected.
 * 
 * The child process does not receive any console input (STDIN), because installers never ask for any. Its output is
 * streamed to the console live. `COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS` allows to stop a child process that
 * runs longer than expected (default `0` - no timeout). Both are precautions: freezes of the child process were not
 * observed so far.
 * 
 * These options are read directly from the JSON config files (see `getConfigOption()`), because the workbench cannot
 * be started inside Composer - that is the reason for the child process in the first place.
 * 
 * Note: there is only one child process per Composer script call - it runs the installers of all apps one after
 * another just like the Composer process would. The child process only isolates the installers from Composer, but
 * not the apps from each other. How the apps share workbenches inside that process is controlled separately by
 * `COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP` (see below) - both options are independent of each other.
 * 
 * TODO Run the installer of every app in its own child process instead of chaining all installers in one process.
 * Every app would get a clean PHP process (no static state, loaded classes, memory or DB connections left over 
 * from previous apps), which would make `COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP` and `releaseWorkbench()`
 * obsolete. The parent process would only determine the apps to install (core first), start a child per app,
 * collect the exit codes and maintain the temp file - without starting a workbench itself. Trade-off: PHP and the 
 * workbench are started once per app, which makes the installation slower.
 * 
 * ## Single or multiple workbenches
 * 
 * By default, this installer will initialize a new Workbench before running installers for each app. However, this
 * might cause rare side effects, so you can opt for a single shared workbench instance for all apps by setting
 * `COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP` to FALSE in `config/axenox.PackageManager.config.json` in your
 * installation folder. 
 * 
 * This applies to whatever process runs the installers - the Composer process or the child process (see above). In 
 * both cases, multiple workbenches still share a single PHP process.
 * 
 * The mode is determined before the first workbench is started. All code here accesses the workbench via
 * `getWorkbench()` and calls `releaseWorkbench()` when it is done. When a new workbench is used for every app 
 * (the default), this stops the workbench explicitly. This deterministically closes its DB connections and releases
 * any locks/open transactions before the next app starts - instead of relying on PHP's garbage collector, which may
 * defer the cleanup. A shared workbench is kept running for all apps.
 * 
 * For example, when using a remote MS SQL database for the metamodel, the installer of the second app being 
 * installed might just hang because the MS SQL connector cannot establish a new connection to the DB. If this happens,
 * use a shared workbench here to make every app use the same DB connection.
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

    const PACAKGE_MANAGER_GENERATE_LIC_BOM_ALIAS = 'axenox.PackageManager.GenerateLicenseBOM';
    
    const CHILD_PROCESS_MIGRATION = 'migration';
    
    const CHILD_PROCESS_ALWAYS = 'always';
    
    const CHILD_PROCESS_OFF = false;
    
    const CONFIG_FILE_NAME = 'axenox.PackageManager.config.json';

    private $workbench = null;
    private static $globalWorkbench = null;
    
    /** @var array<string, mixed>|null config options with upper-case keys, read by `getConfigOption()` */
    private static $configCache = null;

    /**
     *
     * @param PackageEvent $composer_event
     * @return void
     */
    public static function composerFinishPackageInstall(PackageEvent $composer_event)
    {
        static::init();
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
        static::init();
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
        if (static::shouldRunInChildProcess($composer_event)) {
            return static::runInChildProcess(__FUNCTION__);
        }
        static::init();
        try {
            $result = '';
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
        if (static::shouldRunInChildProcess($composer_event)) {
            return static::runInChildProcess(__FUNCTION__);
        }
        static::init();
        
        self::printToStdout("Running installers for newly installed and updated apps...\n");

        $processed_aliases = array();
        $temp = self::getTempFile();

        $appAliases = array_key_exists('update', $temp) ? $temp['update'] : [];
        if (array_key_exists('install', $temp) && is_array($temp['install']) === true) {
            // If the package manager is being installed for the first time, run
            // install instead of update
            if (in_array('axenox.PackageManager', $temp['install'])) {
                // The child process decision was already made above, so run the install right here
                return self::composerFinishInstall();
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
            try {
                $apps = ListApps::findAppAliasesInModel($installer->getWorkbench());
                $backupTime = $temp['backupTime'];
                $unlinkResult = array();
    
                foreach($apps as $app){
    
                    if (! in_array($app, $updatedPackages)){
                        $unlinkResult[] = $installer->unlinkBackup($app,$backupTime);
                    }
                }
                $installer->copyTempFile($backupTime);
            } finally {
                $installer->releaseWorkbench();
            }
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
     * Prepare the environment to run installers
     * 
     * @return void
     */
    protected static function init()
    {
        // Make sure no warnings/notices are thrown because they will break 
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED & ~E_USER_DEPRECATED);
        // Make sure errors are displayed even if local php.ini hides them (not sure, if this will really
        // help because it will not affect fatal errors according to PHP documentation)
        ini_set('display_errors', 1);
    }
    
    /**
     * Returns an option of the PackageManager configuration without starting a workbench.
     * 
     * Reads the default config of this app and the one in the `config` folder of the installation - in this order,
     * so the installation can override the defaults. Keys are compared case-insensitively like in the workbench
     * configuration. The files are read once per process.
     * 
     * This is needed because the options are used inside Composer, where the workbench cannot be started.
     * 
     * @param string $key
     * @param mixed $default returned if no config file contains the option
     * @return mixed
     */
    protected static function getConfigOption(string $key, $default = null)
    {
        if (static::$configCache === null) {
            $options = [];
            foreach (static::getConfigFilePaths() as $path) {
                if (! is_file($path)) {
                    continue;
                }
                $json = file_get_contents($path);
                $fileOptions = ($json === false || trim($json) === '') ? [] : json_decode($json, true);
                if (! is_array($fileOptions)) {
                    self::printToStdout('WARNING: Cannot read configuration file "' . $path . '" - ignoring it!' . PHP_EOL);
                    continue;
                }
                $options = array_merge($options, array_change_key_case($fileOptions, CASE_UPPER));
            }
            static::$configCache = $options;
        }
        $key = strtoupper($key);
        return array_key_exists($key, static::$configCache) ? static::$configCache[$key] : $default;
    }
    
    /**
     * Config files read by `getConfigOption()` - later files override earlier ones.
     * 
     * @return string[]
     */
    protected static function getConfigFilePaths() : array
    {
        return [
            __DIR__ . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . self::CONFIG_FILE_NAME,
            static::getInstallationPath() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . self::CONFIG_FILE_NAME
        ];
    }
    
    /**
     * Returns the root folder of the installation (the one containing `vendor` and `config`).
     * 
     * @return string
     */
    protected static function getInstallationPath() : string
    {
        return dirname(__DIR__, 3);
    }
    
    /**
     * Returns the configured `COMPOSER.CHILD_PROCESS_MODE` - `migration` if it is missing or invalid.
     * 
     * @return string|false `false` if child processes are disabled
     */
    protected static function getChildProcessMode() : string|false
    {
        $value = static::getConfigOption('COMPOSER.CHILD_PROCESS_MODE', self::CHILD_PROCESS_MIGRATION);
        $mode = is_string($value) ? strtolower(trim($value)) : $value;
        $modes = [self::CHILD_PROCESS_MIGRATION, self::CHILD_PROCESS_ALWAYS, self::CHILD_PROCESS_OFF];
        if (! in_array($mode, $modes, true)) {
            self::printToStdout('WARNING: Invalid COMPOSER.CHILD_PROCESS_MODE ' . json_encode($value) . ' - expecting one of ' . implode(', ', array_map('json_encode', $modes)) . '. Using "' . self::CHILD_PROCESS_MIGRATION . '".' . PHP_EOL);
            return self::CHILD_PROCESS_MIGRATION;
        }
        return $mode;
    }
    
    /**
     * Returns the configured `COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS` - `0` (no timeout) if missing or invalid.
     * 
     * @return int
     */
    protected static function getChildProcessTimeout() : int
    {
        $value = static::getConfigOption('COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS', 0);
        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
            self::printToStdout('WARNING: Invalid COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS ' . json_encode($value) . ' - expecting 0 (no timeout) or a positive number of seconds. Using no timeout.' . PHP_EOL);
            return 0;
        }
        return (int) $value;
    }
    
    /**
     * Decides, if an entry point should restart itself in a child process - see class description.
     * 
     * Calls without a Composer event always run in the current process. Composer calls depend on
     * `COMPOSER.CHILD_PROCESS_MODE`.
     * 
     * @param Event|null $composer_event
     * @return bool
     */
    protected static function shouldRunInChildProcess(Event $composer_event = null) : bool
    {
        if ($composer_event === null) {
            return false;
        }
        $mode = static::getChildProcessMode();
        if ($mode === self::CHILD_PROCESS_ALWAYS) {
            return true;
        }
        
        $conflicts = static::findComposerDependencyConflicts();
        if ($mode === self::CHILD_PROCESS_OFF) {
            if (! empty($conflicts)) {
                self::printToStdout('WARNING: Running installers inside Composer although its bundled packages conflict with this installation: ' . implode('; ', $conflicts) . '. The workbench may fail to load - consider setting COMPOSER.CHILD_PROCESS_MODE to "' . self::CHILD_PROCESS_MIGRATION . '".' . PHP_EOL);
            }
            return false;
        }
        
        if (empty($conflicts)) {
            return false;
        }
        self::printToStdout('Composer\'s bundled packages conflict with this installation: ' . implode('; ', $conflicts) . '.' . PHP_EOL);
        return true;
    }
    
    /**
     * Lists packages known to break the workbench, that are installed in another major version in Composer.
     * 
     * Only one version of a class can be loaded per PHP process. If Composer already loaded a class, the workbench
     * gets Composer's version. This only breaks things if a workbench class extends or implements it with an
     * incompatible signature - e.g. Monolog 3 implementing the untyped `LoggerInterface` of Composer's psr/log 1.
     * Other version differences (e.g. symfony/console) never caused problems, so only the packages listed in
     * `getConflictCheckPackages()` are checked. 
     * 
     * A different major version is treated as a conflict (for `0.x` versions the minor version counts as major, as
     * in semantic versioning). If a version cannot be compared (e.g. `dev-master`), the package conflicts if the 
     * versions are not equal.
     * 
     * If the package lists cannot be read, a conflict is assumed to be on the safe side.
     * 
     * @return string[] descriptions of the conflicts with package names as keys - empty if there are none
     */
    protected static function findComposerDependencyConflicts() : array
    {
        $composerPackages = static::readInstalledPackages(static::getComposerInstalledPhpPath());
        $projectPackages = static::readInstalledPackages(static::getInstallationPath() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.php');
        if ($composerPackages === null || $projectPackages === null) {
            return ['*' => 'cannot compare Composer\'s bundled packages with the vendor folder'];
        }
        
        $conflicts = [];
        $checkedPackages = array_flip(static::getConflictCheckPackages());
        foreach (array_intersect_key($composerPackages, $projectPackages, $checkedPackages) as $name => $composerVersion) {
            $projectVersion = $projectPackages[$name];
            $composerMajor = static::getMajorVersion($composerVersion);
            $projectMajor = static::getMajorVersion($projectVersion);
            $isConflict = ($composerMajor === null || $projectMajor === null)
                ? $composerVersion !== $projectVersion
                : $composerMajor !== $projectMajor;
            if ($isConflict) {
                $conflicts[$name] = $name . ' ' . $composerVersion . ' in Composer vs. ' . $projectVersion . ' in vendor';
            }
        }
        return $conflicts;
    }
    
    /**
     * Packages bundled with Composer, that break the workbench if loaded in another major version.
     * 
     * Add a package here if a new version of it makes the installers crash inside Composer (e.g. with "Declaration
     * of ... must be compatible with ...").
     * 
     * @return string[] lower-case package names
     */
    protected static function getConflictCheckPackages() : array
    {
        return [
            // Monolog 3 (used since the Monolog upgrade) needs psr/log 3, Composer bundles psr/log 1
            'psr/log'
        ];
    }
    
    /**
     * Returns the path to `installed.php` of the running Composer or NULL if not running inside Composer.
     * 
     * Works for the PHAR and for source installations of Composer: both have `src/Composer/Composer.php` and
     * `vendor/composer/installed.php` in the same root folder.
     * 
     * @return string|null
     */
    protected static function getComposerInstalledPhpPath() : ?string
    {
        if (! class_exists(\Composer\Composer::class, false)) {
            return null;
        }
        $file = (new \ReflectionClass(\Composer\Composer::class))->getFileName();
        if ($file === false) {
            return null;
        }
        // <root>/src/Composer/Composer.php -> <root>/vendor/composer/installed.php
        return dirname($file, 3) . '/vendor/composer/installed.php';
    }
    
    /**
     * Reads the versions of all installed packages from a Composer `installed.php` file.
     * 
     * Packages without a version (e.g. virtual packages like `psr/log-implementation`) are skipped.
     * 
     * @param string|null $path
     * @return array<string, string>|null package names as keys and pretty versions as values or NULL if not readable
     */
    protected static function readInstalledPackages(?string $path) : ?array
    {
        if ($path === null || ! file_exists($path)) {
            return null;
        }
        try {
            $data = include $path;
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($data) || ! is_array($data['versions'] ?? null)) {
            return null;
        }
        $packages = [];
        foreach ($data['versions'] as $name => $package) {
            $version = $package['pretty_version'] ?? $package['version'] ?? null;
            if (is_string($version) && $version !== '') {
                $packages[strtolower($name)] = $version;
            }
        }
        return $packages;
    }
    
    /**
     * Returns the major version of a Composer version string or NULL for branches like `dev-master`.
     * 
     * Examples: `v5.4.44` -> `5`, `3.0.0-RC1` -> `3`, `2.x-dev` -> `2`, `0.4.2` -> `0.4`.
     * 
     * @param string $version
     * @return string|null
     */
    protected static function getMajorVersion(string $version) : ?string
    {
        if (! preg_match('/^v?(\d+)(?:\.(\d+))?/i', trim($version), $matches)) {
            return null;
        }
        $major = (string) (int) $matches[1];
        if ($major === '0') {
            return '0.' . (isset($matches[2]) ? (int) $matches[2] : 0);
        }
        return $major;
    }
    
    /**
     * Runs the given entry point in a new PHP process and streams its output to the console.
     * 
     * Used when Composer called the entry point (see class description): the new process does not contain
     * Composer's bundled libraries, so the workbench loads only its own `vendor` versions (e.g. psr/log 3 for
     * Monolog 3 instead of Composer's psr/log 1). The entry point is called there without an
     * event, so it runs the installers instead of starting yet another process.
     * 
     * The child is started like Composer starts `@php` scripts: same PHP binary and `memory_limit` as the
     * Composer process. The command is passed as an array, so no shell is involved and nothing is
     * interpolated into a command line.
     * 
     * @param string $method public static method of this class, that runs in-process if called without event
     * @throws \RuntimeException if the child process cannot be started, times out or does not exit successfully
     * @return string
     */
    protected static function runInChildProcess(string $method) : string
    {
        if (PHP_BINARY === '') {
            throw new \RuntimeException('Cannot run "' . $method . '" in a separate PHP process: PHP binary unknown!');
        }
        $autoloadPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'autoload.php';
        $code = 'require ' . var_export($autoloadPath, true) . '; \\' . static::class . '::' . $method . '();';
        $command = [PHP_BINARY, '-d', 'memory_limit=' . ini_get('memory_limit'), '-r', $code];
        
        self::printToStdout('Running ' . $method . ' in a separate PHP process...' . PHP_EOL);
        $exitCode = static::runProcess($command, $method, static::getChildProcessTimeout());
        if ($exitCode !== 0) {
            // Composer stops with an error, just like it did when the installers crashed inside its own process
            throw new \RuntimeException('"' . $method . '" failed in a separate PHP process with exit code ' . $exitCode . ' - see output above.');
        }
        return '';
    }
    
    /**
     * Runs a command, streams its output to the console live and returns its exit code.
     * 
     * The process gets no console input (STDIN is the null device), so it can never block waiting for it. Without
     * a timeout, this waits for the process to finish. With a timeout, the process status is checked a few times
     * per second and the process (incl. its child processes on Windows) is killed once the timeout is exceeded.
     * 
     * @param string[] $command executable and arguments - passed without a shell
     * @param string $label used in error messages
     * @param int $timeoutSeconds `0` for no timeout
     * @throws \RuntimeException if the process cannot be started or times out
     * @return int
     */
    protected static function runProcess(array $command, string $label, int $timeoutSeconds = 0) : int
    {
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        // Hand the console output streams to the child, so its output appears live and is not buffered here
        $descriptors = [
            0 => ['file', $nullDevice, 'r'],
            1 => defined('STDOUT') ? STDOUT : ['file', $nullDevice, 'w'],
            2 => defined('STDERR') ? STDERR : ['file', $nullDevice, 'w']
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('Cannot start a separate PHP process for "' . $label . '"!');
        }
        if ($timeoutSeconds <= 0) {
            return proc_close($process);
        }
        
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $status = proc_get_status($process);
            if ($status['running'] === false) {
                // The exit code is only reported by the first status call after the process ended
                proc_close($process);
                return $status['exitcode'];
            }
            if (microtime(true) >= $deadline) {
                static::killProcess($process, $status['pid']);
                proc_close($process);
                throw new \RuntimeException('"' . $label . '" was stopped after exceeding the timeout of ' . $timeoutSeconds . ' seconds (COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS)!');
            }
            usleep(200000);
        }
    }
    
    /**
     * Kills a process started via `proc_open()` - on Windows including its child processes.
     * 
     * @param resource $process
     * @param int $pid
     * @return void
     */
    protected static function killProcess($process, int $pid) : void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // proc_terminate() would only stop the process itself, but not processes it started (e.g. git)
            exec('taskkill /F /T /PID ' . $pid . ' 2>NUL', $output, $exitCode);
            if ($exitCode === 0) {
                return;
            }
        }
        proc_terminate($process, 9);
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
        if (static::shouldRunInChildProcess($composer_event)) {
            return static::runInChildProcess(__FUNCTION__);
        }
        static::init();
        $installer = new self();
        try {
            $apps = ListApps::findAppAliasesInModel($installer->getWorkbench());
            //write consistent backuptime to delete excess data after update run
            $backupTime = date('Y_m_d_H_i');
            $temp = self::getTempFile();
            $temp['backupTime'] = $backupTime;
            self::setTempFile($temp);
            $backupPath = "autobackup".DIRECTORY_SEPARATOR.$backupTime;
            self::printToStdout("Starting automatic backup to ".$backupPath);
            foreach($apps as $app){
                $installer->backup($app, $backupPath);
            }
        } finally {
            $installer->releaseWorkbench();
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
        static::init();
        if (is_array($extras_array) && array_key_exists('app', $extras_array) && is_array($extras_array['app']) && array_key_exists('app_alias', $extras_array['app'])) {
            return $extras_array['app']['app_alias'];
        }
        return false;
    }

    public static function install($app_alias)
    {
        $installer = new self();
        try {
            return $installer->installApp($app_alias);
        } finally {
            $installer->releaseWorkbench();
        }
    }

    public static function uninstall($app_alias)
    {
        static::init();
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
            if ($exface !== null) {
                $exface->getLogger()->logException($e);
            }
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
            if ($exface !== null) {
                $exface->getLogger()->logException($e);
            }
        }
        return $result;
    }

    /**
     * Returns the workbench to run installers with - starting it if required.
     * 
     * Depending on `COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP` (see class description), this is either the single
     * workbench shared by all installers or a new workbench for this installer instance. Call `releaseWorkbench()`
     * once done with it.
     * 
     * If the workbench cannot be started, a workbench, that is not started, is returned to allow logging the error.
     *
     * @return Workbench
     */
    public function getWorkbench() : WorkbenchInterface
    {
        if (static::$globalWorkbench !== null) {
            return static::$globalWorkbench;
        }
        $this->importSources();
        if (is_null($this->workbench)) {
            static::init();
            $shared = static::isSharedWorkbench();
            try {
                $this::printToStdout('Starting new workbench instance');
                $this->workbench = Workbench::startNewInstance();
                if ($shared) {
                    $this::printToStdout(' - using this workbench globally' . PHP_EOL);
                    static::$globalWorkbench = $this->workbench;
                } else {
                    $this::printToStdout(' - it will be used for this app only' . PHP_EOL);
                }
            } catch (\Throwable $e) {
                $this::printToStdout('FAILED to start workbench!' . PHP_EOL);
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
    
    /**
     * Releases the workbench of this installer instance once it is not needed anymore.
     * 
     * If every app gets its own workbench, it is stopped explicitly. This deterministically releases its DB 
     * connections and any locks/open transactions right away instead of relying on PHP's garbage collector, which
     * may defer cleanup due to circular references. Without this, the lingering connection can prevent the next 
     * app from opening a fresh connection (e.g. remote MS SQL / Azure SQL), causing the installer to hang.
     * 
     * Does nothing if a shared workbench is used, because it must stay alive for all subsequent apps.
     * 
     * @return void
     */
    protected function releaseWorkbench() : void
    {
        if (static::$globalWorkbench !== null || $this->workbench === null) {
            return;
        }
        try {
            $this->workbench->stop();
        } catch (\Throwable $e) {
            static::printException($e);
        }
        $this->workbench = null;
    }
    
    /**
     * Returns TRUE if all installers should share a single workbench (`COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP`
     * set to `false`) and FALSE if every app gets its own workbench (default).
     * 
     * @return bool
     */
    protected static function isSharedWorkbench() : bool
    {
        return static::getConfigOption('COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP', true) === false;
    }

    public static function generateLicenseBOM()
    {
        $installer = new self();
        $exface = null;
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
            if ($exface !== null) {
                $exface->getLogger()->logException($e);
            } else {
                $installer::printToStdout('Cannot log error: workbench not available!');
            }
        } finally {
            $installer->releaseWorkbench();
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
        if (class_exists(\exface\Core\Facades\ConsoleFacade\CliOutputPrinter::class)) {
            self::printToStdout(
                CliOutputPrinter::printBordered(
                    CliOutputPrinter::printExceptionWithBottomTraceOnly($e, '', $prefix . PHP_EOL)
                ),
                '/'
            );
        }
        if ($e instanceof ExceptionInterface){
            $log_hint = 'See log ID ' . $e->getId();
        }
        self::printToStdout(PHP_EOL . PHP_EOL . $prefix . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL . "-> " . $log_hint . PHP_EOL);
        self::printToStdout(PHP_EOL . '------------------------------------------------------------------------');
        self::printToStdout(PHP_EOL . StringDataType::indent($e->getTraceAsString(), '  '));
        while ($e = $e->getPrevious()) {
            self::printToStdout(PHP_EOL . PHP_EOL . $prefix . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL . "-> " . $log_hint . PHP_EOL);
            self::printToStdout(PHP_EOL . StringDataType::indent($e->getTraceAsString(), '  '));
        }
        self::printToStdout(PHP_EOL . '------------------------------------------------------------------------');
    }

    public static function getCoreAppAlias()
    {
        return 'exface.Core';
    }

    protected static function importSources()
    {
        require_once dirname(__FILE__) . DIRECTORY_SEPARATOR 
            . '..' . DIRECTORY_SEPARATOR 
            . '..' . DIRECTORY_SEPARATOR 
            . 'exface' . DIRECTORY_SEPARATOR 
            . 'core' . DIRECTORY_SEPARATOR 
            . 'CommonLogic' . DIRECTORY_SEPARATOR 
            . 'Workbench.php';
    }
}