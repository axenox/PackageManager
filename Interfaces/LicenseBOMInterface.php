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