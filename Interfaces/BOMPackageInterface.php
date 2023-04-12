<?php
namespace axenox\PackageManager\Interfaces;

interface BOMPackageInterface
{
    public function getName() : string;
    
    public function getDescription() : ?string;
    
    public function getVersion(): ?string;
    
    public function getSource(): ?string;
    
    public function getLicenseNames(): array;
    
    public function getLicenseLink(string $licenseUsed) : ?string;
    
    public function getLicenseFile() : ?string;
    
    public function getLicenseText(string $licenseUsed) : ?string;
    
    public function getLicenseUsed() : ?string;
    
    public function setLicenseUsed(?string $name) : BOMPackageInterface;
    
    public function setLicenseText(string $licenseName, string $text) : BOMPackageInterface;

    public function hasLicense() : bool;
    
    public function hasLicenseText() : bool;
    
    public function toComposerArray() : array;
    
    /**
     * Updates values of this package by corresponding data of another package.
     * 
     * This is only allowed for packages with the same name
     * 
     * @param BOMPackageInterface $otherPackage
     * @return BOMPackageInterface
     */
    public function merge(BOMPackageInterface $otherPackage) : BOMPackageInterface;
}