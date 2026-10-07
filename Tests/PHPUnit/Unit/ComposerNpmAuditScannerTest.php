<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureNpmScanner;
use exface\Core\CommonLogic\Tasks\GenericTask;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/** Covers npm input paths and API contracts using local files and a mocked HTTP transport. */
class ComposerNpmAuditScannerTest extends AuditTestCase
{
    private $scanner;
    private $task;
    private $requests = [];
    private $responses;
    private $lockJson;

    /** {@inheritDoc} @see AuditTestCase::setUp() */
    protected function setUp() : void
    {
        parent::setUp();
        $this->requests = [];
        $this->lockJson = json_encode([
            'packages' => [['name' => 'npm-asset/scope--package', 'version' => 'v1.0.0']],
            'packages-dev' => [['name' => 'npm-asset/dev-package', 'version' => '2.0.0']]
        ], JSON_THROW_ON_ERROR);
        $this->responses = new MockHandler();
        $handler = HandlerStack::create($this->responses);
        $handler->push(Middleware::history($this->requests));
        $this->scanner = new FixtureNpmScanner($this->workbench);
        $this->scanner->client = new Client(['handler' => $handler]);
        $this->task = new GenericTask($this->workbench);
        $this->task->setParameter('composer_lock', $this->lockJson);
    }

    /** Folder and archived locks must produce identical findings without installing project code. */
    public function testFolderAndArchivedLocksUseTheSameReadOnlyApiRequest() : void
    {
        $folder = $this->temporaryFolder();
        $this->writeFixture($folder, 'composer.lock', $this->lockJson);
        $response = json_encode($this->linkedNpmResponse(), JSON_THROW_ON_ERROR);
        $this->responses->append(new Response(200, [], $response), new Response(200, [], $response));
        $folderTask = new GenericTask($this->workbench);
        $folderTask->setParameter('folder', $folder);
        self::assertTrue($this->scanner->supports($folderTask));
        $folderRows = $this->scanner->audit($folderTask);
        $this->scanner->install($folderTask);
        self::assertFileDoesNotExist($folder . '/composer.json');
        self::assertSame($this->lockJson, file_get_contents($folder . '/composer.lock'));
        $archivedFindings = $this->scanner->audit($this->task);
        self::assertCount(count($folderRows), $archivedFindings);
        foreach ($folderRows as $index => $finding) {
            self::assertTrue($finding->is($archivedFindings[$index]));
        }
        self::assertSame('npm:123', $folderRows[0]->getSourceId());
        self::assertSame('', $folderRows[0]->getCve());
        self::assertCount(2, $this->requests);
        foreach ($this->requests as $request) {
            self::assertSame('POST', $request['request']->getMethod());
            self::assertSame('https://registry.npmjs.org/-/npm/v1/security/advisories/bulk', (string) $request['request']->getUri());
            self::assertSame(
                ['@scope/package' => ['1.0.0'], 'dev-package' => ['2.0.0']],
                json_decode((string) $request['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)
            );
        }
    }

    /**
     * A null artifact is invalid input, not permission to audit the installation.
     * 
     * @return void
     */
    public function testNullComposerLockParameterDoesNotFallBack() : void
    {
        $this->task->setParameter('composer_lock', null);
        $this->expectException(\exface\Core\Exceptions\RuntimeException::class);
        $this->expectExceptionMessage('Scanner artifact or response must be a JSON object or array.');
        $this->scanner->supports($this->task);
    }

    /** An empty advisory response is a successful scan with no findings. */
    public function testEmptyRegistryResponseProducesNoFindings() : void
    {
        $this->responses->append(new Response(200, [], '{}'));
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(1, $this->requests);
    }

    /** Registry outages must propagate rather than masquerading as a clean dependency tree. */
    public function testRegistryFailureRetainsHttpStatus() : void
    {
        $this->responses->append(new Response(503, [], 'Registry unavailable'));
        try {
            $this->scanner->audit($this->task);
            self::fail('Registry failure was swallowed.');
        } catch (ServerException $error) {
            self::assertSame(503, $error->getResponse()->getStatusCode());
        }
    }

    /** Empty locks must bypass the network entirely, not merely tolerate an empty API response. */
    public function testEmptyLockDoesNotQueryTheRegistry() : void
    {
        $this->task->setParameter('composer_lock', ['packages' => []]);
        self::assertFalse($this->scanner->supports($this->task));
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(0, $this->requests);
    }
}