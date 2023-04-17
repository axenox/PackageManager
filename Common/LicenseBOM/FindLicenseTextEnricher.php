<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use exface\Core\DataTypes\FilePathDataType;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

/**
 * Searches for a license file within the directory of a package.
 * 
 * Examples:
 * 
 * - LICENSE.md
 * - LICENCE.md
 * - LICENSE
 * - MIT_LICENSE.md
 * - ...
 * 
 * @author Thomas Ressel
 *
 */
class FindLicenseTextEnricher implements BOMPackageEnricherInterface
{
    private $vendorPath = null;
    
    /**
     * 
     * @param string $vendorPath
     */
    public function __construct(string $vendorPath)
    {
        $this->vendorPath = $vendorPath;
    }
    
    /**
     * 
     * @param BOMPackage $package
     * @return BOMPackage
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        $licenseUsed = $package->getLicenseUsed();
        $packagePath = $this->vendorPath . DIRECTORY_SEPARATOR . $package->getName();

        // if license-text for licenseUsed is empty and license-text in packagePath is found
        if(null == $package->getLicenseText($licenseUsed) && null !== $this->findLicenseText($packagePath)) {
            $package->setLicenseText($licenseUsed, $this->findLicenseText($packagePath));
        }
        return $package;
    }
    
    /**
     * 
     * @param string $packagePath
     * @return string|NULL
     */
    public function findLicenseText(string $packagePath) : ?string
    {
        foreach (scandir($packagePath) as $file) {
            // skip current and parent directory
            if ($file === '.' || $file === '..') {
                continue;
            }
            // if file-name is 'license'
            if (strcasecmp(FilePathDataType::findFileName($file, false), 'license') === 0) {
                return file_get_contents($packagePath . DIRECTORY_SEPARATOR . $file);
            }
            // if file-name contains 'license'
            if (preg_match('/LICENSE/i', FilePathDataType::findFileName($file, false), $matches) === 1)
            {
                return file_get_contents($packagePath . DIRECTORY_SEPARATOR . $file);
            }
            // if file-name contains 'licence'
            if (preg_match('/LICENCE/i', FilePathDataType::findFileName($file, false), $matches) === 1)
            {
                return file_get_contents($packagePath . DIRECTORY_SEPARATOR . $file);
            }
        }
        return null;
    }
}