<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use Composer\Semver\VersionParser;
use exface\Core\Exceptions\RuntimeException;

/**
 * Reports newer stable Composer releases using Packagist metadata, not Git history.
 * 
 * Accepts saved BOMs and locks without a project composer.json or Composer executable.
 * Configure this scanner independently with timeout_seconds, concurrency and
 * cache_ttl_seconds; defaults are 30, 6 and 86400 respectively.
 * Use {"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\PackagistOutdatedScanner"}.
 */
class PackagistOutdatedScanner extends AbstractRegistryOutdatedScanner
{
    /**
     * {@inheritDoc}
     * 
     * @see AbstractRegistryOutdatedScanner::getEcosystem()
     */
    protected function getEcosystem() : string
    {
        return PackageEcosystemDataType::COMPOSER;
    }

    /**
     * Restricts metadata requests to a Composer vendor/package identity.
     * 
     * {@inheritDoc}
     * 
     * @see AbstractRegistryOutdatedScanner::getMetadataUrl()
     */
    protected function getMetadataUrl(string $name) : string
    {
        if (! preg_match('~^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$~', $name)) {
            throw new RuntimeException('Invalid Packagist package name: ' . $name);
        }
        return 'https://repo.packagist.org/p2/' . $name . '.json';
    }

    /**
     * Reads version fields directly because Composer's minified records inherit unrelated fields.
     * 
     * Stable candidates are compared using Composer normalization, not response order.
     * 
     * {@inheritDoc}
     * 
     * @see AbstractRegistryOutdatedScanner::getLatestVersion()
     */
    protected function getLatestVersion(string $name, array $data) : ?string
    {
        if (! is_array($data['packages'][$name] ?? null)) {
            throw new RuntimeException('Packagist response has no version list for ' . $name . '.');
        }
        $latest = null;
        foreach ($data['packages'][$name] as $package) {
            if (! is_array($package) || ! is_string($package['version'] ?? null)) {
                throw new RuntimeException('Packagist response has invalid version metadata for ' . $name . '.');
            }
            $version = $package['version'];
            $normalized = $this->normalizeVersion($version);
            if ($normalized !== null && VersionParser::parseStability($version) === 'stable'
                && ($latest === null || version_compare($normalized, $this->normalizeVersion($latest), '>'))) {
                $latest = $version;
            }
        }
        return $latest;
    }
}