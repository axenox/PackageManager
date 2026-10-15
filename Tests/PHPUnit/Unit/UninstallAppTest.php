<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\UninstallApp;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Checks uninstall CLI configuration without a Workbench or persisted metamodel.
 */
class UninstallAppTest extends TestCase
{
    /**
     * Omitting the option must preserve strict data cleanup.
     *
     * @return void
     */
    public function testIgnoreErrorsDefaultsToFalse(): void
    {
        $this->assertSame([['my.App'], false, false], $this->prepareDeferredArguments(null));
    }

    /**
     * A true CLI value must reach deferred uninstall execution.
     *
     * @return void
     */
    public function testIgnoreErrorsCanBeEnabled(): void
    {
        $this->assertSame([['my.App'], false, true], $this->prepareDeferredArguments('true'));
    }

    /**
     * The string false must not enable error ignoring.
     *
     * @return void
     */
    public function testIgnoreErrorsCanBeExplicitlyDisabled(): void
    {
        $this->assertSame([['my.App'], false, false], $this->prepareDeferredArguments('false'));
    }

    /**
     * The CLI must expose the hyphenated option alongside existing uninstall options.
     *
     * @return void
     */
    public function testCliOptionsIncludeIgnoreErrors(): void
    {
        $action = (new ReflectionClass(UninstallApp::class))->newInstanceWithoutConstructor();
        $names = array_map(function ($option) {
            return $option->getName();
        }, $action->getCliOptions());

        $this->assertContains('ignore-errors', $names);
        $this->assertContains('keep_data_in_other_apps', $names);
    }

    /**
     * Exercises task parsing while substituting only database-bound app selection.
     *
     * @param string|null $value NULL represents an omitted CLI option.
     * @return array
     */
    private function prepareDeferredArguments(?string $value): array
    {
        require_once dirname((new ReflectionClass(BooleanDataType::class))->getFileName(), 2)
            . DIRECTORY_SEPARATOR . 'CommonLogic' . DIRECTORY_SEPARATOR . 'Constants.php';

        $action = $this->getMockBuilder(UninstallApp::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTargetAppAliases'])
            ->getMock();
        $action->method('getTargetAppAliases')->willReturn(['my.App']);
        $task = $this->createMock(TaskInterface::class);
        $task->method('hasParameter')->willReturnCallback(function ($name) use ($value) {
            return $name === UninstallApp::TASK_PARAM_IGNORE_ERRORS && $value !== null;
        });
        $task->method('getParameter')->with(UninstallApp::TASK_PARAM_IGNORE_ERRORS)->willReturn($value);
        $method = new ReflectionMethod(UninstallApp::class, 'performImmediately');
        $method->setAccessible(true);

        return $method->invoke(
            $action,
            $task,
            $this->createMock(DataTransactionInterface::class),
            $this->createMock(ResultMessageStreamInterface::class)
        );
    }
}
