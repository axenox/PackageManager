<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use axenox\PackageManager\Interfaces\AuditScannerInterface;
use axenox\PackageManager\Interfaces\FindingInterface;
use Composer\Semver\VersionParser;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Tasks\TaskInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

/**
 * Shares inventory handling and bounded HTTP lookups between registry-specific scanners.
 * 
 * Concrete scanners select one ecosystem and own its endpoint and release policy.
 * Combined JSON, CycloneDX, SPDX and Composer locks need no installed project.
 * Private packages and development versions are excluded before requests without coverage hints.
 * Options timeout_seconds, concurrency and cache_ttl_seconds apply independently per scanner.
 */
abstract class AbstractRegistryOutdatedScanner extends AbstractAuditScanner
{
    private int $timeoutSeconds = 30;
    private int $concurrency = 6;
    private int $cacheTtlSeconds = 86400;

    /**
     * Limit each registry request so a slow service cannot block a background audit indefinitely.
     * 
     * @uxon-property timeout_seconds
     * @uxon-type integer
     * @uxon-default 30
     * 
     * @param int $seconds
     * @return $this
     */
    public function setTimeoutSeconds(int $seconds) : self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Registry timeout_seconds must be greater than zero.');
        }
        $this->timeoutSeconds = $seconds;
        return $this;
    }

    /**
     * Bound simultaneous requests to avoid overloading the registry or the audit worker.
     * 
     * @uxon-property concurrency
     * @uxon-type integer
     * @uxon-default 6
     * 
     * @param int $requests
     * @return $this
     */
    public function setConcurrency(int $requests) : self
    {
        if ($requests < 1 || $requests > 32) {
            throw new InvalidArgumentException('Registry concurrency must be between 1 and 32.');
        }
        $this->concurrency = $requests;
        return $this;
    }

    /**
     * Reuse release metadata across builds; set zero to query fresh metadata on every scan.
     * 
     * @uxon-property cache_ttl_seconds
     * @uxon-type integer
     * @uxon-default 86400
     * 
     * @param int $seconds
     * @return $this
     */
    public function setCacheTtlSeconds(int $seconds) : self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Registry cache_ttl_seconds must not be negative.');
        }
        $this->cacheTtlSeconds = $seconds;
        return $this;
    }

    /**
     * Chooses the registry identity without treating other ecosystems as coverage failures.
     * 
     * @return string
     */
    abstract protected function getEcosystem() : string;

    /**
     * Builds a public registry endpoint from a validated native package name.
     * 
     * @param string $name
     * @return string
     */
    abstract protected function getMetadataUrl(string $name) : string;

    /**
     * Selects the registry's recommended release without assuming API response ordering.
     * 
     * @param string $name
     * @param array<string, mixed> $data
     * @return string|null Null means no suitable published release is available.
     */
    abstract protected function getLatestVersion(string $name, array $data) : ?string;

    /**
     * Reads only supplied artifacts, or the folder's inventory and lock when none were supplied.
     * 
     * @param TaskInterface $task
     * @return array<int, array<string, mixed>>
     */
    protected function getInventories(TaskInterface $task) : array
    {
        $inventories = [];
        foreach ([Audit::TASK_PARAM_SBOM, Audit::TASK_PARAM_COMPOSER_LOCK] as $parameter) {
            if ($task->hasParameter($parameter)) {
                $inventories[] = $this->decode($task->getParameter($parameter));
            }
        }
        if ($inventories !== []) {
            return $inventories;
        }
        $folder = $this->getTargetFolder($task);
        foreach (['vendor/licenses.json', 'vendor/SBOM.cdx.json', 'sbom.cdx.json', 'data/sbom.cdx.json'] as $path) {
            if (is_file($folder . '/' . $path)) {
                $inventories[] = $this->readJson($folder . '/' . $path);
                break;
            }
        }
        if (is_file($folder . '/composer.lock')) {
            $inventories[] = $this->readJson($folder . '/composer.lock');
        }
        return $inventories;
    }

    /**
     * Extracts flat and nested package evidence without making HTTP requests during supports().
     * 
     * @param array<string, mixed> $inventory
     * @return array<int, array<string, mixed>>
     */
    protected function getPackages(array $inventory) : array
    {
        if (($inventory['bomFormat'] ?? '') === 'CycloneDX') {
            $packages = $inventory['components'] ?? [];
            if (isset($inventory['metadata']['component'])) {
                $packages[] = $inventory['metadata']['component'];
            }
        } else {
            $packages = $inventory['packages'] ?? null;
            if (! is_array($packages) || ! is_array($inventory['packages-dev'] ?? [])) {
                throw new RuntimeException('Registry scanner inventory must contain valid package arrays.');
            }
            $packages = array_merge($packages, $inventory['packages-dev'] ?? []);
        }
        if (! is_array($packages)) {
            throw new RuntimeException('Registry scanner components must be an array.');
        }
        $result = [];
        foreach ($packages as $package) {
            if (! is_array($package)) {
                throw new RuntimeException('Registry scanner packages must be objects.');
            }
            if (isset($inventory['spdxVersion'])) {
                $package['version'] = $package['versionInfo'] ?? '';
                foreach ($package['externalRefs'] ?? [] as $reference) {
                    if (($reference['referenceType'] ?? '') === 'purl') {
                        $package['purl'] = $reference['referenceLocator'] ?? '';
                        break;
                    }
                }
            }
            $package['_typed_inventory'] = isset($inventory['bomFormat']) || isset($inventory['spdxVersion']);
            $result[] = $package;
            if (isset($package['components'])) {
                $result = array_merge($result, $this->getPackages(['bomFormat' => 'CycloneDX', 'components' => $package['components']]));
            }
        }
        return $result;
    }

    /**
     * Canonicalizes released versions while rejecting branches, constraints and commit references.
      * 
      * Composer cannot order every npm prerelease label. Unsupported syntax remains a
      * visible coverage gap instead of aborting otherwise comparable package checks.
     * 
     * @param string $version
     * @return string|null
     */
    protected function normalizeVersion(string $version) : ?string
    {
        if (! preg_match('/^v?\d+(?:\.\d+){0,3}(?:-?[a-z][a-z0-9.-]*)?(?:\+[0-9A-Za-z.-]+)?$/i', $version)) {
            return null;
        }
        try {
            $normalized = (new VersionParser())->normalize($version);
        } catch (\UnexpectedValueException $error) {
            return null;
        }
        return VersionParser::parseStability($normalized) === 'dev' ? null : $normalized;
    }

    /**
     * Resolves registry identities and exclusions before querying any public service.
     * 
     * @param TaskInterface $task
     * @return array<string, array<string, string|null>> Native names mapped to original versions and normalized values.
     */
    protected function getDependencies(TaskInterface $task) : array
    {
        $this->hints = [];
        $inventories = $this->getInventories($task);
        $private = array_change_key_case($this->getPrivatePackageNames($inventories), CASE_LOWER);
        $identities = [];
        foreach ($inventories as $inventory) {
            foreach ($this->getPackages($inventory) as $package) {
                if (! is_string($package['name'] ?? null) || $package['name'] === '' || ! is_string($package['version'] ?? '')) {
                    throw new RuntimeException('Registry scanner packages require a name and a string version when present.');
                }
                $name = $package['name'];
                $ecosystem = $package['ecosystem'] ?? ($package['_typed_inventory'] ? PackageEcosystemDataType::UNKNOWN : PackageEcosystemDataType::COMPOSER);
                foreach ($package['properties'] ?? [] as $property) {
                    if (($property['name'] ?? '') === 'axenox:packagemanager:ecosystem') {
                        $ecosystem = $property['value'];
                    }
                }
                if (! is_string($ecosystem) || ! is_string($package['group'] ?? '')) {
                    throw new RuntimeException('Registry package ecosystem and group must be strings when present.');
                }
                $isPrivate = strcasecmp(trim($ecosystem), PackageEcosystemDataType::PRIVATE) === 0 || isset($private[strtolower($name)]);
                if (isset($package['purl'])) {
                    if (! is_string($package['purl']) || ! preg_match('~^pkg:([^/]+)/([^?#]+)(?:\?[^#]*)?(?:#.*)?$~', $package['purl'], $matches)) {
                        throw new RuntimeException('Invalid registry package URL for ' . $name . '.');
                    }
                    $ecosystem = PackageEcosystemDataType::convertFromPurlType($matches[1]) ?? PackageEcosystemDataType::UNKNOWN;
                    $separator = strrpos($matches[2], '@');
                    $hasVersion = $separator !== false && $separator > (strrpos($matches[2], '/') ?: 0);
                    $name = rawurldecode($hasVersion ? substr($matches[2], 0, $separator) : $matches[2]);
                    if (empty($package['version']) && $hasVersion) {
                        $package['version'] = rawurldecode(substr($matches[2], $separator + 1));
                    }
                } elseif ($package['_typed_inventory'] && ! empty($package['group'])) {
                    $name = $package['group'] . '/' . $name;
                }
                $ecosystem = PackageEcosystemDataType::findEcosystem($name, $ecosystem);
                $name = strtolower(PackageEcosystemDataType::convertNameFromAssetPackagist($name, $ecosystem));
                if ($isPrivate) {
                    $private[$name] = true;
                }
                $identities[] = ['name' => $name, 'ecosystem' => $ecosystem, 'version' => $package['version'] ?? ''];
            }
        }
        $dependencies = [];
        foreach ($identities as $identity) {
            $name = $identity['name'];
            if ($identity['ecosystem'] === $this->getEcosystem() && ! isset($private[$name])
                && VersionParser::parseStability(strtolower($identity['version'])) !== 'dev') {
                $this->getMetadataUrl($name);
                $dependencies[$name][$identity['version']] = $this->normalizeVersion($identity['version']);
            }
        }
        return $dependencies;
    }

    /**
     * Keeps HTTP transport replaceable for fixture tests without replacing Workbench.
     * 
     * @return Client
     */
    protected function createHttpClient() : Client
    {
        return new Client();
    }

    /**
     * Keeps reads and writes in the same cache namespace, independent of the installed version.
     * 
     * @param string $name
     * @return string
     */
    private function getCacheKey(string $name) : string
    {
        return 'registry-outdated-v1-' . hash('sha256', $this->getMetadataUrl($name));
    }

    /**
     * {@inheritDoc}
     * 
     * @see AuditScannerInterface::supports()
     */
    public function supports(TaskInterface $task) : bool
    {
        return $this->getDependencies($task) !== [];
    }

    /**
     * Queries each native identity once and caches release metadata across build audits.
     * 
     * HTTP failures propagate. Missing public packages and non-comparable versions
     * produce coverage hints rather than a false assertion that they are up to date.
     * 
     * {@inheritDoc}
     * 
     * @see AuditScannerInterface::audit()
     * @return FindingInterface[]
     */
    public function audit(TaskInterface $task) : array
    {
        $dependencies = $this->getDependencies($task);
        $latest = [];
        $client = $this->createHttpClient();
        $cache = $this->cacheTtlSeconds > 0 ? $this->workbench->getCache() : null;
        $requests = function () use ($dependencies, $client, $cache, &$latest) {
            foreach ($dependencies as $name => $versions) {
                if (array_filter($versions, static fn ($version) => $version !== null) === []) {
                    continue;
                }
                $cached = $cache === null ? null : $cache->get($this->getCacheKey($name));
                if (is_array($cached) && array_key_exists('version', $cached)
                    && ($cached['fetched_at'] ?? 0) > time() - $this->cacheTtlSeconds) {
                    $latest[$name] = $cached['version'];
                    continue;
                }
                yield $name => function () use ($client, $name) {
                    return $client->getAsync($this->getMetadataUrl($name), [
                        'timeout' => $this->timeoutSeconds, 'connect_timeout' => min(10, $this->timeoutSeconds),
                        'http_errors' => false, 'allow_redirects' => false, 'headers' => ['Accept' => 'application/json']
                    ]);
                };
            }
        };
        $pool = new Pool($client, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function (ResponseInterface $response, string $name) use (&$latest, $cache) {
                $status = $response->getStatusCode();
                if ($status !== 200 && $status !== 404) {
                    throw new RuntimeException($this->getEcosystem() . ' registry request failed for ' . $name . ' (HTTP ' . $status . ').');
                }
                $latest[$name] = $status === 404 ? null : $this->getLatestVersion($name, $this->decode((string) $response->getBody()));
                if ($cache !== null) {
                    $cache->set($this->getCacheKey($name), ['version' => $latest[$name], 'fetched_at' => time()], $this->cacheTtlSeconds);
                }
            },
            'rejected' => static function (\Throwable $error, string $name) {
                throw new RuntimeException('Registry request failed for ' . $name . ': ' . $error->getMessage(), null, $error);
            }
        ]);
        $pool->promise()->wait();
        $findings = [];
        foreach ($dependencies as $name => $versions) {
            foreach ($versions as $installed => $normalized) {
                if ($normalized === null) {
                    $this->hints[] = $this->getEcosystem() . ': cannot compare a released version for ' . $name . ' (' . $installed . ').';
                } elseif (($latest[$name] ?? null) === null) {
                    $this->hints[] = $this->getEcosystem() . ': no suitable public release metadata for ' . $name . '.';
                } elseif (version_compare($this->normalizeVersion($latest[$name]), $normalized, '>')) {
                    $isMajor = (int) explode('.', $this->normalizeVersion($latest[$name]))[0]
                        > (int) explode('.', $normalized)[0];
                    $title = ($isMajor ? 'New major version ' : 'New version ') . $latest[$name]
                        . ' available - currently on ' . $installed;
                    $findings[] = new Finding(
                        'outdated:' . $name, $title, AdvisoryTypeDataType::OUTDATED,
                        PackageEcosystemDataType::convertNameToAssetPackagist($name, $this->getEcosystem()),
                        $this->getEcosystem() === PackageEcosystemDataType::COMPOSER ? 'packagist' : 'npm', 'optional',
                        detailsUrl: $this->getMetadataUrl($name),
                        description: 'The public registry reports ' . $latest[$name] . '; the inventory contains ' . $installed
                            . '. Upgrade compatibility, dependency constraints and fork differences were not evaluated.',
                        remediation: 'Review upstream changes and project constraints before upgrading.',
                        versionInstalled: (string) $installed, versionFixed: $latest[$name], ecosystem: $this->getEcosystem()
                    );
                }
            }
        }
        return $findings;
    }

    /**
     * Needs no executable or installation because all requests use public registry metadata.
     * 
     * {@inheritDoc}
     * 
     * @see AuditScannerInterface::install()
     */
    public function install(TaskInterface $task) : void
    {
    }
}