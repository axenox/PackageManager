<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\StaticInstaller;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Checks installer failure propagation and persistent retry queues without starting a Workbench.
 */
class StaticInstallerRetryTest extends TestCase
{
    private string $tempFile;

    /**
     * Creates an isolated queue before each scenario.
     *
     * {@inheritDoc}
     * @see \PHPUnit\Framework\TestCase::setUp()
     */
    protected function setUp() : void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'pm-retry-');
        RetryStaticInstaller::$tempFile = $this->tempFile;
        RetryStaticInstaller::$failures = [];
        RetryStaticInstaller::$attempts = [];
        RetryStaticInstaller::$queuesBeforeInstall = [];
        RetryStaticInstaller::$generatedBOM = false;
        RetryStaticInstaller::writeQueue([]);
    }

    /**
     * Removes only this scenario's temporary queue.
     *
     * {@inheritDoc}
     * @see \PHPUnit\Framework\TestCase::tearDown()
     */
    protected function tearDown() : void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * A mixed result must fail the script and retry only failed apps, preserving their operation.
     *
     * @return void
     */
    public function testMixedFailuresAreRetainedAndRetried() : void
    {
        RetryStaticInstaller::writeQueue([
            'update' => ['test.Success', 'exface.Core', 'test.Failed', 'test.Success'],
            'install' => ['test.NewFailed', 'test.Success'],
            'metadata' => 'keep'
        ]);
        RetryStaticInstaller::$failures = [
            'test.Failed' => new \RuntimeException('Installer failed'),
            'test.NewFailed' => new \Error('Missing class')
        ];

        self::assertSame(1, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertSame(['exface.Core', 'test.Success', 'test.Failed', 'test.NewFailed'], RetryStaticInstaller::$attempts);
        self::assertSame([
            'update' => ['test.Failed'],
            'install' => ['test.NewFailed'],
            'metadata' => 'keep'
        ], RetryStaticInstaller::readQueue());
        self::assertSame(
            RetryStaticInstaller::readQueue(),
            RetryStaticInstaller::$queuesBeforeInstall['test.Failed']
        );
        self::assertFalse(RetryStaticInstaller::$generatedBOM);

        RetryStaticInstaller::$attempts = [];
        RetryStaticInstaller::$failures = [];
        self::assertSame(0, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertSame(['test.Failed', 'test.NewFailed'], RetryStaticInstaller::$attempts);
        self::assertSame(['metadata' => 'keep'], RetryStaticInstaller::readQueue());
        self::assertTrue(RetryStaticInstaller::$generatedBOM);
    }

    /**
     * Startup failure for every app must leave all pending entries available.
     *
     * @return void
     */
    public function testAllFailuresKeepTheQueue() : void
    {
        $queue = ['update' => ['exface.Core'], 'install' => ['test.New']];
        RetryStaticInstaller::writeQueue($queue);
        RetryStaticInstaller::$failures = [
            'exface.Core' => new \Error('Authenticator not found'),
            'test.New' => new \RuntimeException('Workbench not started')
        ];

        self::assertSame(1, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertFileExists($this->tempFile);
        self::assertSame($queue, RetryStaticInstaller::readQueue());
    }

    /**
     * A successful run clears the queue, and another run does not reinstall anything.
     *
     * @return void
     */
    public function testSuccessDeletesAnEmptyQueue() : void
    {
        RetryStaticInstaller::writeQueue(['update' => ['test.Success']]);

        self::assertSame(0, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertFileDoesNotExist($this->tempFile);
        RetryStaticInstaller::$attempts = [];
        self::assertSame(0, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertSame([], RetryStaticInstaller::$attempts);
    }

    /**
     * Full installation must queue discovered apps and avoid installing Core twice.
     *
     * @return void
     */
    public function testFullInstallRetainsFailuresForAnUpdateRetry() : void
    {
        RetryStaticInstaller::$failures = ['exface.Core' => new \Error('Startup failed')];

        self::assertSame(1, RetryStaticInstaller::runComposerScript('composerFinishInstall'));
        self::assertSame('exface.Core', RetryStaticInstaller::$attempts[0]);
        self::assertSame(1, count(array_keys(RetryStaticInstaller::$attempts, 'exface.Core', true)));
        self::assertSame(['install' => ['exface.Core'], 'fullInstallPending' => true], RetryStaticInstaller::readQueue());

        RetryStaticInstaller::$attempts = [];
        RetryStaticInstaller::$failures = [];
        self::assertSame(0, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertSame(['exface.Core'], RetryStaticInstaller::$attempts);
        self::assertFileDoesNotExist($this->tempFile);
    }

    /**
     * A failed PackageManager installer must not rediscover and reinstall successful apps on retry.
     *
     * @return void
     */
    public function testFullInstallRetryDoesNotRepeatDiscovery() : void
    {
        RetryStaticInstaller::$failures = ['axenox.PackageManager' => new \RuntimeException('Failed')];

        self::assertSame(1, RetryStaticInstaller::runComposerScript('composerFinishInstall'));
        self::assertSame([
            'install' => ['axenox.PackageManager'],
            'fullInstallPending' => true
        ], RetryStaticInstaller::readQueue());

        RetryStaticInstaller::$attempts = [];
        RetryStaticInstaller::$failures = [];
        self::assertSame(0, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertSame(['axenox.PackageManager'], RetryStaticInstaller::$attempts);
        self::assertFileDoesNotExist($this->tempFile);
    }

    /**
     * Removing successful queue entries must not discard the list needed for later backup cleanup.
     *
     * @return void
     */
    public function testFailurePreservesBackupHistoryAcrossRetries() : void
    {
        RetryStaticInstaller::writeQueue([
            'update' => ['test.Success', 'test.Failed'],
            'backupTime' => 'test-backup'
        ]);
        RetryStaticInstaller::$failures = ['test.Failed' => new \RuntimeException('Failed')];

        self::assertSame(1, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertSame([
            'update' => ['test.Failed'],
            'backupTime' => 'test-backup',
            'backupUpdatedApps' => ['test.Success', 'test.Failed']
        ], RetryStaticInstaller::readQueue());
        self::assertSame(1, RetryStaticInstaller::runComposerScript('composerFinishUpdate'));
        self::assertSame(['test.Success', 'test.Failed'], RetryStaticInstaller::readQueue()['backupUpdatedApps']);
    }

    /**
     * The app-level wrapper must propagate startup errors instead of returning a failure-shaped string.
     *
     * @return void
     */
    public function testInstallAppPropagatesStartupFailure() : void
    {
        $installer = new StartupFailureStaticInstaller();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workbench startup failed');
        $installer->installApp('test.App');
    }
}

/**
 * Substitutes app execution and the queue location, leaving orchestration and persistence real.
 */
class RetryStaticInstaller extends StaticInstaller
{
    public static string $tempFile;
    public static array $failures = [];
    public static array $attempts = [];
    public static array $queuesBeforeInstall = [];
    public static bool $generatedBOM = false;

    /**
     * Reads this scenario's real JSON queue.
     *
     * @return array
     */
    public static function readQueue() : array
    {
        return parent::getTempFile();
    }

    /**
     * Writes this scenario's real JSON queue.
     *
     * @param array $queue
     * @return void
     */
    public static function writeQueue(array $queue) : void
    {
        parent::setTempFile($queue);
    }

    /**
     * Records execution and simulates failures without invoking app installers.
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\StaticInstaller::install()
     */
    public static function install($app_alias)
    {
        self::$attempts[] = $app_alias;
        self::$queuesBeforeInstall[$app_alias] = self::readQueue();
        if (isset(self::$failures[$app_alias])) {
            throw self::$failures[$app_alias];
        }
        return 'Installed ' . $app_alias;
    }

    /**
     * Keeps the production queue untouched.
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\StaticInstaller::getTempFilePathAbsolute()
     */
    protected static function getTempFilePathAbsolute()
    {
        return self::$tempFile;
    }

    /**
     * Prevents migration of the installation's actual composer.json.
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\StaticInstaller::migrateRootComposerJson()
     */
    public static function migrateRootComposerJson(?string $composerJsonPath = null) : bool
    {
        return false;
    }

    /**
     * Records BOM execution without accessing installed apps or a Workbench.
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\StaticInstaller::generateLicenseBOM()
     */
    public static function generateLicenseBOM()
    {
        self::$generatedBOM = true;
    }
}

/**
 * Exercises the app-level failure path without constructing or mocking a Workbench.
 */
class StartupFailureStaticInstaller extends StaticInstaller
{
    /**
     * Simulates a startup exception before a Workbench is available for logging.
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\StaticInstaller::getWorkbench()
     */
    public function getWorkbench() : WorkbenchInterface
    {
        throw new \RuntimeException('Workbench startup failed');
    }
}
