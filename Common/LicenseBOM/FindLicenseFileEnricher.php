<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use Symfony\Component\Cache\Adapter\NullAdapter;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

class FindLicenseFileEnricher implements BOMPackageEnricherInterface
{
    private $filePath = null;
    
    public function __construct($filePath)
    {
        $this->filePath = $filePath;
    }
    
    /**
     * 
     * @param BOMPackage $package
     * @return BOMPackage
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        $licenseUsed = $package->getLicenseUsed();

        if(null == $package->getLicenseText($licenseUsed) && null !== $package->getLicenseFile($licenseUsed)){
            $package->setLicenseText($licenseUsed, $this->getLicenseFileContent($this->filePath));
        }
        return $package;
    }
    
    /**
     * Find license-text if package-attribute license_file is set
     * @param string $packagePath
     * @return string|NULL
     */
    public function getLicenseFileContent(string $filePath) : ?string
    {
        if(file_exists($filePath))
        {
            return file_get_contents($filePath);
        } else return null;
    } 
}