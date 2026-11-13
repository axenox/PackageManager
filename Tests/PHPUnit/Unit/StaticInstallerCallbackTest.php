<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\StaticInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Checks installer orchestration independently of the Composer pending-file workflow.
 */
class StaticInstallerCallbackTest extends TestCase
{
    /**
     * A caller's checkpoint failure must interrupt the batch instead of being treated as an app failure.
     *
     * @return void
     */
    public function testCallbackFailureStopsTheBatch() : void
    {
        $completed = [];
        try {
            CallbackStaticInstaller::runApps(
                ['test.App', 'exface.Core', 'test.App'],
                function (string $appAlias) use (&$completed) : void {
                    $completed[] = $appAlias;
                    throw new \LogicException('Checkpoint failed');
                }
            );
            self::fail('Callback failures must interrupt the batch');
        } catch (\LogicException $e) {
            self::assertSame('Checkpoint failed', $e->getMessage());
            self::assertSame(['exface.Core'], $completed);
        }
    }

    /**
     * Successful callbacks preserve Core-first ordering and deduplicate generated input.
     *
     * @return void
     */
    public function testGeneratedArrayReportsSuccessfulApps() : void
    {
        $completed = [];
        $count = CallbackStaticInstaller::runApps(
            ['test.App', 'exface.Core', 'test.App'],
            function (string $appAlias) use (&$completed) : void {
                $completed[] = $appAlias;
            }
        );
        self::assertSame(2, $count);
        self::assertSame(['exface.Core', 'test.App'], $completed);
    }
}

/**
 * Exposes orchestration with local app execution and forbids any temp-file access.
 */
class CallbackStaticInstaller extends StaticInstaller
{
    /**
     * Runs a generated list with caller-owned completion handling.
     *
     * @param string[] $appAliases
     * @param callable(string): void $onSuccess
     * @return int
     */
    public static function runApps(array $appAliases, callable $onSuccess) : int
    {
        return parent::installApps($appAliases, false, $onSuccess);
    }

    /**
     * Avoids real app installation while exercising the orchestration.
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\StaticInstaller::install()
     */
    public static function install($app_alias)
    {
        return 'Installed ' . $app_alias;
    }

    /**
     * Rejects accidental persistence in the storage-independent orchestration.
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\StaticInstaller::getTempFilePathAbsolute()
     */
    protected static function getTempFilePathAbsolute()
    {
        throw new \LogicException('Orchestration must not access the temp file');
    }
}
