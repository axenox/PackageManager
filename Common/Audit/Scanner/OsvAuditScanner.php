<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Tasks\TaskInterface;
use GuzzleHttp\Client;

/**
 * Checks Composer locks and combined inventories against Google's OSV advisory API.
 * 
 * Supply composer_lock, sbom, or both. SBOM input accepts the combined package JSON,
 * CycloneDX and SPDX JSON. Explicit artifacts never fall back to the installation.
 * No Composer, Node.js or scanner executable is needed. Unqueryable components
 * produce low-level unscannable findings and coverage hints, not a clean bill of health.
 * 
 * Select this prototype in a named AUDIT.SCANNERS or action scanners entry:
 * `{"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\OsvAuditScanner"}`.
 * This scanner has no additional UXON options.
 */
class OsvAuditScanner extends AbstractAuditScanner
{
    /**
     * @var FindingInterface[]
     */
    private array $unscannableFindings = [];

    /**
     * Collects all supplied artifacts or the selected folder's saved inventory.
     * 
     * The combined JSON is preferred over alternative SBOM exports from the same build.
     * 
     * @param TaskInterface $task
     * @return array<int, array{data: array, composer: bool}>
     */
    protected function artifacts(TaskInterface $task) : array
    {
        $artifacts = [];
        foreach ([Audit::TASK_PARAM_COMPOSER_LOCK, Audit::TASK_PARAM_SBOM] as $parameter) {
            if ($task->hasParameter($parameter)) {
            $artifacts[] = ['data' => $this->decode($task->getParameter($parameter)), 'composer' => $parameter === Audit::TASK_PARAM_COMPOSER_LOCK];
            }
        }
        if ($artifacts !== []) {
            return $artifacts;
        }
        $folder = $this->getTargetFolder($task);
        if (is_file($folder . '/composer.lock')) {
            $artifacts[] = ['data' => $this->readJson($folder . '/composer.lock'), 'composer' => true];
        }
        foreach (['vendor/licenses.json', 'vendor/SBOM.cdx.json', 'sbom.cdx.json', 'data/sbom.cdx.json'] as $path) {
            if (is_file($folder . '/' . $path)) {
                $artifacts[] = ['data' => $this->readJson($folder . '/' . $path), 'composer' => false];
                break;
            }
        }
        return $artifacts;
    }

    /**
     * Builds unique OSV queries while retaining the original reporting package.
     * 
     * @param TaskInterface $task
     * @return array<int, array{query: array, name: string, version: string}>
     */
    protected function dependencies(TaskInterface $task) : array
    {
        $this->hints = [];
        $this->unscannableFindings = [];
        $dependencies = [];
        foreach ($this->artifacts($task) as $artifact) {
            $data = $artifact['data'];
            if ($artifact['composer'] || isset($data['packages']) && ! isset($data['spdxVersion'])) {
                if (! isset($data['packages']) || ! is_array($data['packages'])
                    || isset($data['packages-dev']) && ! is_array($data['packages-dev'])) {
                    throw new RuntimeException('OSV package inventory must contain a packages array.');
                }
                foreach (array_merge($data['packages'], $data['packages-dev'] ?? []) as $package) {
                    $this->addDependency($dependencies, $package, $artifact['composer']);
                }
            } elseif (($data['bomFormat'] ?? '') === 'CycloneDX') {
                if (isset($data['components']) && ! is_array($data['components'])) {
                    throw new RuntimeException('OSV CycloneDX input must contain a components array.');
                }
                $this->addComponents($dependencies, $data['components'] ?? []);
                if (isset($data['metadata']['component'])) {
                    $this->addComponents($dependencies, [$data['metadata']['component']]);
                }
            } elseif (isset($data['spdxVersion']) && isset($data['packages']) && is_array($data['packages'])) {
                foreach ($data['packages'] as $package) {
                    if (! is_array($package)) {
                        throw new RuntimeException('OSV inventory packages must be objects.');
                    }
                    $package['version'] = $package['versionInfo'] ?? '';
                    foreach ($package['externalRefs'] ?? [] as $reference) {
                        if (($reference['referenceType'] ?? '') === 'purl') {
                            $package['purl'] = $reference['referenceLocator'];
                            break;
                        }
                    }
                    $this->addDependency($dependencies, $package);
                }
            } else {
                throw new RuntimeException('OSV SBOM must be combined package JSON, CycloneDX or SPDX JSON.');
            }
        }
        return array_values($dependencies);
    }

    /**
     * Visits nested CycloneDX components without discarding bundled libraries.
     * 
     * @param array<string, array> $dependencies
     * @param array<int, array> $components
     * @return void
     */
    protected function addComponents(array &$dependencies, array $components) : void
    {
        foreach ($components as $component) {
            $this->addDependency($dependencies, $component);
            if (isset($component['components'])) {
                if (! is_array($component['components'])) {
                    throw new RuntimeException('OSV CycloneDX components must be arrays.');
                }
                $this->addComponents($dependencies, $component['components']);
            }
        }
    }

