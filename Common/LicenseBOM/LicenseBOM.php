<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;

/**
 * Base class for license bills of materials
 * 
 * Contains packages with metadata similar to the composer package descriptions.
 * 
 * @author andrej.kabachnik
 *
 */
class LicenseBOM implements LicenseBOMInterface
{
    private $filePath = null; 
    
    private $packageArray = [];
    
    private $enricher = [];

    private $licenses = [];

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
     * {@inheritDoc}
     * 
     * @see LicenseBOMInterface::setLicenses()
     */
    public function setLicenses(array $licenses) : LicenseBOMInterface
    {
        $this->licenses = array_replace_recursive($this->licenses, $licenses);
        foreach ($this->packageArray as $package) {
            $this->addPackage($package);
        }
        return $this;
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
            $data = $package->toComposerArray();
            $key = $package->getUrn();
            if (isset($this->packageArray[$key])) {
                $package = $this->packageArray[$key]->merge($package);
            } else {
                $identity = PackageEcosystemDataType::buildPackageKey($package->getName(), $data['ecosystem'] ?? null);
                $matched = false;
                foreach ($this->packageArray as $existingKey => $existing) {
                    $existingData = $existing->toComposerArray();
                    if ($identity !== PackageEcosystemDataType::buildPackageKey($existing->getName(), $existingData['ecosystem'] ?? null)) {
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
        $data = $package->toComposerArray();
        $names = array_unique(array_merge($package->getLicenseNames(), [$package->getLicenseUsed()]));
        foreach ($names as $name) {
            foreach ($this->licenses[$name] ?? [] as $property => $value) {
                if (in_array($property, ['license_link', 'license_text'], true)) {
                    $data[$property][$name] = $value;
                }
            }
        }
        if ($data !== $package->toComposerArray()) {
            $package = new BOMPackage($data);
        }
        foreach($this->getEnricher() as $enricher) {
            $package = $enricher->enrich($package);
        }
        $this->packageArray[$package->getUrn()] = $package;
        return $this;
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