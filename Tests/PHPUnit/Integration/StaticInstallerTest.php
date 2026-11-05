<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use axenox\PackageManager\StaticInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Covers the decisions the StaticInstaller makes before any workbench is started: reading its configuration,
 * detecting conflicts between Composer's bundled packages and the vendor folder, and running child processes.
 * 
 * This is an integration test, because it starts real (tiny) PHP processes. No workbench or database is used.
 */
class StaticInstallerTest extends TestCase
{
    private string $folder;
    
    /**
     * Creates an empty fixture installation and resets the config cache of the installer.
     * 
     * @return void
     */
    protected function setUp() : void
    {
        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'StaticInstallerTest_' . bin2hex(random_bytes(6));
        mkdir($this->folder . '/config', 0777, true);
        mkdir($this->folder . '/vendor/composer', 0777, true);
        mkdir($this->folder . '/composer/vendor/composer', 0777, true);
        StaticInstallerTestDouble::$installationPath = $this->folder;
        StaticInstallerTestDouble::$configFiles = [$this->folder . '/default.json', $this->folder . '/config/installation.json'];
        StaticInstallerTestDouble::$composerInstalledPhp = $this->folder . '/composer/vendor/composer/installed.php';
        StaticInstallerTestDouble::$conflictCheckPackages = null;
        $this->resetConfigCache();
    }
    