    /**
     * Resolves registry identity and version, or records a coverage gap.
     * 
     * PURLs take precedence over inferred names. Untyped bundled PHP names are
     * not assumed to be Packagist packages. Development revisions use Git commits.
     * 
     * @param array<string, array> $dependencies
     * @param mixed $package
     * @param bool $composer
     * @return void
     */
    protected function addDependency(array &$dependencies, $package, bool $composer = false) : void
    {
        if (! is_array($package) || ! isset($package['name']) || ! is_string($package['name']) || $package['name'] === '') {
            throw new RuntimeException('OSV inventory packages must contain a nonempty name.');
        }
        $name = $package['name'];
        if (isset($package['version']) && ! is_string($package['version'])) {
            throw new RuntimeException('OSV inventory versions must be strings: ' . $name . '.');
        }
        $version = (string) ($package['version'] ?? '');
        $identity = null;
        if (! empty($package['purl'])) {
            if (! is_string($package['purl']) || ! preg_match('~^pkg:([^/]+)/([^?#]+)(?:\?[^#]*)?(?:#.*)?$~', $package['purl'], $matches)) {
                throw new RuntimeException('Invalid OSV package URL for ' . $name . '.');
            }
            $versionPosition = strrpos($matches[2], '@');
            $nameEnd = strrpos($matches[2], '/');
            $parts = $versionPosition !== false && $versionPosition > ($nameEnd !== false ? $nameEnd : 0)
                ? [substr($matches[2], 0, $versionPosition), substr($matches[2], $versionPosition + 1)]
                : [$matches[2]];
            $purlName = rawurldecode($parts[0]);
            if (isset($parts[1])) {
                $version = rawurldecode($parts[1]);
            }
            if ($matches[1] === 'npm' || $matches[1] === 'composer') {
                $identity = ['ecosystem' => $matches[1] === 'npm' ? 'npm' : 'Packagist', 'name' => $purlName];
                $name = $matches[1] === 'npm' ? 'npm-asset/' . str_replace('/', '--', ltrim($purlName, '@')) : $purlName;
            } elseif (! in_array($matches[1], ['generic', 'bower'], true)) {
                $identity = ['purl' => 'pkg:' . $matches[1] . '/' . $parts[0]
                    . substr($matches[0], strcspn($matches[0], '?#'))];
            }
        } elseif (strpos($name, 'npm-asset/') === 0) {
            $npmName = substr($name, 10);
            $identity = ['ecosystem' => 'npm', 'name' => strpos($npmName, '--') !== false ? '@' . str_replace('--', '/', $npmName) : $npmName];
        } elseif (isset($package['ecosystem'])) {
            $identity = ['ecosystem' => (string) $package['ecosystem'], 'name' => $name];
        } elseif (strpos($name, 'bower-asset/') !== 0 && ($composer || ! empty($package['type']))) {
            $identity = ['ecosystem' => 'Packagist', 'name' => $name];
        }
        if ($identity !== null && $version !== '' && ! preg_match('/(^dev-|[-.]dev$)/i', $version)) {
            $version = preg_replace('/^v(?=\d)/', '', $version);
            $query = ['package' => $identity, 'version' => $version];
        } elseif (preg_match('/^[a-f0-9]{40}$/i', (string) ($package['source']['reference'] ?? ''))
            && ($package['source']['type'] ?? '') === 'git') {
            $query = ['commit' => $package['source']['reference']];
        } else {
            $hint = 'OsvAuditScanner: cannot query ' . $name . ' (' . $version . '): supply a supported package identity and exact version or Git commit.';
            $this->hints[] = $hint;
            $this->unscannableFindings[] = new Finding(
                '', 'Package cannot be scanned', AdvisoryTypeDataType::UNSCANNABLE, $name, 'osv', 'low',
                '', '', $hint, 'Supply a supported package identity and exact version or Git commit.', '', $version
            );
            return;
        }
        $key = json_encode([$query, $name], JSON_THROW_ON_ERROR);
        $dependencies[$key] = ['query' => $query, 'name' => $name, 'version' => $version];
    }

    /**
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::supports()
     */
    public function supports(TaskInterface $task) : bool
    {
        return $this->dependencies($task) !== [] || $this->hints !== [];
    }

