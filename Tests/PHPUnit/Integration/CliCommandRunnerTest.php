<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use exface\Core\Exceptions\CliRuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use PHPUnit\Framework\TestCase;

/** Protects the shared subprocess contracts required by audit scanners using real local PHP commands. */
class CliCommandRunnerTest extends TestCase
{
    /** The executable resolver must return a usable CLI interpreter even when the host is Apache. */
    public function testPhpExecutableRunsCliPhp() : void
    {
        $result = CliCommandRunner::runCliCommandIntoArray(CliCommandRunner::findPhpExecutable(), ['-r', 'echo PHP_SAPI;']);
        self::assertSame('cli', $result['stdout']);
    }

    /** Shell-free execution must preserve spaces, quotes, semicolons and empty argument boundaries. */
    public function testArgumentsRetainTheirLiteralBoundaries() : void
    {
        $arguments = ['two words', '"quoted"', 'semi;colon', ''];
        $result = CliCommandRunner::runCliCommandIntoArray(
            CliCommandRunner::findPhpExecutable(),
            array_merge(['-r', 'echo json_encode(array_slice($argv, 1));', '--'], $arguments)
        );
        self::assertSame($arguments, json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }

    /** Scanners need separate diagnostics, accepted finding exit codes and an explicit working directory. */
    public function testStructuredResultRetainsStreamsExitCodeAndWorkingDirectory() : void
    {
        $result = CliCommandRunner::runCliCommandIntoArray(
            CliCommandRunner::findPhpExecutable(),
            ['-r', 'fwrite(STDOUT, getcwd()); fwrite(STDERR, "diagnostic"); exit(1);'], __DIR__, [0, 1]
        );
        self::assertSame('diagnostic', $result['stderr']);
        self::assertSame(1, $result['exit_code']);
        self::assertSame(realpath(__DIR__), realpath($result['stdout']));
    }

    /** Composer environment overrides belong only to the child process, not the hosting application. */
    public function testEnvironmentOverridesDoNotChangeTheParent() : void
    {
        $originalHome = getenv('COMPOSER_HOME');
        $result = CliCommandRunner::runCliCommandIntoArray(
            CliCommandRunner::findPhpExecutable(), ['-r', 'echo getenv("COMPOSER_HOME");'],
            __DIR__, [0], 300, ['COMPOSER_HOME' => 'audit-fixture-home']
        );
        self::assertSame('audit-fixture-home', $result['stdout']);
        self::assertSame($originalHome, getenv('COMPOSER_HOME'));
    }

    /** Unexpected exit codes must fail loudly while retaining the process exit status. */
    public function testUnexpectedExitCodeIsReported() : void
    {
        try {
            CliCommandRunner::runCliCommandIntoArray(CliCommandRunner::findPhpExecutable(), ['-r', 'exit(2);'], __DIR__);
            self::fail('Unexpected command failure was swallowed.');
        } catch (CliRuntimeException $error) {
            self::assertSame(2, $error->getExitCode());
        }
    }

    /** Missing executables must have the same diagnostic exit code across operating systems. */
    public function testMissingExecutableIsReportedWithExitCode127() : void
    {
        try {
            CliCommandRunner::runCliCommandIntoArray('exf-definitely-missing-audit-command', ['--version']);
            self::fail('Missing executable was not reported.');
        } catch (CliRuntimeException $error) {
            self::assertSame(127, $error->getExitCode());
        }
    }
}