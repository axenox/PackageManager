<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers package-specific scanner evidence without Workbench.
 * 
 * Result-row formatting is tested with the Audit action, not with findings.
 */
class FindingTest extends TestCase
{
    /**
     * Findings retain one package and one scanner even when public IDs match.
     * 
     * @return void
     */
    public function testEvidenceDistinguishesPackagesAndScanners() : void
    {
        $first = new Finding('npm:1', 'Advisory', 'vulnerability', 'npm-asset/first', 'npm', 'high', '', 'CVE-2026-0001');
        $second = new Finding('scanner:2', 'Advisory', 'vulnerability', 'npm-asset/second', 'trivy', 'critical', '', 'CVE-2026-0001');
        $otherScanner = new Finding('npm:1', 'Advisory', 'vulnerability', 'npm-asset/first', 'trivy', 'high', '', 'CVE-2026-0001');
        $otherPackage = new Finding('npm:1', 'Advisory', 'vulnerability', 'npm-asset/other', 'npm', 'high', '', 'CVE-2026-0001');
        self::assertInstanceOf(FindingInterface::class, $first);
        self::assertSame($first->getPublicId(), $second->getPublicId());
        self::assertFalse($first->is($otherScanner));
        self::assertFalse($first->is($otherPackage));
        self::assertSame(300, $first->getLevel());
        self::assertSame('npm-asset/first', $first->getPackage());
        self::assertSame('npm', $first->getSource());
        self::assertTrue($first->is(clone $first));
        self::assertFalse($first->is($second));
    }

    /**
    * Severity changes remain evidence differences even for the same native advisory.
     * 
     * @return void
     */
    public function testEvidenceComparisonIncludesSeverity() : void
    {
        $first = new Finding('native-id', 'Advisory', 'vulnerability', 'package', 'npm', 'high');
        $second = new Finding('native-id', 'Advisory', 'vulnerability', 'package', 'npm', 'critical');
        self::assertSame($first->getSourceId(), $second->getSourceId());
        self::assertFalse($first->is($second));
    }

    /**
     * Optional evidence and explicit public identities remain accessible through getters.
     * 
     * @return void
     */
    public function testOptionalEvidenceAndExplicitPublicIdentity() : void
    {
        $finding = new Finding('native', 'Advisory', 'vulnerability', 'package', 'composer', 'moderate',
            'https://example.org/advisory', 'cve-2026-0001', 'Description', 'Upgrade', '<2', '1', '2', 'custom-public-id');
        self::assertSame('custom-public-id', $finding->getPublicId());
        self::assertSame('CVE-2026-0001', $finding->getCve());
        self::assertSame(200, $finding->getLevel());
        self::assertSame('Description', $finding->getDescription());
        self::assertSame('Upgrade', $finding->getRemediation());
        self::assertSame('<2', $finding->getVersionsAffected());
        self::assertSame('1', $finding->getVersionInstalled());
        self::assertSame('2', $finding->getVersionFixed());
        self::assertSame('native', $finding->getSourceId());
    }

    /**
     * The finding API does not impose grouping or reporting policies on consumers.
     * 
     * @return void
     */
    public function testFindingHasNoAggregationMethods() : void
    {
        self::assertFalse(method_exists(FindingInterface::class, 'merge'));
        self::assertFalse(method_exists(FindingInterface::class, 'getDetections'));
        self::assertFalse(method_exists(FindingInterface::class, 'compareTo'));
        self::assertFalse(method_exists(FindingInterface::class, 'getId'));
        self::assertFalse(method_exists(Finding::class, 'getId'));
    }

    /** Finding types are restricted to the supported public contract. */
    public function testRejectsUnsupportedType() : void
    {
        $this->expectException(InvalidArgumentException::class);
        new Finding('id', 'Advisory', 'unsupported', 'package', 'npm', 'low');
    }

    /** Findings without identifiers retain their evidence and EOL labels. */
    public function testMissingIdentifiersAndEolLabels() : void
    {
        $first = new Finding('', 'Advisory', 'vulnerability', 'first', 'npm', 'unknown');
        $second = new Finding('', 'Advisory', 'vulnerability', 'second', 'npm', 'unknown');
        self::assertFalse($first->is($second));
        self::assertSame('', $first->getSourceId());
        self::assertSame('', $first->getPublicId());
        self::assertSame(300, $first->getLevel());
        $eol = new Finding('EOL:os', 'Unsupported OS', 'EOL', 'os', 'trivy', 'high', '', 'CVE-2026-0001');
        self::assertSame('', $eol->getPublicId());
        self::assertSame('EOL:os', $eol->getSourceId());
    }
}