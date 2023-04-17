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
    
    private $vendorPath = null;
    
    public function __construct($vendorPath)
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

        if(null !== $package->getLicenseFile() && $this->getLicenseFileContent($package->getLicenseFile()) !== null){
            $package->setLicenseText($licenseUsed, $this->getLicenseFileContent($package->getLicenseFile()));
        }
        return $package;
    }

    /**
     * Find license-text if package-attribute license_file is set
     * @param string $licenseFilePath
     * @return string|NULL
     */
    protected function getLicenseFileContent(string $licenseFilePath) : ?string
    {
        $path = $this->vendorPath . DIRECTORY_SEPARATOR . $licenseFilePath;
        if(file_exists($path))
        {
            return file_get_contents($path);
        } else return null;
    } 
}