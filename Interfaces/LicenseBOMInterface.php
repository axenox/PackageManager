<?php
namespace axenox\PackageManager\Interfaces;

interface LicenseBOMInterface
{
    /**
     * Merge LicenseBOM-Array with array in method-parameter
     * 
     * @param LicenseBOMInterface $mergingBOM
     * @return LicenseBOMInterface
     */
    public function merge(LicenseBOMInterface $mergingBOM) : LicenseBOMInterface;
    
    public function getPackages() : array;
    
    public function hasPackage(string $name) : bool;
    
    public function addPackage(BOMPackageInterface $package) : LicenseBOMInterface;
}