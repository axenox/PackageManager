<?php
namespace axenox\PackageManager\Interfaces;

/**
 * Defines the package collection shared by license metadata sources, combined BOMs, and exporters.
 * 
 * Supports combining package information from multiple sources and accessing packages by name.
 */
interface LicenseBOMInterface
{
    /**
     * Returns reusable license definitions keyed by their installation-specific names.
     * 
     * @return array<string, array<string, string>>
     */
    public function getLicenses() : array;

    /**
     * Registers shared license metadata so packages need only reference its name.
     * 
     * @param array<string, array<string, string>> $licenses
     * @return LicenseBOMInterface
     */
    public function setLicenses(array $licenses) : LicenseBOMInterface;

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