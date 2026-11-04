<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * Define a license inventory containing packages and reusable license definitions.
 *
 * Packages use typed license objects. Shared licenses provide installation-wide terms
 * for packages selecting the same name without repeating their configuration.
 *
 * ## Examples
 *
 * ### Share installation-specific terms
 *
 * Packages select the shared definition by name. Omit a package version when supplementing
 * an installed package rather than declaring a bundled copy.
 *
 * ```
 *
 * {
 *     "packages": [
 *         {"name": "example/client-app", "ecosystem": "private", "license_used": "Commercial"}
 *     ],
 *     "licenses": [
 *         {"name": "Commercial", "text": "The complete license terms agreed for this installation."}
 *     ]
 * }
 *
 * ```
 *
 *
 * @author andrej.kabachnik
 *
 */
class LicenseBOM implements LicenseBOMInterface, iCanBeConvertedToUxon
{
    use iCanBeConvertedToUxonTrait;
    
    private $packageArray = [];
    
    private $enricher = [];

    private $licenses = [];

    /**
     * Loads archived result evidence without interpreting it as designer configuration.
     *
     * @param array<string,mixed>|null $bom
     */
    public function __construct(?array $bom = null)
    {
        $data = $bom ?? [];
        $this->importUxonObject(new UxonObject());
        foreach ($data['licenses'] ?? [] as $key => $definition) {
            $license = new BOMPackageLicense(self::normalizeLicenseDefinition($definition, $key));
            $this->setLicenses([$license]);
        }
        foreach ($data['packages'] ?? [] as $package) {
            $this->addPackage(new BOMPackage($package));
        }
    }

    /**
     * Define installed packages or versionless metadata supplements.
     *
     * @uxon-property packages
     * @uxon-type \axenox\PackageManager\Common\LicenseBOM\BOMPackage[]
     * @uxon-template [{"name":"","licenses":[{"name":"MIT"}]}]
     * @param UxonObject $packages
     * @return void
     */
    protected function setPackages(UxonObject $packages) : void
    {
        foreach ($packages as $package) {
            $configuredPackage = new BOMPackage();
            $configuredPackage->importUxonObject($package);
            $this->addPackage($configuredPackage);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @see LicenseBOMInterface::getLicenses()
     */
    public function getLicenses() : array
    {
        return $this->licenses;
    }

    /**
     * Define reusable license objects referenced by package license names.
     *
     * Legacy name-keyed definitions are accepted when reading existing configuration.
     *
     * @uxon-property licenses
     * @uxon-type \axenox\PackageManager\Common\LicenseBOM\BOMPackageLicense[]
     * @uxon-template [{"name":"Commercial","text":""}]
     *
     * {@inheritDoc}
     *
     * @see LicenseBOMInterface::setLicenses()
     */
    public function setLicenses($licenses) : LicenseBOMInterface
    {
        $licenses = $licenses instanceof UxonObject ? $licenses->toArray() : $licenses;
        foreach ($licenses as $key => $definition) {
            if ($definition instanceof BOMPackageLicense) {
                $license = new BOMPackageLicense($definition->toBOM());
            } else {
                $license = new BOMPackageLicense();
                $license->importUxonObject(new UxonObject(self::normalizeLicenseDefinition($definition, $key)));
            }
            $name = $license->getName();
            if (isset($this->licenses[$name])) {
                $this->licenses[$name]->merge($license);
            } else {
                $this->licenses[$name] = $license;
            }
        }
        foreach ($this->packageArray as $package) {
            $this->addPackage($package);
        }
        return $this;
    }

    /**
     * Keeps legacy shared definitions readable at both configuration and archived BOM boundaries.
     *
     * @param array<string,mixed> $definition
     * @param string|int $key
     * @return array<string,mixed>
     */
    protected static function normalizeLicenseDefinition(array $definition, $key) : array
    {
        $definition['name'] = $definition['name'] ?? $key;
        foreach (['license_text' => 'text', 'license_link' => 'link', 'license_file' => 'file'] as $old => $new) {
            if (isset($definition[$old]) && ! array_key_exists($new, $definition)) {
                $definition[$new] = $definition[$old];
            }
            unset($definition[$old]);
        }
        return $definition;
    }

    /**
     * Merge LicenseBOM with otherBOM in method-argument
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::merge()
     */
    public function merge(LicenseBOMInterface $mergingBOM) : LicenseBOMInterface
    {
        $this->setLicenses($mergingBOM->getLicenses());
        foreach ($mergingBOM->getPackages() as $package) {
            $key = $package->getUrn();
            if (isset($this->packageArray[$key])) {
                $package = $this->packageArray[$key]->merge($package);
            } else {
                $identity = PackageEcosystemDataType::buildPackageKey($package->getName(), $package->getEcosystem());
                $matched = false;
                foreach ($this->packageArray as $existingKey => $existing) {
                    if ($identity !== PackageEcosystemDataType::buildPackageKey($existing->getName(), $existing->getEcosystem())) {
                        continue;
                    }
                    if (($package->getVersion() ?? '') !== '' && ($existing->getVersion() ?? '') !== ''
                        && $package->getVersion() !== $existing->getVersion()) {
                        continue;
                    }
                    $merged = $existing->merge($package);
                    if ($existingKey !== $merged->getUrn()) {
                        unset($this->packageArray[$existingKey]);
                    }
                    $this->addPackage($merged);
                    $matched = true;
                }
                if ($matched) {
                    continue;
                }
            }
            // run enrichers on package and add package to packageArray
            $this->addPackage($package);
        }
        return $this;
    }
    
    /**
     * 
     * @param BOMPackageEnricherInterface $enricher
     * @return LicenseBOMInterface
     */
    public function addEnricher(BOMPackageEnricherInterface $enricher) : LicenseBOMInterface
    {
        $this->enricher[] = $enricher;
        return $this;
    }
    
    /**
     * 
     * @return array
     */
    protected function getEnricher() : array
    {
        return $this->enricher;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::hasPackage()
     */
    public function hasPackage(string $name) : bool
    {
        if (array_key_exists($name, $this->packageArray)) {
            return true;
        }
        foreach ($this->packageArray as $package) {
            if ($package->getName() === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::addPackage()
     */
    public function addPackage(BOMPackageInterface $package) : LicenseBOMInterface
    {
        foreach ($package->getLicenses() as $license) {
            $name = $license->getName();
            if (isset($this->licenses[$name])) {
                $package->addLicense($this->licenses[$name]);
            }
        }
        foreach($this->getEnricher() as $enricher) {
            $package = $enricher->enrich($package);
        }
        $this->packageArray[$package->getUrn()] = $package;
        return $this;
    }

    /**
     * Captures current inventory state without changing the imported UXON configuration.
     *
     * @return array<string,mixed>
     */
    public function toBOM() : array
    {
        $data = ['packages' => array_values(array_map(static function (BOMPackageInterface $package) : array {
            return $package->toBOM();
        }, $this->packageArray))];
        if ($this->licenses !== []) {
            $data['licenses'] = array_values(array_map(static function (BOMPackageLicense $license) : array {
                return $license->toBOM();
            }, $this->licenses));
        }
        return $data;
    }

    /**
     * Get all packages
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::getPackages()
     */
    public function getPackages(): array
    {
        return $this->packageArray;
    }  
}