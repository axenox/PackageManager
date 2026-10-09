<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\TrivySBOMScanner;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use axenox\PackageManager\Tests\PHPUnit\Support\FixtureTrivyScanner;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;

/** Covers local advisory parsing without CLI tools, live HTTP requests or database access. */
class ScannerNormalizationTest extends AuditTestCase
{
    /**
     * SBOM parameters accept JSON, arrays and UXON without using folder artifacts.
     * 
     * @return void
     */
    public function testTrivyReadsSbomParameterAndRemovesTemporaryFiles() : void
    {
        $sbom = ['bomFormat' => 'CycloneDX', 'components' => []];
        foreach ([json_encode($sbom, JSON_THROW_ON_ERROR), $sbom, new UxonObject($sbom)] as $input) {
            $scanner = new FixtureTrivyScanner($this->workbench);
            $task = new GenericTask($this->workbench);
            $task->setParameter('sbom', $input);
            $task->setParameter('folder', $this->temporaryFolder() . '/missing');
            self::assertTrue($scanner->supports($task));
            self::assertSame([], $scanner->audit($task));
            self::assertSame([$sbom], $scanner->artifacts);
            self::assertFileDoesNotExist($scanner->paths[0]);
        }
    }

    /**
     * Composer-lock-only audits must not pick up installation SBOM files.
     * 
     * @return void
     */
    public function testTrivyDoesNotFallBackForComposerLockParameter() : void
    {
        $scanner = new FixtureTrivyScanner($this->workbench);
        $task = new GenericTask($this->workbench);
        $task->setParameter('composer_lock', ['packages' => []]);
        self::assertFalse($scanner->supports($task));
        self::assertSame([], $scanner->audit($task));
        self::assertSame([], $scanner->paths);
    }

