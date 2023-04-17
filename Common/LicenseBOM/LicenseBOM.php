<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

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
            if ($this->hasPackage($package->getName())) {
                $this->packageArray[$package->getName()]->merge($package);
            } 
                // set first licenseName in package as $licenseUsed
                $package->setLicenseUsed($package->getLicenseNames()[0]);
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
        return array_key_exists($name, $this->packageArray);
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
        $this->packageArray[$package->getName()] = $package;
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