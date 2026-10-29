<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\Exceptions\RuntimeException;

/**
 * Reports newer npm releases from the registry's latest distribution tag.
 * 
 * Accepts saved BOMs and Composer asset locks without Node.js or Composer.
 * Configure this scanner independently with timeout_seconds, concurrency and
 * cache_ttl_seconds; defaults are 30, 6 and 86400 respectively.
 * Use {"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\NpmOutdatedScanner"}.
 */
class NpmOutdatedScanner extends AbstractRegistryOutdatedScanner
{
    /**
     * {@inheritDoc}
     * 
     * @see AbstractRegistryOutdatedScanner::getEcosystem()
     */
    protected function getEcosystem() : string
    {
        return PackageEcosystemDataType::NPM;
    }

    /**
     * Restricts public requests to native npm identities, including scoped names.
     * 
     * {@inheritDoc}
     * 
     * @see AbstractRegistryOutdatedScanner::getMetadataUrl()
     */
    protected function getMetadataUrl(string $name) : string
    {
        if (! preg_match('~^(?:@[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*$~', $name)) {
            throw new RuntimeException('Invalid npm package name: ' . $name);
        }
        return 'https://registry.npmjs.org/' . rawurlencode($name) . '/latest';
    }

    /**
     * Uses npm's recommendation rather than selecting a prerelease or numerically greatest tag.
     * 
     * {@inheritDoc}
     * 
     * @see AbstractRegistryOutdatedScanner::getLatestVersion()
     */
    protected function getLatestVersion(string $name, array $data) : ?string
    {
        if (! is_string($data['version'] ?? null) || $data['version'] === '') {
            throw new RuntimeException('npm latest response has no comparable version for ' . $name . '.');
        }
        return $this->normalizeVersion($data['version']) === null ? null : $data['version'];
    }
}