    /**
     * Unsupported SBOM content fails instead of scanning unrelated files.
     * 
     * @return void
     */
    public function testTrivyRejectsInvalidSbomParameter() : void
    {
        $scanner = new FixtureTrivyScanner($this->workbench);
        $task = new GenericTask($this->workbench);
        $task->setParameter('sbom', ['packages' => []]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SBOM must be a CycloneDX or SPDX JSON document.');
        $scanner->audit($task);
    }

    /**
     * Combined JSON belongs to OSV and must not trigger an unsupported Trivy audit.
     * 
     * @return void
     */
    public function testTrivyDeclinesCombinedJsonInventory() : void
    {
        $scanner = new FixtureTrivyScanner($this->workbench);
        $task = new GenericTask($this->workbench);
        $task->setParameter('sbom', '{"packages":[]}');
        self::assertFalse($scanner->supports($task));
        self::assertSame([], $scanner->paths);
    }

    /** Ensures multiple CVEs retain separate evidence and scoped npm names use Composer aliases. */
    public function testNpmNormalizationRetainsCvesSeverityAndRemediation() : void
    {
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        $rows = $this->invokeProtected($scanner, 'normalize', [$this->npmResponse()]);
        self::assertCount(2, $rows);
        self::assertSame('npm-asset/scope--package', $rows[0]->getPackage());
        self::assertSame(['CVE-2026-0001', 'CVE-2026-0002'], array_map(static function ($finding) { return $finding->getCve(); }, $rows));
        self::assertSame(200, $rows[0]->getLevel());
        self::assertSame('Upgrade to version 2', $rows[0]->getRemediation());
    }

    /**
     * Native npm fields map directly to finding evidence without a shared key schema.
     * 
     * @return void
     */
    public function testNpmNativeResponseFieldsMapDirectlyToFinding() : void
    {
        $scanner = new ComposerNpmAuditScanner($this->workbench);
        $rows = $this->invokeProtected($scanner, 'normalize', [['package' => [[
            'id' => 456,
            'title' => 'npm advisory',
            'severity' => 'moderate',
            'url' => 'https://example.org/npm',
            'overview' => 'npm description',
            'recommendation' => 'Upgrade npm dependency',
            'vulnerable_versions' => '<2',
            'patched_versions' => '>=2'
        ]]]]);
        self::assertCount(1, $rows);
        $finding = $rows[0];
        self::assertSame('npm:456', $finding->getSourceId());
        self::assertSame('npm advisory', $finding->getName());
        self::assertSame('npm', $finding->getSource());
        self::assertSame('moderate', $finding->getSourceLevel());
        self::assertSame(200, $finding->getLevel());
        self::assertSame('https://example.org/npm', $finding->getDetailsUrl());
        self::assertSame('npm description', $finding->getDescription());
        self::assertSame('Upgrade npm dependency', $finding->getRemediation());
        self::assertSame('<2', $finding->getVersionsAffected());
        self::assertSame('>=2', $finding->getVersionFixed());
        self::assertSame('', $finding->getVersionInstalled());
    }

    /** Prevents native advisory identifiers from being presented as CVEs. */
    public function testNativeAdvisoryIdIsNotACve() : void
    {
        $scanner = new ComposerAuditScanner($this->workbench);
        $finding = $this->invokeProtected($scanner, 'createFinding', ['php/package', [
            'advisoryId' => 'PKSA-generated', 'title' => 'No CVE', 'severity' => 'high'
        ]]);
        self::assertSame('PKSA-generated', $finding->getSourceId());
        self::assertSame('', $finding->getCve());
    }

    /** Keeps public CVE identities consistent when a source uses lowercase. */
    public function testCveCasingIsNormalized() : void
    {
        $scanner = new ComposerAuditScanner($this->workbench);
        $finding = $this->invokeProtected($scanner, 'createFinding', ['php/package', ['cve' => 'cve-2026-0004']]);
        self::assertSame('CVE-2026-0004', $finding->getCve());
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
        self::assertSame(400, $rows[0]->getLevel());
        self::assertSame('EOL', $rows[1]->getType());
        self::assertSame('CVE-2026-0001', $rows[0]->getCve());
        self::assertSame('', $rows[1]->getCve());
        self::assertSame('npm-asset/scope--package', $rows[0]->getPackage());
    }

    /**
     * Native Trivy fields and PURL metadata map directly to finding evidence.
     * 
     * @return void
     */
    public function testTrivyNativeResponseFieldsMapDirectlyToFinding() : void
    {
        $scanner = new TrivySBOMScanner($this->workbench);
        $rows = $this->invokeProtected($scanner, 'normalize', [[
            'SchemaVersion' => 2,
            'Results' => [['Type' => 'other', 'Vulnerabilities' => [[
                'PkgName' => '@scope/package',
                'PkgIdentifier' => ['PURL' => 'pkg:npm/%40scope/package@1.0.0'],
                'VulnerabilityID' => 'CVE-2026-0005',
                'Title' => 'Trivy advisory',
                'Severity' => 'HIGH',
                'References' => ['https://example.org/trivy'],
                'Description' => 'Trivy description',
                'InstalledVersion' => '1.0.0',
                'FixedVersion' => '2.0.0'
            ]]]]
        ]]);
        self::assertCount(1, $rows);
        $finding = $rows[0];
        self::assertSame('npm-asset/scope--package', $finding->getPackage());
        self::assertSame('CVE-2026-0005', $finding->getCve());
        self::assertSame('Trivy advisory', $finding->getName());
        self::assertSame('trivy', $finding->getSource());
        self::assertSame('HIGH', $finding->getSourceLevel());
        self::assertSame(300, $finding->getLevel());
        self::assertSame('https://example.org/trivy', $finding->getDetailsUrl());
        self::assertSame('Trivy description', $finding->getDescription());
        self::assertSame('Upgrade to 2.0.0', $finding->getRemediation());
        self::assertSame('1.0.0', $finding->getVersionInstalled());
        self::assertSame('2.0.0', $finding->getVersionFixed());
    }

    /** Prevents Trivy GHSA identifiers from populating the CVE field. */
    public function testTrivyNativeIdIsNotACve() : void
    {
        $scanner = new TrivySBOMScanner($this->workbench);
        $rows = $this->invokeProtected($scanner, 'normalize', [[
            'SchemaVersion' => 2,
            'Results' => [['Vulnerabilities' => [['PkgName' => 'php/package', 'VulnerabilityID' => 'GHSA-fixture']]]]
        ]]);
        self::assertSame('GHSA-fixture', $rows[0]->getSourceId());
        self::assertSame('', $rows[0]->getCve());
    }

    /** Unknown severities must not understate risk in the consolidated report. */
    public function testUnknownSeverityDefaultsToHigh() : void
    {
        self::assertSame(300, VulnerabilityLevelDataType::normalize('unknown'));
    }

    /**
     * Numeric levels preserve scanner aliases and support ordered comparisons.
     * 
     * @return void
     */
    public function testNumericSeverityNormalizationAndComparison() : void
    {
        foreach (['low' => 100, 'info' => 100, 'informational' => 100, 'negligible' => 100,
            'medium' => 200, 'moderate' => 200, 'high' => 300, 'critical' => 400] as $label => $level) {
            self::assertSame($level, VulnerabilityLevelDataType::normalize(' ' . strtoupper($label) . ' '));
            self::assertSame($level, VulnerabilityLevelDataType::cast((string) $level));
        }
        foreach ([100, 200, 300, 400] as $left) {
            foreach ([100, 200, 300, 400] as $right) {
                self::assertSame($left <=> $right, VulnerabilityLevelDataType::compare($left, $right));
                self::assertSame($left > $right, VulnerabilityLevelDataType::isHigher($left, $right));
                self::assertSame($left < $right, VulnerabilityLevelDataType::isLower($left, $right));
            }
        }
    }

    /**
    * Numeric enum values retain constant names used by severity translation keys.
     * 
     * @return void
     */
    public function testNumericSeverityConstantsRetainLabelNames() : void
    {
        self::assertSame([400, 300, 200, 100], VulnerabilityLevelDataType::getValuesStatic());
        foreach ([400 => 'CRITICAL', 300 => 'HIGH', 200 => 'MEDIUM', 100 => 'LOW'] as $level => $name) {
            self::assertSame($name, VulnerabilityLevelDataType::findConstant($level));
        }
    }
}