    /**
     * Removes the fixture installation.
     * 
     * @return void
     */
    protected function tearDown() : void
    {
        $this->resetConfigCache();
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->folder, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->folder);
    }
    
    /**
     * Without any config files, the documented defaults apply.
     * 
     * @return void
     */
    public function testDefaultsApplyWithoutConfigFiles() : void
    {
        self::assertSame(StaticInstaller::CHILD_PROCESS_MIGRATION, StaticInstallerTestDouble::call('getChildProcessMode'));
        self::assertSame(0, StaticInstallerTestDouble::call('getChildProcessTimeout'));
        self::assertFalse(StaticInstallerTestDouble::call('isSharedWorkbench'));
    }
    
    /**
     * The installation config overrides the app defaults, keys and mode values are case-insensitive.
     * 
     * @return void
     */
    public function testInstallationConfigOverridesDefaults() : void
    {
        $this->writeConfig('default.json', [
            'COMPOSER.CHILD_PROCESS_MODE' => 'migration',
            'COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS' => 0,
            'COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP' => true
        ]);
        $this->writeConfig('config/installation.json', [
            'composer.child_process_mode' => ' Always ',
            'COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS' => '600',
            'COMPOSER.USE_NEW_WORKBENCH_FOR_EVERY_APP' => false
        ]);
        
        self::assertSame(StaticInstaller::CHILD_PROCESS_ALWAYS, StaticInstallerTestDouble::call('getChildProcessMode'));
        self::assertSame(600, StaticInstallerTestDouble::call('getChildProcessTimeout'));
        self::assertTrue(StaticInstallerTestDouble::call('isSharedWorkbench'));
    }
    
    /**
     * Invalid values must not break the installer: they fall back to the defaults.
     * 
     * @dataProvider invalidOptions
     * @param mixed $mode
     * @param mixed $timeout
     * @return void
     */
    public function testInvalidOptionsFallBackToDefaults($mode, $timeout) : void
    {
        $this->writeConfig('config/installation.json', [
            'COMPOSER.CHILD_PROCESS_MODE' => $mode,
            'COMPOSER.CHILD_PROCESS_TIMEOUT_SECONDS' => $timeout
        ]);
        
        self::assertSame(StaticInstaller::CHILD_PROCESS_MIGRATION, StaticInstallerTestDouble::call('getChildProcessMode'));
        self::assertSame(0, StaticInstallerTestDouble::call('getChildProcessTimeout'));
    }
    
    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function invalidOptions() : array
    {
        return [
            'unknown mode, negative timeout' => ['sometimes', -5],
            'former "off" mode' => ['off', 0],
            'boolean mode, text timeout' => [true, 'abc'],
            'null mode, fractional timeout' => [null, 1.5]
        ];
    }
    
    /**
     * An unreadable installation config is ignored instead of breaking the installer.
     * 
     * @return void
     */
    public function testBrokenConfigFileIsIgnored() : void
    {
        $this->writeConfig('default.json', ['COMPOSER.CHILD_PROCESS_MODE' => false]);
        file_put_contents($this->folder . '/config/installation.json', '{ broken');
        
        self::assertSame(StaticInstaller::CHILD_PROCESS_OFF, StaticInstallerTestDouble::call('getChildProcessMode'));
    }
    
    /**
     * Calls without a Composer event (manual calls, actions, the child process itself) never start a child process.
     * 
     * @return void
     */
    public function testCallsWithoutComposerEventRunInProcess() : void
    {
        $this->writeConfig('config/installation.json', ['COMPOSER.CHILD_PROCESS_MODE' => 'always']);
        
        self::assertFalse(StaticInstallerTestDouble::call('shouldRunInChildProcess', null));
    }
    
    /**
     * Major versions follow semantic versioning, branches have none.
     * 
     * @dataProvider versions
     * @param string $version
     * @param string|null $expected
     * @return void
     */
    public function testMajorVersionParsing(string $version, ?string $expected) : void
    {
        self::assertSame($expected, StaticInstallerTestDouble::call('getMajorVersion', $version));
    }
    
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function versions() : array
    {
        return [
            'prefixed' => ['v5.4.44', '5'],
            'plain' => ['1.1.4', '1'],
            'pre-release' => ['3.0.0-RC1', '3'],
            'branch alias' => ['2.x-dev', '2'],
            'zero major' => ['0.4.2', '0.4'],
            'branch' => ['dev-master', null]
        ];
    }
    
    /**
     * Only checked packages with different major versions (or different unparsable versions) conflict.
     * 
     * @return void
     */
    public function testConflictsAreDifferentMajorVersionsOfSharedPackages() : void
    {
        StaticInstallerTestDouble::$conflictCheckPackages = ['psr/log', 'symfony/console', 'acme/branch', 'acme/zero', 'psr/log-implementation'];
        $this->writeInstalledPhp(StaticInstallerTestDouble::$composerInstalledPhp, [
            'psr/log' => ['pretty_version' => '1.1.4'],
            'psr/log-implementation' => ['provided' => ['1.0|2.0']],
            'symfony/console' => ['pretty_version' => 'v5.4.44'],
            'react/promise' => ['pretty_version' => 'v3.2.0'],
            'acme/branch' => ['pretty_version' => 'dev-main'],
            'acme/zero' => ['pretty_version' => '0.3.0']
        ]);
        $this->writeInstalledPhp($this->folder . '/vendor/composer/installed.php', [
            'psr/log' => ['pretty_version' => '3.0.2'],
            'psr/log-implementation' => ['provided' => ['3.0.0']],
            'symfony/console' => ['pretty_version' => 'v5.4.49'],
            'acme/branch' => ['pretty_version' => 'dev-feature'],
            'acme/zero' => ['pretty_version' => '0.4.1'],
            'monolog/monolog' => ['pretty_version' => '3.9.0']
        ]);
        
        $conflicts = StaticInstallerTestDouble::call('findComposerDependencyConflicts');
        
        self::assertSame(['psr/log', 'acme/branch', 'acme/zero'], array_keys($conflicts));
        self::assertSame('psr/log 1.1.4 in Composer vs. 3.0.2 in vendor', $conflicts['psr/log']);
    }
    
    /**
     * By default only psr/log is checked: other version differences (e.g. symfony/console) never broke the 
     * installers, so they must not start a child process.
     * 
     * @return void
     */
    public function testOnlyKnownBreakingPackagesAreCheckedByDefault() : void
    {
        StaticInstallerTestDouble::$conflictCheckPackages = null;
        $this->writeInstalledPhp(StaticInstallerTestDouble::$composerInstalledPhp, [
            'psr/log' => ['pretty_version' => '1.1.4'],
            'symfony/console' => ['pretty_version' => 'v5.4.44'],
            'composer/pcre' => ['pretty_version' => '2.3.1']
        ]);
        $projectPath = $this->folder . '/vendor/composer/installed.php';
        $this->writeInstalledPhp($projectPath, [
            'psr/log' => ['pretty_version' => '1.1.4'],
            'symfony/console' => ['pretty_version' => 'v6.4.50'],
            'composer/pcre' => ['pretty_version' => '3.4.0']
        ]);
        
        self::assertSame([], StaticInstallerTestDouble::call('findComposerDependencyConflicts'));
        
        $this->writeInstalledPhp($projectPath, [
            'psr/log' => ['pretty_version' => '3.0.2'],
            'symfony/console' => ['pretty_version' => 'v6.4.50']
        ]);
        
        self::assertSame(['psr/log'], array_keys(StaticInstallerTestDouble::call('findComposerDependencyConflicts')));
    }
    
    /**
     * If the package lists cannot be compared, a conflict is assumed, so `migration` mode stays on the safe side.
     * 
     * @return void
     */
    public function testUnreadablePackageListsCountAsConflict() : void
    {
        $this->writeInstalledPhp($this->folder . '/vendor/composer/installed.php', [
            'psr/log' => ['pretty_version' => '3.0.2']
        ]);
        
        self::assertArrayHasKey('*', StaticInstallerTestDouble::call('findComposerDependencyConflicts'));
    }
    
    /**
     * The exit code of the child process is passed on and it gets no console input, so it cannot wait for any.
     * 
     * @return void
     */
    public function testChildProcessReturnsExitCodeAndHasNoInput() : void
    {
        $code = 'exit(stream_get_contents(STDIN) === "" ? 3 : 1);';
        
        self::assertSame(3, StaticInstallerTestDouble::call('runProcess', [PHP_BINARY, '-r', $code], 'exit code test', 0));
        self::assertSame(3, StaticInstallerTestDouble::call('runProcess', [PHP_BINARY, '-r', $code], 'exit code test', 30));
    }
    
    /**
     * A child process exceeding the timeout is stopped and reported as error.
     * 
     * @return void
     */
    public function testChildProcessIsStoppedAfterTimeout() : void
    {
        $start = microtime(true);
        try {
            StaticInstallerTestDouble::call('runProcess', [PHP_BINARY, '-r', 'sleep(30);'], 'timeout test', 1);
            self::fail('Expected a timeout');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('timeout of 1 seconds', $e->getMessage());
        }
        self::assertLessThan(10, microtime(true) - $start);
    }
    
    /**
     * @param string $relativePath
     * @param array<string, mixed> $options
     * @return void
     */
    private function writeConfig(string $relativePath, array $options) : void
    {
        file_put_contents($this->folder . '/' . $relativePath, json_encode($options));
    }
    
    /**
     * @param string $path
     * @param array<string, array<string, mixed>> $versions
     * @return void
     */
    private function writeInstalledPhp(string $path, array $versions) : void
    {
        file_put_contents($path, '<?php return ' . var_export(['root' => [], 'versions' => $versions], true) . ';');
    }
    
    /**
     * @return void
     */
    private function resetConfigCache() : void
    {
        $property = new \ReflectionProperty(StaticInstaller::class, 'configCache');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}

/**
 * Points the installer to fixture files and exposes its protected static methods.
 */
class StaticInstallerTestDouble extends StaticInstaller
{
    /** @var string[] */
    public static array $configFiles = [];
    
    public static string $installationPath = '';
    
    public static ?string $composerInstalledPhp = null;
    
    /** @var string[]|null NULL to use the packages of the real installer */
    public static ?array $conflictCheckPackages = null;
    
    /**
     * Calls a protected static method of the installer.
     * 
     * @param string $method
     * @param mixed ...$arguments
     * @return mixed
     */
    public static function call(string $method, ...$arguments)
    {
        return static::$method(...$arguments);
    }
    
    protected static function getConfigFilePaths() : array
    {
        return static::$configFiles;
    }
    
    protected static function getInstallationPath() : string
    {
        return static::$installationPath;
    }
    
    protected static function getComposerInstalledPhpPath() : ?string
    {
        return static::$composerInstalledPhp;
    }
    
    protected static function getConflictCheckPackages() : array
    {
        return static::$conflictCheckPackages ?? parent::getConflictCheckPackages();
    }
}
