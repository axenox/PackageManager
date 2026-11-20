<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureAudit;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureComposerScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureNpmScanner;
use exface\Core\CommonLogic\DataTransaction;
use exface\Core\CommonLogic\Model\MetaObject;
use exface\Core\CommonLogic\Model\Model;
use exface\Core\CommonLogic\Tasks\CliTask;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\DataTypes\IntegerDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\CliRuntimeException;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\WorkbenchInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/** Exercises scanner failure isolation through real action reporting without database access. */
class AuditScannerFailureTest extends AuditTestCase
{
    private FixtureAudit $action;

    /** {@inheritDoc} @see AuditTestCase::setUp() */
    protected function setUp() : void
    {
        parent::setUp();
        $app = $this->workbench->getApp('axenox.PackageManager');
        $translator = new \ReflectionProperty(\exface\Core\CommonLogic\Model\App::class, 'translator');
        $translator->setValue($app, $app->getTranslator('en'));
        $this->action = new class($app) extends FixtureAudit {
            public array $configuredScanners = [];

            /** {@inheritDoc} @see FixtureAudit::getScanners() */
            protected function getScanners() : array
            {
                return $this->configuredScanners;
            }
        };
        $object = new MetaObject(new Model($this->workbench));
        $object->setAlias('AUDIT_FAILURE_TEST');
        $object->setNamespace('axenox.PackageManager');
        foreach (Audit::COLUMNS as $column) {
            $type = DataTypeFactory::createFromPrototype(
                $this->workbench,
                $column === 'LEVEL' ? IntegerDataType::class : StringDataType::class
            );
            MetaObjectFactory::addAttributeTemporary($object, $column, $column, $column, $type);
        }
        $this->action->resultObject = $object;
    }

    /** Network and TLS errors remain distinct findings while a later scanner still completes. */
    public function testNetworkAndSslFailuresContinueAndExportSkippedStatus() : void
    {
        $request = new Request('POST', 'https://registry.npmjs.org/-/npm/v1/security/advisories/bulk');
        $networkError = new ConnectException('cURL error 7: Failed to connect to registry.npmjs.org', $request);
        $sslError = new RequestException(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
            $request,
            null,
            null,
            ['errno' => 60]
        );
        $network = $this->npmScanner($networkError);
        $ssl = $this->npmScanner($sslError);
        $healthy = $this->npmScanner(new Response(200, [], '{"fixture":[{"id":123,"title":"Fixture","severity":"high","vulnerable_versions":"<2"}]}'));
        $this->action->configuredScanners = ['network' => $network, 'ssl' => $ssl, 'healthy' => $healthy];
        $folder = $this->temporaryFolder();
        $path = $folder . '/audit.json';
        $arguments = [
            Audit::TASK_PARAM_COMPOSER_LOCK => [
                'packages' => [['name' => 'npm-asset/fixture', 'version' => '1.0.0']]
            ],
            Audit::TASK_PARAM_SBOM => null,
            Audit::TASK_PARAM_FOLDER => null,
            Audit::TASK_PARAM_INSTALL => null,
            Audit::TASK_PARAM_OUTPUT => $path
        ];
        $task = new CliTask($this->workbench, 'axenox.PackageManager:Audit', $arguments, array_fill_keys(array_keys($arguments), null));
        $transaction = (new \ReflectionClass(DataTransaction::class))->newInstanceWithoutConstructor();
        $result = $this->invokeProtected($this->action, 'perform', [$task, $transaction]);
        $rows = $result->getData()->getRows();
        self::assertCount(3, $rows);
        self::assertSame('Fixture', $rows[0]['NAME']);
        $bySource = array_column($rows, null, 'SOURCE');
        $this->assertFailureRow($bySource['network'], 'network', $networkError);
        $this->assertFailureRow($bySource['ssl'], 'ssl', $sslError);
        self::assertStringContainsString('curl.cainfo / openssl.cafile', $bySource['ssl']['REMEDIATION']);
        self::assertSame(1, $healthy->auditCalls);
        self::assertStringNotContainsString('No scanner completed an audit.', $result->getMessage());
        $export = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($rows, $export['findings']);
        self::assertSame(['network' => 'skipped', 'ssl' => 'skipped', 'healthy' => 'completed'], $export['scanners']);
        self::assertCount(2, $export['hints']);
    }

