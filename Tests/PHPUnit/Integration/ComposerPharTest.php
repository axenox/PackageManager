<?php
namespace axenox\PackageManager\Tests\PHPUnit\Integration;

use axenox\PackageManager\Audit\ComposerAuditScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\Exceptions\CliRuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** Exercises real PHP subprocesses using local Composer PHAR stand-ins, never live Composer services. */
class ComposerPharTest extends AuditTestCase
{
    private $folder;
    private $task;
    private $scanner;
    private $lockJson;

    /** {@inheritDoc} @see AuditTestCase::setUp() */
    protected function setUp() : void
    {
        parent::setUp();
        $this->folder = $this->temporaryFolder();
        $phar = <<<'PHP'
<?php
if (in_array('--probe-home', $argv, true)) {
    echo getenv('COMPOSER_HOME');
    exit;
}
if (in_array('--version', $argv, true)) {
    echo 'Composer version 2.10.0';
    exit;
}
if (! in_array('audit', $argv, true) || ! in_array('--no-plugins', $argv, true)
    || ! in_array('--no-scripts', $argv, true) || ! is_file('composer.lock')) {
    exit(4);
}
echo json_encode(['advisories' => ['php/package' => [[
    'advisoryId' => 'local-phar', 'title' => 'Local PHAR fixture', 'severity' => 'high',
    'description' => getcwd()
]]], 'abandoned' => []]);
exit(1);
PHP;
        $this->writeFixture($this->folder, 'composer.phar', $phar);
        $this->lockJson = json_encode([
            'packages' => [['name' => 'php/package', 'version' => '1.0.0']], 'packages-dev' => []
        ], JSON_THROW_ON_ERROR);
        $this->writeFixture($this->folder, 'composer.lock', $this->lockJson);
        $this->task = new GenericTask($this->workbench);
        $this->task->setParameter('folder', $this->folder);
        $this->scanner = new ComposerAuditScanner($this->workbench);
    }

    /** Isolates the immutable constant used to reproduce Apache's non-CLI PHP_BINARY value. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLockOnlyScanUsesProjectPharAndCliPhpInAnIsolatedFolder() : void
    {
        define('axenox\\PackageManager\\Audit\\PHP_BINARY', $this->folder . '/httpd.exe');
        $rows = $this->scanner->audit($this->task);
        self::assertCount(1, $rows);
        self::assertSame('local-phar', $rows[0]['ID']);
        self::assertNotSame(realpath($this->folder), $rows[0]['DESCRIPTION']);
        self::assertDirectoryDoesNotExist($rows[0]['DESCRIPTION']);
        self::assertFileDoesNotExist($this->folder . '/composer.json');
        self::assertSame($this->lockJson, file_get_contents($this->folder . '/composer.lock'));
    }

    /** A project with a manifest must use its own Composer PHAR and working directory. */
    public function testManifestScanUsesProjectPharAndWorkingDirectory() : void
    {
        $this->writeFixture($this->folder, 'composer.json', '{"name":"fixture/project","require":{}}');
        $rows = $this->scanner->audit($this->task);
        self::assertCount(1, $rows);
        self::assertSame('local-phar', $rows[0]['ID']);
        self::assertSame(realpath($this->folder), $rows[0]['DESCRIPTION']);
        self::assertSame($this->lockJson, file_get_contents($this->folder . '/composer.lock'));
    }

    /** Web-server environments without profile variables still need a usable Composer home. */
    public function testMissingProfileVariablesSupplyComposerHomeToTheChild() : void
    {
        $probe = <<<'PHP'
require $argv[1];
$scanner = new \axenox\PackageManager\Audit\ComposerAuditScanner(new \exface\Core\CommonLogic\Workbench());
$method = new \ReflectionMethod($scanner, 'composer');
$method->setAccessible(true);
echo $method->invoke($scanner, ['--probe-home'], $argv[2])['stdout'];
PHP;
        $result = CliCommandRunner::runCliCommandIntoArray(
            CliCommandRunner::findPhpExecutable(),
            ['-r', $probe, dirname(__DIR__) . '/bootstrap.php', $this->folder],
            __DIR__, [0], 300, ['COMPOSER_HOME' => false, 'APPDATA' => false, 'HOME' => false]
        );
        self::assertSame($this->workbench->filemanager()->getPathToDataFolder() . '/.composer', $result['stdout']);
    }

    /** A failing project PHAR must not be silently bypassed by a different Composer installation. */
    public function testBrokenProjectPharRetainsItsExitCode() : void
    {
        $this->writeFixture($this->folder, 'composer.json', '{"name":"fixture/project","require":{}}');
        $this->writeFixture($this->folder, 'composer.phar', '<?php exit(4);');
        try {
            $this->scanner->audit($this->task);
            self::fail('Broken project Composer PHAR was silently bypassed.');
        } catch (CliRuntimeException $error) {
            self::assertSame(4, $error->getExitCode());
        }
    }
}