<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Audit\ComposerNpmAuditScanner;
use axenox\PackageManager\Audit\TrivySBOMScanner;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;

/** Covers local advisory parsing without CLI tools, live HTTP requests or database access. */
class ScannerNormalizationTest extends AuditTestCase
{
    /** Ensures multiple CVEs retain separate evidence and scoped npm names use Composer aliases. */
    public function testNpmNormalizationRetainsCvesSeverityAndRemediation() : void
    {
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        $rows = $this->invokeProtected($scanner, 'normalize', [$this->npmResponse()]);
        self::assertCount(2, $rows);
        self::assertSame('npm-asset/scope--package', $rows[0]['PACKAGE']);
        self::assertSame(['CVE-2026-0001', 'CVE-2026-0002'], array_column($rows, 'CVE'));
        self::assertSame('medium', $rows[0]['LEVEL']);
        self::assertSame('Upgrade to version 2', $rows[0]['REMEDIATION']);
    }

    /** Prevents native advisory identifiers from being presented as CVEs. */
    public function testNativeAdvisoryIdIsNotACve() : void
    {
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        $finding = $this->invokeProtected($scanner, 'finding', ['composer', 'php/package', [
            'advisoryId' => 'PKSA-generated', 'title' => 'No CVE', 'severity' => 'high'
        ]]);
        self::assertSame('PKSA-generated', $finding['ID']);
        self::assertSame('', $finding['CVE']);
    }

    /** Keeps public CVE identities consistent when a source uses lowercase. */
    public function testCveCasingIsNormalized() : void
    {
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        $finding = $this->invokeProtected($scanner, 'finding', ['composer', 'php/package', ['cve' => 'cve-2026-0004']]);
        self::assertSame('CVE-2026-0004', $finding['CVE']);
    }

    /** Excludes PHP dependencies from npm requests and strips Composer version prefixes. */
    public function testNpmDependenciesAreExtractedFromComposerLock() : void
    {
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        $dependencies = $this->invokeProtected($scanner, 'dependencies', [['packages' => [
            ['name' => 'npm-asset/scope--package', 'version' => 'v1.0.0'],
            ['name' => 'php/package', 'version' => '1.0.0']
        ]]]);
        self::assertSame(['@scope/package' => ['1.0.0']], $dependencies);
    }

    /** Keeps Trivy package identities compatible with npm evidence while retaining OS EOL findings. */
    public function testTrivyNormalizationRetainsCveAndEndOfLifeEvidence() : void
    {
        $scanner = new TrivySBOMScanner($this->workbench);
        $rows = $this->invokeProtected($scanner, 'normalize', [$this->trivyResponse()]);
        self::assertSame('critical', $rows[0]['LEVEL']);
        self::assertSame('EOL', $rows[1]['TYPE']);
        self::assertSame('CVE-2026-0001', $rows[0]['CVE']);
        self::assertSame('', $rows[1]['CVE']);
        self::assertSame('npm-asset/scope--package', $rows[0]['PACKAGE']);
    }

    /** Prevents Trivy GHSA identifiers from populating the CVE field. */
    public function testTrivyNativeIdIsNotACve() : void
    {
        $scanner = new TrivySBOMScanner($this->workbench);
        $rows = $this->invokeProtected($scanner, 'normalize', [[
            'SchemaVersion' => 2,
            'Results' => [['Vulnerabilities' => [['PkgName' => 'php/package', 'VulnerabilityID' => 'GHSA-fixture']]]]
        ]]);
        self::assertSame('GHSA-fixture', $rows[0]['ID']);
        self::assertSame('', $rows[0]['CVE']);
    }

    /** Unknown severities must not understate risk in the consolidated report. */
    public function testUnknownSeverityDefaultsToHigh() : void
    {
        self::assertSame('high', VulnerabilityLevelDataType::normalize('unknown'));
    }
}