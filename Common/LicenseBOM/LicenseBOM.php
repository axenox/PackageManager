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

    /**
     * Merge LicenseBOM with otherBOM in method-argument
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::merge()
     */
    public function merge(LicenseBOMInterface $mergingBOM) : LicenseBOMInterface
    {
        foreach ($mergingBOM->getPackages() as $package) {
            $data = $package->toComposerArray();
            $key = PackageEcosystemDataType::buildPackageKey($package->getName(), $data['ecosystem'] ?? null);
            if (isset($this->packageArray[$key])) {
                $package = $this->packageArray[$key]->merge($package);
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
        foreach($this->getEnricher() as $enricher) {
            $package = $enricher->enrich($package);
        }
        $data = $package->toComposerArray();
        $key = PackageEcosystemDataType::buildPackageKey($package->getName(), $data['ecosystem'] ?? null);
        $this->packageArray[$key] = $package;
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