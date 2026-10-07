<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Audit\Finding;
use axenox\PackageManager\Audit\MergedFinding;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Covers aggregation strategies and access to immutable scanner evidence.
 * 
 * These scenarios do not require Workbench, scanners or database access.
 */
class MergedFindingTest extends TestCase
{
    /**
     * Aggregates must satisfy the finding contract without losing the originals.
     * 
     * @return void
     */
    public function testAggregatesEvidenceWithoutChangingOriginals() : void
    {
        $first = new Finding('npm:1', 'First title', 'vulnerability', 'package', 'npm', 'moderate',
            'https://example.org/first', 'CVE-2026-0001', 'Description', 'Upgrade', '<2', '1', '2');
        $second = new Finding('trivy:2', 'Second title', 'vulnerability', 'package', 'trivy', 'critical',
            'https://example.org/second', 'CVE-2026-0001', '', 'Rebuild', '<3', '1', '3');
        $merged = new MergedFinding([$first, $second, clone $first]);
        self::assertInstanceOf(FindingInterface::class, $merged);
        self::assertSame([$first, $second], $merged->getMergedFindings());
        self::assertFalse(method_exists(MergedFinding::class, 'getId'));
        self::assertSame('npm:1; trivy:2', $merged->getSourceId());
        self::assertSame('First title', $merged->getName());
        self::assertSame('vulnerability', $merged->getType());
        self::assertSame('package', $merged->getPackage());
        self::assertSame('critical', $merged->getLevel());
        self::assertSame('moderate; critical', $merged->getSourceLevel());
        self::assertSame('npm; trivy', $merged->getSource());
        self::assertSame('https://example.org/first; https://example.org/second', $merged->getDetailsUrl());
        self::assertSame('CVE-2026-0001', $merged->getCve());
        self::assertSame('CVE-2026-0001', $merged->getPublicId());
        self::assertSame('Description', $merged->getDescription());
        self::assertSame('Upgrade; Rebuild', $merged->getRemediation());
        self::assertSame('<2; <3', $merged->getVersionsAffected());
        self::assertSame('1', $merged->getVersionInstalled());
        self::assertSame('2; 3', $merged->getVersionFixed());
        self::assertSame('medium', $first->getLevel());
        self::assertSame('2', $first->getVersionFixed());
        $originals = $merged->getMergedFindings();
        array_pop($originals);
        self::assertCount(2, $merged->getMergedFindings());
    }

    /**
    * Nested groups expose only original findings without requiring an identity.
     * 
     * @return void
     */
    public function testFlattensNestedGroupsWithoutIdentity() : void
    {
        $first = new Finding('1', 'Advisory', 'vulnerability', 'package', 'npm', 'low');
        $second = new Finding('2', 'Advisory', 'vulnerability', 'package', 'trivy', 'high');
        $nested = new MergedFinding([$first]);
        $merged = new MergedFinding([$nested, $second, $nested]);
        $reverse = new MergedFinding([$second, $first]);
        self::assertSame([$first, $second], $merged->getMergedFindings());
        self::assertSame([$second, $first], $reverse->getMergedFindings());
        self::assertSame('high', $merged->getLevel());
        self::assertSame('high', $reverse->getLevel());
        self::assertSame('', $merged->getVersionFixed());
    }

    /**
     * Evidence equality must remain symmetric for single-member aggregates.
     * 
     * @return void
     */
    public function testComparesScalarEvidence() : void
    {
        $first = new Finding('1', 'Advisory', 'vulnerability', 'package', 'npm', 'low');
        $different = new Finding('1', 'Advisory', 'vulnerability', 'package', 'npm', 'high');
        $merged = new MergedFinding([$first]);
        self::assertTrue($merged->is($first));
        self::assertTrue($first->is($merged));
        self::assertTrue($merged->is(new MergedFinding([$first])));
        self::assertFalse($merged->is($different));
    }

    /**
     * An empty group cannot provide the finding getters.
     * 
     * @return void
     */
    public function testRejectsEmptyGroups() : void
    {
        $this->expectException(InvalidArgumentException::class);
        new MergedFinding([]);
    }

    /**
     * Group members must implement the finding interface.
     * 
     * @return void
     */
    public function testRejectsInvalidMembers() : void
    {
        $this->expectException(InvalidArgumentException::class);
        new MergedFinding([new \stdClass()]);
    }

    /**
     * Aggregation must not turn the package getter into a combined package list.
     * 
     * @return void
     */
    public function testRejectsDifferentPackages() : void
    {
        $this->expectException(InvalidArgumentException::class);
        new MergedFinding([
            new Finding('1', 'Advisory', 'vulnerability', 'first', 'npm', 'low'),
            new Finding('1', 'Advisory', 'vulnerability', 'second', 'npm', 'low')
        ]);
    }

    /**
     * Vulnerabilities and lifecycle advisories must remain separate finding types.
     * 
     * @return void
     */
    public function testRejectsDifferentTypes() : void
    {
        $this->expectException(InvalidArgumentException::class);
        new MergedFinding([
            new Finding('1', 'Advisory', 'vulnerability', 'package', 'npm', 'low'),
            new Finding('1', 'Advisory', 'EOL', 'package', 'npm', 'low')
        ]);
    }
}