<?php
namespace axenox\PackageManager\Tests\PHPUnit\Support;

use exface\Core\CommonLogic\Workbench;
use PHPUnit\Framework\TestCase;

/** Shares real Workbench construction and temporary fixtures without requiring a database. */
abstract class AuditTestCase extends TestCase
{
    protected $workbench;
    private $temporaryFolders = [];

    /** {@inheritDoc} @see TestCase::setUp() */
    protected function setUp() : void
    {
        $this->workbench = new Workbench();
    }

    /** {@inheritDoc} @see TestCase::tearDown() */
    protected function tearDown() : void
    {
        foreach ($this->temporaryFolders as $folder) {
            foreach (glob($folder . '/*') as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
        parent::tearDown();
    }

    /** Keeps parsing checks independent of action authorization and persistent metamodels. */
    protected function invokeProtected($instance, string $name, array $arguments = [])
    {
        $method = new \ReflectionMethod($instance, $name);
        $method->setAccessible(true);
        return $method->invokeArgs($instance, $arguments);
    }

    /** Registers cleanup before assertions can interrupt a filesystem scenario. */
    protected function temporaryFolder() : string
    {
        $folder = sys_get_temp_dir() . '/exf-audit-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($folder, 0700));
        $this->temporaryFolders[] = $folder;
        return $folder;
    }

    /** Reports fixture write failures at their source rather than as scanner failures. */
    protected function writeFixture(string $folder, string $name, string $contents) : void
    {
        self::assertNotFalse(file_put_contents($folder . '/' . $name, $contents));
    }

    /** Supplies npm evidence independently of HTTP tests so test execution order does not matter. */
    protected function npmResponse() : array
    {
        return ['@scope/package' => [[
            'id' => 123, 'title' => 'Fixture vulnerability', 'severity' => 'moderate',
            'cves' => ['CVE-2026-0001', 'CVE-2026-0002'], 'vulnerable_versions' => '<2',
            'patched_versions' => '>=2', 'recommendation' => 'Upgrade to version 2'
        ]]];
    }

    /** Gives aggregation tests linked npm evidence without requiring a transport call. */
    protected function linkedNpmResponse() : array
    {
        return ['@scope/package' => [[
            'id' => 123, 'url' => 'https://github.com/advisories/GHSA-jpcq-cgw6-v4j6',
            'title' => 'Fixture', 'severity' => 'high'
        ]]];
    }

    /** Supplies overlapping CVE and end-of-life evidence for scanner and aggregation checks. */
    protected function trivyResponse() : array
    {
        return [
            'SchemaVersion' => 2,
            'Results' => [['Type' => 'node-pkg', 'Vulnerabilities' => [[
                'PkgName' => '@scope/package', 'VulnerabilityID' => 'CVE-2026-0001',
                'Severity' => 'CRITICAL', 'InstalledVersion' => '1.0.0', 'FixedVersion' => '2.0.0'
            ]]]],
            'Metadata' => ['OS' => ['Family' => 'alpine', 'Name' => '3.10', 'EOSL' => true]]
        ];
    }
}