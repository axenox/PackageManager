<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;

class LicenseBOM implements LicenseBOMInterface
{
    private $filePath = null; 
    
    private $packageArray = [];
    
    private $enricher = [];

    /**
     * Merge LicenseBOM with otherBOM in method-argument
     * {@inheritDoc}
     * @see \axenox\IDE\Interfaces\LicenseBOMInterface::merge()
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
     * @param unknown $enricher
     * @return LicenseBOMInterface
     */
    public function addEnricher($enricher) : LicenseBOMInterface
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
     * @see \axenox\IDE\Interfaces\LicenseBOMInterface::hasPackage()
     */
    public function hasPackage(string $name) : bool
    {
        return array_key_exists($name, $this->packageArray);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \axenox\IDE\Interfaces\LicenseBOMInterface::addPackage()
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
     * @see \axenox\IDE\Interfaces\LicenseBOMInterface::getPackages()
     */
    public function getPackages(): array
    {
        return $this->packageArray;
    }  
}