    /**
     * Queries both inventories, follows pagination and fetches full advisory records.
     * 
     * Duplicate queries and advisory downloads are avoided within one scan.
     * Withdrawn records are not reported. Transport failures remain scan failures.
     * 
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::audit()
     * @return FindingInterface[]
     */
    public function audit(TaskInterface $task) : array
    {
        $dependencies = $this->dependencies($task);
        $findings = $this->unscannableFindings;
        $records = [];
        $detections = [];
        $client = $this->createHttpClient();
        foreach (array_chunk($dependencies, 100) as $chunk) {
            $pending = $chunk;
            $tokens = [];
            while ($pending !== []) {
                $response = $client->post('https://api.osv.dev/v1/querybatch', ['json' => ['queries' => array_column($pending, 'query')]]);
                $data = $this->decode((string) $response->getBody());
                if (! isset($data['results']) || ! is_array($data['results']) || count($data['results']) !== count($pending)
                    || array_keys($data['results']) !== array_keys($pending)) {
                    throw new RuntimeException('Invalid OSV batch response: results must match the submitted queries.');
                }
                $next = [];
                foreach ($data['results'] as $index => $result) {
                    if (! is_array($result) || isset($result['vulns']) && ! is_array($result['vulns'])) {
                        throw new RuntimeException('Invalid OSV batch result.');
                    }
                    $dependency = $pending[$index];
                    foreach ($result['vulns'] ?? [] as $vulnerability) {
                        $id = $vulnerability['id'] ?? null;
                        if (! is_string($id) || $id === '') {
                            throw new RuntimeException('Invalid OSV advisory identifier.');
                        }
                        if (! isset($records[$id])) {
                            $record = $client->get('https://api.osv.dev/v1/vulns/' . rawurlencode($id));
                            $records[$id] = $this->decode((string) $record->getBody());
                            if (($records[$id]['id'] ?? null) !== $id) {
                                throw new RuntimeException('OSV advisory response does not match ' . $id . '.');
                            }
                        }
                        $key = json_encode([$id, $dependency['name'], $dependency['version']], JSON_THROW_ON_ERROR);
                        if (! isset($detections[$key]) && empty($records[$id]['withdrawn'])) {
                            $findings = array_merge($findings, $this->normalize($records[$id], $dependency));
                            $detections[$key] = true;
                        }
                    }
                    if (! empty($result['next_page_token'])) {
                        $token = $result['next_page_token'];
                        $key = json_encode([$dependency['name'], $dependency['version'], $token], JSON_THROW_ON_ERROR);
                        if (! is_string($token) || isset($tokens[$key])) {
                            throw new RuntimeException('Invalid or repeated OSV pagination token.');
                        }
                        $tokens[$key] = true;
                        $dependency['query']['page_token'] = $token;
                        $next[] = $dependency;
                    }
                }
                $pending = $next;
            }
        }
        return $findings;
    }

    /**
     * Creates the HTTP transport; subclasses can supply a mocked registry.
     * 
     * @return Client
     */
    protected function createHttpClient() : Client
    {
        return new Client(['timeout' => 120]);
    }

    /**
     * Converts a full native OSV record to findings for the queried package only.
     * 
     * CVE aliases remain separate public identities. Affected range events are
     * retained as JSON to preserve disjoint ranges and Git versus release semantics.
     * 
     * @param array<string, mixed> $advisory
     * @param array{query: array, name: string, version: string} $dependency
     * @return FindingInterface[]
     */
    protected function normalize(array $advisory, array $dependency) : array
    {
        foreach (['affected', 'aliases'] as $field) {
            if (isset($advisory[$field]) && ! is_array($advisory[$field])) {
                throw new RuntimeException('Invalid OSV advisory ' . $field . ' array.');
            }
        }
        $ranges = [];
        $versions = [];
        $fixed = [];
        $severity = $advisory['database_specific']['severity'] ?? 'unknown';
        foreach ($advisory['affected'] ?? [] as $affected) {
            $identity = $dependency['query']['package'] ?? null;
            $package = $affected['package'] ?? [];
            if ($identity !== null && (isset($identity['purl'])
                ? preg_replace('/@[^?#]+/', '', $package['purl'] ?? '') !== $identity['purl']
                : ($package['name'] ?? '') !== $identity['name'] || ($package['ecosystem'] ?? '') !== $identity['ecosystem'])) {
                continue;
            }
            $severity = $affected['ecosystem_specific']['severity'] ?? $severity;
            $versions = array_merge($versions, $affected['versions'] ?? []);
            foreach ($affected['ranges'] ?? [] as $range) {
                if ($identity === null && ($range['type'] ?? '') !== 'GIT') {
                    continue;
                }
                $ranges[] = $range;
                foreach ($range['events'] ?? [] as $event) {
                    if (isset($event['fixed'])) {
                        $fixed[] = $event['fixed'];
                    }
                }
            }
        }
        $cves = array_values(array_filter($advisory['aliases'] ?? [], static function ($alias) {
            return is_string($alias) && VulnerabilityLevelDataType::isCVE($alias);
        }));
        $evidence = [];
        if ($ranges !== []) {
            $evidence['ranges'] = $ranges;
        }
        if ($versions !== []) {
            $evidence['versions'] = array_values(array_unique($versions));
        }
        $findings = [];
        foreach (array_unique($cves !== [] ? $cves : ['']) as $cve) {
            $findings[] = new Finding(
                $advisory['id'],
                (string) ($advisory['summary'] ?? $advisory['id']),
                AdvisoryTypeDataType::VULNERABILITY,
                $dependency['name'],
                'osv',
                (string) $severity,
                'https://osv.dev/vulnerability/' . rawurlencode($advisory['id']),
                $cve,
                (string) ($advisory['details'] ?? ''),
                '',
                $evidence !== [] ? json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '',
                $dependency['version'],
                implode('; ', array_unique($fixed))
            );
        }
        return $findings;
    }

    /**
     * Requires no local executable or changes to the target installation.
     * 
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::install()
     */
    public function install(TaskInterface $task) : void
    {
    }
}