    /** A failed Composer subprocess keeps its diagnostic output and does not prevent later scans. */
    public function testComposerCommandFailureContinuesAudit() : void
    {
        $error = new CliRuntimeException(
            'composer audit --locked --format=json',
            'Could not resolve host: repo.packagist.org',
            1,
            "Composer command failed (exit code 1):\nCould not resolve host: repo.packagist.org"
        );
        $composer = new class($this->workbench, $error) extends FixtureComposerScanner {
            private CliRuntimeException $error;

            /** Supplies the command failure at the external process boundary. */
            public function __construct(WorkbenchInterface $workbench, CliRuntimeException $error)
            {
                parent::__construct($workbench);
                $this->error = $error;
            }

            /** {@inheritDoc} @see FixtureComposerScanner::runComposer() */
            protected function runComposer(array $arguments, string $folder, array $acceptedExitCodes = [0], ?string $composerFolder = null) : array
            {
                throw $this->error;
            }
        };
        $healthy = $this->npmScanner(new Response(200, [], '{}'));
        $this->action->configuredScanners = ['composer' => $composer, 'healthy' => $healthy];
        $task = new GenericTask($this->workbench);
        $task->setParameter(Audit::TASK_PARAM_COMPOSER_LOCK, [
            'packages' => [['name' => 'npm-asset/fixture', 'version' => '1.0.0']]
        ]);
        $transaction = (new \ReflectionClass(DataTransaction::class))->newInstanceWithoutConstructor();
        $result = $this->invokeProtected($this->action, 'perform', [$task, $transaction]);
        $rows = $result->getData()->getRows();
        self::assertCount(1, $rows);
        $this->assertFailureRow($rows[0], 'composer', $error);
        self::assertSame(1, $healthy->auditCalls);
        self::assertStringNotContainsString('No scanner completed an audit.', $result->getMessage());
    }

    /** An entirely failed audit must not look like a clean completed scan. */
    public function testAllScannersFailWithVisibleCompletenessWarning() : void
    {
        $error = new ConnectException('Connection timed out', new Request('POST', 'https://registry.npmjs.org'));
        $this->action->configuredScanners = ['npm' => $this->npmScanner($error)];
        $task = new GenericTask($this->workbench);
        $task->setParameter(Audit::TASK_PARAM_COMPOSER_LOCK, [
            'packages' => [['name' => 'npm-asset/fixture', 'version' => '1.0.0']]
        ]);
        $transaction = (new \ReflectionClass(DataTransaction::class))->newInstanceWithoutConstructor();
        $result = $this->invokeProtected($this->action, 'perform', [$task, $transaction]);
        self::assertCount(1, $result->getData()->getRows());
        self::assertStringContainsString('No scanner completed an audit.', $result->getMessage());
        self::assertStringContainsString('Connection timed out', $result->getMessage());
    }

    /** Controls only the HTTP boundary, retaining npm scanner behavior. */
    private function npmScanner(Response|\Throwable $response) : FixtureNpmScanner
    {
        $scanner = new FixtureNpmScanner($this->workbench);
        $scanner->client = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
        return $scanner;
    }

    /** Verifies the advisory contract in the resulting DataSheet, not just the raw finding. */
    private function assertFailureRow(array $row, string $name, \Throwable $error) : void
    {
        self::assertSame('Scanner ' . $name . ' skipped due to failure', $row['NAME']);
        self::assertSame(get_class($error) . ': ' . $error->getMessage(), $row['DESCRIPTION']);
        self::assertSame(VulnerabilityLevelDataType::MEDIUM, $row['LEVEL']);
        self::assertSame(AdvisoryTypeDataType::UNSCANNABLE, $row['TYPE']);
        self::assertSame('', $row['PACKAGE']);
    }
}
