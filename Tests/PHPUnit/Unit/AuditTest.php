<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Audit\ComposerNpmAuditScanner;
use axenox\PackageManager\Audit\TrivySBOMScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureAudit;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureNpmScanner;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\DataSheets\DataSheet;
use exface\Core\CommonLogic\DataTransaction;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Tasks\ResultData;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/** Covers aggregation, public reporting and synchronous results without database access. */
class AuditTest extends AuditTestCase
{
    private $action;
    private $npmRows;
    private $trivyRows;
    private $linkedNpm;

    /** {@inheritDoc} @see AuditTestCase::setUp() */
    protected function setUp() : void
    {
        parent::setUp();
        $this->action = (new \ReflectionClass(Audit::class))->newInstanceWithoutConstructor();
        $npm = new ComposerNpmAuditScanner($this->workbench);
        $this->npmRows = $this->invokeProtected($npm, 'normalize', [$this->npmResponse()]);
        $this->linkedNpm = $this->invokeProtected($npm, 'normalize', [$this->linkedNpmResponse()])[0];
        $this->trivyRows = $this->invokeProtected(new TrivySBOMScanner($this->workbench), 'normalize', [$this->trivyResponse()]);
    }

    /** Deduplicated advisories must keep source evidence and the highest observed severity. */
    public function testMergeRetainsEvidenceAndOrdersBySeverity() : void
    {
        $findings = $this->invokeProtected($this->action, 'mergeFindings', [array_merge($this->npmRows, $this->trivyRows, [$this->npmRows[0]])]);
        self::assertCount(3, $findings);
        self::assertSame('critical', $findings[0]['LEVEL']);
        self::assertSame('npm; trivy', $findings[0]['SOURCE']);
        self::assertArrayNotHasKey('CVE', $findings[0]);
        self::assertSame('CVE-2026-0001', $findings[0]['PUBLIC_ID']);
        $detections = json_decode($findings[0]['DETECTIONS'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $detections);
        self::assertSame('CVE-2026-0001', $detections[0]['CVE']);
    }

    /** Internal identities must be unique and stable regardless of scanner execution order. */
    public function testInternalIdsAreStableAcrossScannerOrder() : void
    {
        $rows = array_merge($this->npmRows, $this->trivyRows, [$this->npmRows[0]]);
        $findings = $this->invokeProtected($this->action, 'mergeFindings', [$rows]);
        $reordered = $this->invokeProtected($this->action, 'mergeFindings', [array_reverse($rows)]);
        foreach ($findings as $finding) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $finding['ID']);
        }
        $ids = array_column($findings, 'ID');
        self::assertCount(count($findings), array_unique($ids));
        $reorderedIds = array_column($reordered, 'ID');
        sort($ids);
        sort($reorderedIds);
        self::assertSame($ids, $reorderedIds);
    }

    /** Package or severity changes must not create a new identity for an existing advisory. */
    public function testAffectedPackagesAreMergedWithoutChangingIdentity() : void
    {
        $anotherPackage = $this->npmRows[0];
        $anotherPackage['PACKAGE'] = 'npm-asset/other';
        $merged = $this->invokeProtected($this->action, 'mergeFindings', [[$this->npmRows[0], $anotherPackage]]);
        $withTrivy = $this->invokeProtected($this->action, 'mergeFindings', [array_merge($this->npmRows, $this->trivyRows)]);
        self::assertCount(1, $merged);
        self::assertSame('npm-asset/scope--package; npm-asset/other', $merged[0]['PACKAGE']);
        self::assertSame($withTrivy[0]['ID'], $merged[0]['ID']);
    }

    /** Multiple source identifiers for the same linked advisory must retain one public identity. */
    public function testSharedPublicIdDeduplicatesNativeIds() : void
    {
        $anotherNpmId = $this->linkedNpm;
        $anotherNpmId['ID'] = 'npm:456';
        $merged = $this->invokeProtected($this->action, 'mergeFindings', [[$this->linkedNpm, $anotherNpmId]]);
        $single = $this->invokeProtected($this->action, 'mergeFindings', [[$this->linkedNpm]]);
        self::assertCount(1, $merged);
        self::assertSame('GHSA-jpcq-cgw6-v4j6', $merged[0]['PUBLIC_ID']);
        self::assertSame(['npm:123', 'npm:456'], array_column(json_decode($merged[0]['DETECTIONS'], true, 512, JSON_THROW_ON_ERROR), 'ID'));
        self::assertSame($single[0]['ID'], $merged[0]['ID']);
    }

    /** Public reports must hide generated hashes and verbose evidence without changing source rows. */
    public function testTableDisplaysPublicIdentifiersOnly() : void
    {
        $npm = new ComposerNpmAuditScanner($this->workbench);
        $noCve = $this->invokeProtected($npm, 'finding', ['composer', 'php/package', [
            'advisoryId' => 'PKSA-generated', 'title' => 'No CVE', 'severity' => 'high'
        ]]);
        $findings = $this->invokeProtected($this->action, 'mergeFindings', [array_merge($this->npmRows, $this->trivyRows, [$noCve, $this->linkedNpm])]);
        $table = $this->invokeProtected($this->action, 'table', [$findings]);
        self::assertStringNotContainsString('SOURCE_LEVEL', $table);
        self::assertStringNotContainsString('DETAILS_URL', $table);
        self::assertStringContainsString('CVE-2026-0001', $table);
        self::assertStringContainsString('PUBLIC ID', $table);
        self::assertStringContainsString('GHSA-jpcq-cgw6-v4j6', $table);
        self::assertStringContainsString('PKSA-generated', $table);
        self::assertStringNotContainsString('npm:123', $table);
        self::assertStringNotContainsString('EOL:alpine', $table);
        self::assertStringNotContainsString($findings[0]['ID'], $table);
        self::assertSame('npm:123', $this->linkedNpm['ID']);
        self::assertSame('', $this->linkedNpm['CVE']);
    }

    /** CVEs take precedence over GHSA links, and repeated links must not duplicate the public label. */
    public function testPublicIdPrefersCveAndDeduplicatesLinks() : void
    {
        $withCve = $this->linkedNpm;
        $withCve['CVE'] = 'CVE-2020-11023';
        self::assertSame('CVE-2020-11023', $this->invokeProtected($this->action, 'displayAdvisoryId', [$withCve]));
        $repeatedLinks = $this->linkedNpm;
        $repeatedLinks['DETAILS_URL'] .= '; ' . $repeatedLinks['DETAILS_URL'] . '?source=fixture';
        self::assertSame('GHSA-jpcq-cgw6-v4j6', $this->invokeProtected($this->action, 'displayAdvisoryId', [$repeatedLinks]));
    }

    /** Reading a standard result must not defer or repeat physical scanner execution. */
    public function testPerformCompletesSynchronouslyAndReturnsData() : void
    {
        $action = (new \ReflectionClass(FixtureAudit::class))->newInstanceWithoutConstructor();
        $sheet = (new \ReflectionClass(DataSheet::class))->newInstanceWithoutConstructor();
        $action->sheet = $sheet;
        $scanner = new FixtureNpmScanner($this->workbench);
        $scanner->rows = [['COMPOSER_LOCK' => ['packages' => [['name' => 'npm-asset/fixture', 'version' => '1.0.0']]]]];
        $scanner->client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '{"fixture":[{"id":123,"title":"Fixture","severity":"high"}]}')
        ]))]);
        $action->scanner = $scanner;
        $transaction = (new \ReflectionClass(DataTransaction::class))->newInstanceWithoutConstructor();
        $result = $this->invokeProtected($action, 'perform', [new GenericTask($this->workbench), $transaction]);
        self::assertInstanceOf(AbstractAction::class, $action);
        self::assertNotInstanceOf(AbstractActionDeferred::class, $action);
        self::assertInstanceOf(ResultData::class, $result);
        self::assertNotInstanceOf(ResultMessageStreamInterface::class, $result);
        self::assertSame(1, $scanner->supportChecks);
        self::assertSame(1, $scanner->auditCalls);
        $message = $result->getMessage();
        self::assertStringContainsString('FixtureNpmScanner: checking input...', $message);
        self::assertStringContainsString('FixtureNpmScanner: completed (1 findings).', $message);
        self::assertSame($sheet, $result->getData());
        self::assertSame($message, $result->getMessage());
        self::assertCount(1, $action->findings);
        self::assertSame(1, $scanner->auditCalls);
    }
}