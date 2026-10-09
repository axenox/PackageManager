<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Audit\ComposerAuditScanner;
use axenox\PackageManager\Audit\ComposerNpmAuditScanner;
use axenox\PackageManager\Audit\TrivySBOMScanner;
use axenox\PackageManager\Audit\Finding;
use axenox\PackageManager\Audit\MergedFinding;
use axenox\PackageManager\Interfaces\FindingInterface;
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
        $findings = $this->invokeProtected($this->action, 'toDataSheetRows', [array_merge($this->npmRows, $this->trivyRows, [$this->npmRows[0]])]);
        self::assertCount(3, $findings);
        self::assertSame(400, $findings[0]['LEVEL']);
        self::assertSame('npm; trivy', $findings[0]['SOURCE']);
        self::assertArrayNotHasKey('CVE', $findings[0]);
        self::assertSame('CVE-2026-0001', $findings[0]['PUBLIC_ID']);
        self::assertArrayNotHasKey('DETECTIONS', $findings[0]);
        $groups = $this->invokeProtected($this->action, 'groupFindings', [array_merge($this->npmRows, $this->trivyRows, [$this->npmRows[0]])]);
        self::assertCount(2, $groups[0]->getMergedFindings());
        self::assertSame('CVE-2026-0001', $groups[0]->getMergedFindings()[0]->getCve());
    }

    /**
     * Numeric levels sort by descending severity before public identifier ties.
     * 
     * @return void
     */
    public function testNumericLevelsSortDescendingWithStableTies() : void
    {
        $findings = [];
        foreach (['low', 'medium', 'high', 'critical'] as $level) {
            foreach (['B', 'A'] as $identifier) {
                $findings[] = new Finding($level . $identifier, 'Advisory', 'vulnerability', 'package', 'npm', $level);
            }
        }
        $rows = $this->invokeProtected($this->action, 'toDataSheetRows', [$findings]);
        self::assertSame([400, 400, 300, 300, 200, 200, 100, 100], array_column($rows, 'LEVEL'));
        self::assertSame(['criticalA', 'criticalB', 'highA', 'highB', 'mediumA', 'mediumB', 'lowA', 'lowB'], array_column($rows, 'PUBLIC_ID'));
        self::assertSame($rows, $this->invokeProtected($this->action, 'toDataSheetRows', [array_reverse($findings)]));
    }

    /**
     * CLI reports display severity names while technical result rows stay numeric.
     * 
     * @return void
     */
    public function testCliDisplaysSeverityNamesInsteadOfNumbers() : void
    {
        $findings = [];
        foreach (['low', 'medium', 'high', 'critical'] as $level) {
            $findings[] = new Finding($level, 'Advisory', 'vulnerability', 'package', 'npm', $level);
        }
        $table = $this->invokeProtected($this->action, 'table', [$findings]);
        foreach (['low', 'medium', 'high', 'critical'] as $level) {
            self::assertMatchesRegularExpression('/\|\s*' . $level . '\s*\|/', $table);
        }
        foreach ([100, 200, 300, 400] as $level) {
            self::assertStringNotContainsString((string) $level, $table);
        }
        $rows = $this->invokeProtected($this->action, 'toDataSheetRows', [$findings]);
        self::assertSame([400, 300, 200, 100], array_column($rows, 'LEVEL'));
    }

    /** Internal identities must be unique and stable regardless of scanner execution order. */
    public function testInternalIdsAreStableAcrossScannerOrder() : void
    {
        $rows = array_merge($this->npmRows, $this->trivyRows, [$this->npmRows[0]]);
        $findings = $this->invokeProtected($this->action, 'toDataSheetRows', [$rows]);
        $reordered = $this->invokeProtected($this->action, 'toDataSheetRows', [array_reverse($rows)]);
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

    /**
     * A shared advisory must remain independently actionable for each package.
     * 
     * @return void
     */
    public function testAffectedPackagesRemainSeparate() : void
    {
        $original = $this->npmRows[0];
        $anotherPackage = new Finding($original->getSourceId(), $original->getName(), $original->getType(),
            'npm-asset/other', $original->getSource(), $original->getSourceLevel(), $original->getDetailsUrl(), $original->getCve());
        $rows = $this->invokeProtected($this->action, 'toDataSheetRows', [[$original, $anotherPackage]]);
        self::assertCount(2, $rows);
        self::assertSame(['npm-asset/other', 'npm-asset/scope--package'], array_column($rows, 'PACKAGE'));
        self::assertNotSame($rows[0]['ID'], $rows[1]['ID']);
        $single = $this->invokeProtected($this->action, 'toDataSheetRows', [[$original]])[0];
        self::assertSame($single['ID'], $rows[1]['ID']);
        self::assertSame($single['PUBLIC_ID'], $rows[0]['PUBLIC_ID']);
        $table = $this->invokeProtected($this->action, 'table', [[$original, $anotherPackage]]);
        self::assertSame(2, substr_count($table, $original->getPublicId()));
    }

    /**
     * Public identities group scanners without modifying or replacing the original findings.
     * 
     * @return void
     */
    public function testGroupingKeepsRawFindingsAndHandlesMissingPublicIds() : void
    {
        $first = new Finding('npm:1', 'First', 'vulnerability', 'package', 'npm', 'high', '', '', '', '', '', '', '', 'GHSA-abcd-1234-efgh');
        $second = new Finding('trivy:2', 'Second', 'vulnerability', 'package', 'trivy', 'critical', '', '', '', '', '', '', '', 'ghsa-abcd-1234-efgh');
        $groups = $this->invokeProtected($this->action, 'groupFindings', [[$first, $second, clone $first]]);
        self::assertCount(1, $groups);
        self::assertInstanceOf(MergedFinding::class, $groups[0]);
        self::assertSame([$first, $second], $groups[0]->getMergedFindings());
        $forward = $this->invokeProtected($this->action, 'toDataSheetRows', [[$first, $second]])[0];
        $reverse = $this->invokeProtected($this->action, 'toDataSheetRows', [[$second, $first]])[0];
        self::assertSame($forward['ID'], $reverse['ID']);
        $table = $this->invokeProtected($this->action, 'table', [[$first, $second]]);
        self::assertSame(1, substr_count($table, $first->getPublicId()));
        self::assertStringContainsString('npm; trivy', $table);
        self::assertSame(300, $first->getLevel());

        $nativeFirst = new Finding('native', 'Unsupported', 'EOL', 'package', 'npm', 'high');
        $nativeSecond = new Finding('native', 'Unsupported', 'EOL', 'package', 'trivy', 'high');
        $unnamedFirst = new Finding('', 'First', 'vulnerability', 'package', 'npm', 'high');
        $unnamedSecond = new Finding('', 'Second', 'vulnerability', 'package', 'npm', 'high');
        $rows = $this->invokeProtected($this->action, 'toDataSheetRows', [[$nativeFirst, $nativeSecond, $unnamedFirst, $unnamedSecond]]);
        self::assertCount(4, $rows);
        self::assertCount(4, array_unique(array_column($rows, 'ID')));
        self::assertSame([], $this->invokeProtected($this->action, 'toDataSheetRows', [[]]));
    }

    /**
     * Native-ID fallback groups retain stable output IDs without object identifiers.
     * 
     * @return void
     */
    public function testOutputIdsRemainStableWithoutPublicIdentifiers() : void
    {
        $first = new Finding('native', 'Unsupported', 'EOL', 'package', 'npm', 'high');
        $second = new Finding('NATIVE', 'Unsupported', 'EOL', 'package', 'npm', 'critical');
        $single = $this->invokeProtected($this->action, 'toDataSheetRows', [[$first]])[0];
        $merged = $this->invokeProtected($this->action, 'toDataSheetRows', [[$first, $second]])[0];
        $reverse = $this->invokeProtected($this->action, 'toDataSheetRows', [[$second, $first]])[0];
        $expectedId = hash('sha256', json_encode(['EOL', 'NATIVE', 'package', 'npm'], JSON_THROW_ON_ERROR));
        self::assertSame($expectedId, $single['ID']);
        self::assertSame($single['ID'], $merged['ID']);
        self::assertSame($merged['ID'], $reverse['ID']);
        self::assertSame(400, $merged['LEVEL']);
        $finding = new MergedFinding([$first, $second]);
        self::assertSame('native; NATIVE', $finding->getSourceId());
        self::assertSame($merged, $this->invokeProtected($this->action, 'toDataSheetRow', [$finding]));
        self::assertSame($merged, $this->invokeProtected($this->action, 'toDataSheetRows', [[$finding]])[0]);
    }

    /** Multiple source identifiers for the same linked advisory must retain one public identity. */
    public function testSharedPublicIdDeduplicatesNativeIds() : void
    {
        $anotherNpmId = new Finding('npm:456', $this->linkedNpm->getName(), $this->linkedNpm->getType(),
            $this->linkedNpm->getPackage(), 'npm', $this->linkedNpm->getSourceLevel(), $this->linkedNpm->getDetailsUrl());
        $merged = $this->invokeProtected($this->action, 'toDataSheetRows', [[$this->linkedNpm, $anotherNpmId]]);
        $single = $this->invokeProtected($this->action, 'toDataSheetRows', [[$this->linkedNpm]]);
        self::assertCount(1, $merged);
        self::assertSame('GHSA-jpcq-cgw6-v4j6', $merged[0]['PUBLIC_ID']);
        $group = $this->invokeProtected($this->action, 'groupFindings', [[$this->linkedNpm, $anotherNpmId]])[0];
        self::assertSame('npm:123; npm:456', $group->getSourceId());
        self::assertSame([$this->linkedNpm, $anotherNpmId], $group->getMergedFindings());
        self::assertSame($single[0]['ID'], $merged[0]['ID']);
    }

    /**
    * The action serializes raw and merged findings without exporting nested evidence.
     * 
     * @return void
     */
    public function testResultRowFormattingUsesFindingGettersWithoutDetections() : void
    {
        $first = new Finding('npm:1', 'Advisory', 'vulnerability', 'npm-asset/first', 'npm', 'moderate',
            'https://example.org/advisory', 'CVE-2026-0001', 'Description', 'Upgrade', '<2', '1', '2');
        $second = new Finding('scanner:2', 'Advisory', 'vulnerability', 'npm-asset/first', 'trivy', 'critical', '', 'CVE-2026-0001', '', '', '', '1', '3');
        $row = $this->invokeProtected($this->action, 'toDataSheetRows', [[$first, $second, $first]])[0];
        $single = $this->invokeProtected($this->action, 'toDataSheetRows', [[$first]])[0];
        self::assertSame(Audit::COLUMNS, array_keys($row));
        self::assertSame([
            'LEVEL' => 400,
            'ID' => $single['ID'],
            'NAME' => 'Advisory',
            'TYPE' => 'vulnerability',
            'PACKAGE' => 'npm-asset/first',
            'DETAILS_URL' => 'https://example.org/advisory',
            'SOURCE' => 'npm; trivy',
            'SOURCE_LEVEL' => 'moderate; critical',
            'DESCRIPTION' => 'Description',
            'REMEDIATION' => 'Upgrade',
            'VERSIONS_AFFECTED' => '<2',
            'VERSION_INSTALLED' => '1',
            'VERSION_FIXED' => '2; 3',
            'PUBLIC_ID' => 'CVE-2026-0001'
        ], $row);
        self::assertArrayNotHasKey('DETECTIONS', $row);
        self::assertCount(14, Audit::COLUMNS);
        $rawRow = $this->invokeProtected($this->action, 'toDataSheetRow', [$first]);
        self::assertSame($single['ID'], $rawRow['ID']);
        self::assertSame(200, $rawRow['LEVEL']);
        self::assertSame(Audit::COLUMNS, array_keys($rawRow));
        $group = $this->invokeProtected($this->action, 'groupFindings', [[$first, $second, $first]])[0];
        self::assertSame([$first, $second], $group->getMergedFindings());
        self::assertSame($row, $this->invokeProtected($this->action, 'toDataSheetRow', [$group]));
        self::assertSame('npm', $first->getSource());
        self::assertSame(200, $first->getLevel());
    }

    /**
     * The modeled advisory attributes must match the action's output schema.
     * 
     * @return void
     */
    public function testResultColumnsMatchModeledAttributes() : void
    {
        $model = json_decode(file_get_contents(dirname(__DIR__, 3) . '/Model/axenox.PackageManager.AUDIT_ADVISORY/04_ATTRIBUTE.json'), true, 512, JSON_THROW_ON_ERROR);
        $attributes = array_column($model['rows'], 'ALIAS');
        $columns = Audit::COLUMNS;
        sort($attributes);
        sort($columns);
        self::assertSame($columns, $attributes);
    }

    /** Public reports must hide generated hashes and verbose evidence without changing source rows. */
    public function testTableDisplaysPublicIdentifiersOnly() : void
    {
        $composer = new ComposerAuditScanner($this->workbench);
        $noCve = $this->invokeProtected($composer, 'createFinding', ['php/package', [
            'advisoryId' => 'PKSA-generated', 'title' => 'No CVE', 'severity' => 'high'
        ]]);
        $findings = array_merge($this->npmRows, $this->trivyRows, [$noCve, $this->linkedNpm]);
        $table = $this->invokeProtected($this->action, 'table', [$findings]);
        self::assertStringNotContainsString('SOURCE_LEVEL', $table);
        self::assertStringNotContainsString('DETAILS_URL', $table);
        self::assertStringContainsString('CVE-2026-0001', $table);
        self::assertStringContainsString('PUBLIC ID', $table);
        self::assertStringContainsString('GHSA-jpcq-cgw6-v4j6', $table);
        self::assertStringContainsString('PKSA-generated', $table);
        self::assertStringNotContainsString('npm:123', $table);
        self::assertStringNotContainsString('EOL:alpine', $table);
        $rows = $this->invokeProtected($this->action, 'toDataSheetRows', [$findings]);
        self::assertStringNotContainsString($rows[0]['ID'], $table);
        self::assertSame('npm:123', $this->linkedNpm->getSourceId());
        self::assertSame('', $this->linkedNpm->getCve());
    }

    /** CVEs take precedence over GHSA links, and repeated links must not duplicate the public label. */
    public function testPublicIdPrefersCveAndDeduplicatesLinks() : void
    {
        $withCve = new Finding('npm:123', 'Advisory', 'vulnerability', 'package', 'npm', 'high',
            $this->linkedNpm->getDetailsUrl(), 'CVE-2020-11023');
        self::assertSame('CVE-2020-11023', $withCve->getPublicId());
        $url = $this->linkedNpm->getDetailsUrl();
        $repeatedLinks = new Finding('npm:123', 'Advisory', 'vulnerability', 'package', 'npm', 'high', $url . '; ' . $url . '?source=fixture');
        self::assertSame('GHSA-jpcq-cgw6-v4j6', $repeatedLinks->getPublicId());
    }

    /** Reading a standard result must not defer or repeat physical scanner execution. */
    public function testPerformCompletesSynchronouslyAndReturnsData() : void
    {
        $action = (new \ReflectionClass(FixtureAudit::class))->newInstanceWithoutConstructor();
        $sheet = (new \ReflectionClass(DataSheet::class))->newInstanceWithoutConstructor();
        $action->sheet = $sheet;
        $scanner = new FixtureNpmScanner($this->workbench);
        $task = new GenericTask($this->workbench);
        $task->setParameter('composer_lock', ['packages' => [['name' => 'npm-asset/fixture', 'version' => '1.0.0']]]);
        $scanner->client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], '{"fixture":[{"id":123,"title":"Fixture","severity":"high"}]}')
        ]))]);
        $action->scanner = $scanner;
        $transaction = (new \ReflectionClass(DataTransaction::class))->newInstanceWithoutConstructor();
        $result = $this->invokeProtected($action, 'perform', [$task, $transaction]);
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
        self::assertInstanceOf(FindingInterface::class, $action->findings[0]);
        self::assertSame(1, $scanner->auditCalls);
    }
}