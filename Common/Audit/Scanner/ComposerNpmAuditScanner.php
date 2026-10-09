<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\Actions\Audit;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Exceptions\RuntimeException;
use GuzzleHttp\Client;
use axenox\PackageManager\Interfaces\FindingInterface;
use axenox\PackageManager\Common\Audit\Finding;

/**
 * Audits locked npm-asset dependencies through npm's advisory API.
 * 
 * Folder scans and archived Composer locks use the same read-only HTTP workflow.
 * Auditing does not install packages or plugins and does not require Composer
 * or Node.js executables.
 * 
 * Select this prototype in a named AUDIT.SCANNERS or action scanners entry:
 * `{"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\ComposerNpmAuditScanner"}`.
 * This scanner currently has no additional UXON options.
 */
class ComposerNpmAuditScanner extends AbstractAuditScanner
{
    /**
     * Returns decoded Composer locks for folder or archived-build audits.
     * 
     * @param TaskInterface $task
     * @return array<int, array<array-key, mixed>>
     */
    protected function locks(TaskInterface $task) : array
    {
        if ($task->hasParameter(Audit::TASK_PARAM_COMPOSER_LOCK)) {
            return [$this->decode($task->getParameter(Audit::TASK_PARAM_COMPOSER_LOCK))];
        }
        $folder = $this->getTargetFolder($task);
        if ($folder !== null) {
            return is_file($folder . '/composer.lock') ? [$this->readJson($folder . '/composer.lock')] : [];
        }
        return [];
    }

    /**
     * Extracts released npm dependencies from a Composer lock.
     * 
     * Composer's scoped asset notation is converted to npm registry package names.
     * Development dependencies are included, but unreleased versions are rejected.
     * 
     * @param array<string, mixed> $lock
     * @return array<string, string[]>
     */
    protected function dependencies(array $lock) : array
    {
        if (! isset($lock['packages']) || ! is_array($lock['packages'])) {
            throw new RuntimeException('COMPOSER_LOCK must contain a packages array.');
        }
        $dependencies = [];
        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            $name = $package['name'] ?? '';
            if (strpos($name, 'npm-asset/') !== 0) {
                continue;
            }
            $name = substr($name, strlen('npm-asset/'));
            if (strpos($name, '--') !== false) {
                $name = '@' . str_replace('--', '/', $name);
            }
            $version = ltrim((string) ($package['version'] ?? ''), 'v');
            if ($version === '' || strpos($version, 'dev-') === 0) {
                throw new RuntimeException('Cannot audit npm package without a released version: ' . $name);
            }
            $dependencies[$name][] = $version;
        }
        return $dependencies;
    }

    /**
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::supports()
     * @param TaskInterface $task
     * @return bool
     */
    public function supports(TaskInterface $task) : bool
    {
        foreach ($this->locks($task) as $lock) {
            if ($this->dependencies($lock) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::audit()
     * @param TaskInterface $task
     * @return FindingInterface[]
     */
    public function audit(TaskInterface $task) : array
    {
        $findings = [];
        $client = $this->createHttpClient();
        foreach ($this->locks($task) as $lock) {
            $dependencies = $this->dependencies($lock);
            if ($dependencies === []) {
                continue;
            }
            $response = $client->post('https://registry.npmjs.org/-/npm/v1/security/advisories/bulk', ['json' => $dependencies]);
            $findings = array_merge($findings, $this->normalize($this->decode((string) $response->getBody())));
        }
        return $findings;
    }

    /**
     * Creates the advisory HTTP client.
     * 
     * Subclasses can replace the transport for tests without contacting the public registry.
     * 
     * @return Client
     */
    protected function createHttpClient() : Client
    {
        return new Client(['timeout' => 120]);
    }

    /**
     * Converts npm advisory responses into typed findings.
     * 
     * Registry IDs, patched ranges and upgrade recommendations are retained.
     * Supplied CVEs produce separate findings for each public advisory identity.
     * 
     * @param array<string, array<int, array<string, mixed>>> $data
     * @return FindingInterface[]
     */
    protected function normalize(array $data) : array
    {
        $findings = [];
        foreach ($data as $package => $advisories) {
            $name = strpos($package, '@') === 0 ? str_replace('/', '--', substr($package, 1)) : $package;
            foreach ($advisories as $advisory) {
                $cves = $advisory['cves'] ?? [];
                foreach ($cves !== [] ? $cves : [''] as $cve) {
                    $findings[] = $this->createFinding('npm-asset/' . $name, $advisory, $cve);
                }
            }
        }
        return $findings;
    }

    /**
     * Creates a finding directly from a native npm registry advisory.
     * 
     * A supplied CVE selects one identity from an advisory's CVE list without
     * injecting fields into the response. Native IDs keep their npm prefix.
     * 
     * @param string $package The Composer npm-asset package name.
     * @param array{id?: int|string, title?: string, severity?: string, url?: string, overview?: string, recommendation?: string, vulnerable_versions?: string, patched_versions?: string, cves?: string[]} $advisory
     * @param string $cve
     * @return FindingInterface
     */
    protected function createFinding(string $package, array $advisory, string $cve = '') : FindingInterface
    {
        return new Finding(
            $cve !== '' ? $cve : (isset($advisory['id']) ? 'npm:' . $advisory['id'] : ''),
            (string) ($advisory['title'] ?? 'Known vulnerability'),
            FindingInterface::TYPE_VULNERABILITY,
            $package,
            'npm',
            (string) ($advisory['severity'] ?? 'unknown'),
            (string) ($advisory['url'] ?? ''),
            $cve,
            (string) ($advisory['overview'] ?? ''),
            (string) ($advisory['recommendation'] ?? ''),
            (string) ($advisory['vulnerable_versions'] ?? ''),
            '',
            (string) ($advisory['patched_versions'] ?? '')
        );
    }

    /**
     * Leaves the target project's Composer setup unchanged.
     * 
     * HTTP audits require no scanner installation, so this method is a no-op.
     * 
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::install()
     * @param TaskInterface $task
     * @return void
     */
    public function install(TaskInterface $task) : void
    {
    }
}