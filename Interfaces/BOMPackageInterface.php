<?php
namespace axenox\PackageManager\Interfaces;

/**
 * Describes one included software package and the license information used in a license BOM.
 * 
 * Provides a shared contract for metadata overrides, effective license selection, license references,
 * and exportable package information across sources, enrichers, and exporters.
 */
interface BOMPackageInterface
{
    /**
     * Returns the current version-specific identity using the native registry name.
     * 
     * @return string
     */
    public function getUrn() : string;

    /**
     * Returns whether this entry declares a nonempty version rather than versionless metadata.
     * 
     * This is the includes declaration convention, not proof of installation. Version-specific
     * overrides also return true, and the result reflects the current merged metadata.
     * 
     * @return bool
     */
    public function isSeparatePackage() : bool;

    /**
     * Returns whether this entry has an omitted, null or empty version and can supplement packages.
     * 
     * This is the complement of isSeparatePackage(). Unmatched versionless entries still remain
     * in the inventory; this classification does not exclude them or change their URNs.
     * 
     * @return bool
     */
    public function isSupplementalInfo() : bool;

    public function getName() : string;
    
    public function getDescription() : ?string;
    
    public function getVersion(): ?string;
    
    public function getSource(): ?string;
    
    public function getLicenseNames(): array;
    
    public function getLicenseLink(string $licenseUsed) : ?string;
    
    public function getLicenseFile(string $licenseUsed = null) : ?string;
    
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
    * Names and ecosystems must identify the same package. Two known versions must match;
    * an omitted version supplies metadata without replacing the installed version.
     * 
     * @param BOMPackageInterface $otherPackage
     * @return BOMPackageInterface
     */
    public function merge(BOMPackageInterface $otherPackage) : BOMPackageInterface;
}