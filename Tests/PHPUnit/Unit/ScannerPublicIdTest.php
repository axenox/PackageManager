<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\LicenseScanner;
use axenox\PackageManager\Common\Audit\Scanner\OsvAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\TrivySBOMScanner;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;

/**
 * Verifies scanner-owned public identities using local advisory evidence only.
 */
class ScannerPublicIdTest extends AuditTestCase
{
    /**
     * Shared advisory URLs do not override scanner identities when grouping findings.
     *
     * @return void
     */
    public function testDifferentPublicIdsRequireMatchingTitlesToMerge() : void
    {
        $url = 'https://github.com/advisories/GHSA-abcd-1234-efgh';
        $composer = new ComposerAuditScanner($this->workbench);
        $osv = new OsvAuditScanner($this->workbench);
        $composerFinding = $this->invokeProtected($composer, 'createFinding', ['example/library', [
            'advisoryId' => 'PKSA-fixture', 'title' => 'Composer title', 'link' => $url
        ], '1.0.0']);
        $dependency = [
            'query' => [], 'name' => 'example/library', 'version' => '1.0.0', 'ecosystem' => 'composer'
        ];
        $action = (new \ReflectionClass(Audit::class))->newInstanceWithoutConstructor();
        foreach (['Different title' => 2, 'COMPOSER TITLE' => 1] as $title => $expectedGroups) {
            $osvFindings = $this->invokeProtected($osv, 'normalize', [[
                'id' => 'GHSA-abcd-1234-efgh', 'summary' => $title
            ], $dependency]);
            $groups = $this->invokeProtected($action, 'groupFindings', [[$composerFinding, $osvFindings[0]]]);
            self::assertCount($expectedGroups, $groups);
            self::assertSame('PKSA-fixture', $composerFinding->getPublicId());
            self::assertSame('GHSA-abcd-1234-efgh', $osvFindings[0]->getPublicId());
            if ($expectedGroups === 1) {
                self::assertSame('GHSA-abcd-1234-efgh', $groups[0]->getPublicId());
                self::assertCount(2, $groups[0]->getMergedFindings());
            }
        }
    }

    /**
     * npm's generated source prefix must not become a public advisory identifier.
     * 
     * @return void
     */
    public function testNpmRequiresCveOrLinkedGhsaForPublicIdentity() : void
    {
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        foreach ([
            ['', '', ''],
            ['', 'https://example.org/advisory/123', ''],
            ['', 'https://github.com/advisories/GHSA-abcd-1234-efgh', 'GHSA-abcd-1234-efgh'],
            ['cve-2026-1234', 'https://github.com/advisories/GHSA-abcd-1234-efgh', 'CVE-2026-1234']
        ] as [$cve, $url, $expected]) {
            $finding = $this->invokeProtected($scanner, 'createFinding', ['npm-asset/library', [
                'id' => 123, 'url' => $url
            ], $cve]);
            self::assertSame($expected, $finding->getPublicId());
            self::assertSame($cve !== '' ? $cve : 'npm:123', $finding->getSourceId());
        }
    }

    /**
     * Composer uses its structured identity fields without interpreting advisory URLs.
     * 
     * @return void
     */
    public function testComposerSelectsPublishedIdentifiersAndAllowsMissingIds() : void
    {
        $scanner = new ComposerAuditScanner($this->workbench);
        foreach ([
            [[], ''],
            [['advisoryId' => 'PKSA-fixture'], 'PKSA-fixture'],
            [['advisoryId' => 'PKSA-fixture', 'link' => 'https://github.com/advisories/GHSA-abcd-1234-efgh'], 'PKSA-fixture'],
            [['link' => 'https://github.com/advisories/GHSA-abcd-1234-efgh'], ''],
            [['cve' => '', 'advisoryId' => 'PKSA-fixture'], 'PKSA-fixture'],
            [['cve' => 'cve-2026-1234', 'advisoryId' => 'PKSA-fixture'], 'cve-2026-1234']
        ] as [$advisory, $expected]) {
            $finding = $this->invokeProtected($scanner, 'createFinding', ['example/library', $advisory]);
            self::assertSame($expected, $finding->getPublicId());
        }
    }

    /**
     * OSV and Trivy explicitly publish their external advisory identities.
     * 
     * @return void
     */
    public function testOsvAndTrivyRetainPublishedAdvisoryIds() : void
    {
        $osv = new OsvAuditScanner($this->workbench);
        foreach ([
            [[], 'OSV-2026-1234'],
            [['cve-2026-1234'], 'CVE-2026-1234']
        ] as [$aliases, $expected]) {
            $findings = $this->invokeProtected($osv, 'normalize', [
                ['id' => 'OSV-2026-1234', 'aliases' => $aliases],
                ['query' => [], 'name' => 'example/library', 'version' => '1.0.0']
            ]);
            self::assertSame($expected, $findings[0]->getPublicId());
            self::assertSame('OSV-2026-1234', $findings[0]->getSourceId());
        }
        $trivy = new TrivySBOMScanner($this->workbench);
        foreach (['cve-2026-1234', 'GHSA-abcd-1234-efgh', 'TRIVY-native-id'] as $id) {
            $finding = $this->invokeProtected($trivy, 'createFinding', [[
                'PkgName' => 'example/library', 'VulnerabilityID' => $id,
                'PrimaryURL' => 'https://github.com/advisories/GHSA-other-1234-efgh'
            ], 'composer']);
            self::assertSame($id, $finding->getPublicId());
            self::assertSame($id, $finding->getSourceId());
        }
    }

    /**
     * License checks retain internal categories without inventing public identifiers.
     * 
     * @return void
     */
    public function testLicenseFindingsLeavePublicIdentityEmpty() : void
    {
        $task = new GenericTask($this->workbench);
        $task->setParameter('sbom', ['packages' => [['name' => 'example/library', 'version' => '1.0.0']]]);
        $findings = (new LicenseScanner($this->workbench))->audit($task);
        self::assertCount(2, $findings);
        self::assertSame(['missing-license', 'missing-license-text'], array_map(static function ($finding) {
            return $finding->getSourceId();
        }, $findings));
        foreach ($findings as $finding) {
            self::assertSame('', $finding->getPublicId());
        }
    }
}
