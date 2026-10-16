<?php
namespace axenox\PackageManager\Common\LicenseBOM\Enricher;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Exceptions\RuntimeException;

/**
 * Recovers missing license declarations from installed package.json files.
 * 
 * Asset repositories can omit npm license metadata from composer.lock. Read the local
 * manifest before discovering license text so the terms are indexed by the actual license.
 * Existing declarations and explicit license selections take precedence.
 */
class FindLicensePackageJsonEnricher implements BOMPackageEnricherInterface
{
    private $vendorPath;

    /**
     * Keeps manifest discovery rooted in the installation being exported.
     * 
     * @param string $vendorPath
     */
    public function __construct(string $vendorPath)
    {
        $this->vendorPath = $vendorPath;
    }

    /**
     * Fills missing declarations without replacing Composer, app or installation choices.
     * 
     * Supports npm's license string, legacy license objects and legacy licenses arrays.
     * SPDX expressions are preserved as declared, not split into independent alternatives.
     * 
     * {@inheritDoc}
     * 
     * @see BOMPackageEnricherInterface::enrich()
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        if ($package->hasLicense()) {
            return $package;
        }
        $data = $package->toComposerArray();
        $path = $this->vendorPath . DIRECTORY_SEPARATOR . $package->getName() . '/package.json';
        if (! is_file($path)) {
            $ecosystem = PackageEcosystemDataType::findEcosystem($package->getName(), $data['ecosystem'] ?? null);
            $path = $this->vendorPath . DIRECTORY_SEPARATOR
                . PackageEcosystemDataType::convertNameToAssetPackagist($package->getName(), $ecosystem) . '/package.json';
        }
        if (! is_file($path)) {
            return $package;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read package license manifest: ' . $path);
        }
        $manifest = JsonDataType::decodeJson($contents);
        if (! is_array($manifest)) {
            throw new RuntimeException('Package license manifest must contain a JSON object: ' . $path);
        }
        $license = $manifest['license'] ?? null;
        $definitions = is_string($license) && trim($license) !== ''
            ? [$license]
            : (is_array($license) && isset($license['type']) ? [$license] : ($manifest['licenses'] ?? []));
        $names = [];
        foreach (is_array($definitions) ? $definitions : [] as $definition) {
            $name = is_array($definition) ? ($definition['type'] ?? null) : $definition;
            if (! is_string($name) || trim($name) === '' || stripos(trim($name), 'SEE LICENSE IN ') === 0) {
                continue;
            }
            $name = trim($name);
            $names[$name] = $name;
            $url = is_array($definition) ? ($definition['url'] ?? null) : null;
            if (is_string($url) && trim($url) !== '' && empty($data['license_link'][$name])) {
                $data['license_link'][$name] = $url;
            }
        }
        if ($names === []) {
            return $package;
        }
        $data['license'] = array_values($names);
        if (($data['license_used'] ?? null) === 'Other') {
            unset($data['license_used']);
        }
        return new BOMPackage($data);
    }
}