<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use Symfony\Component\Cache\Adapter\NullAdapter;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

class FindLicenseGithubEnricher implements BOMPackageEnricherInterface
{
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
        // if license_link is set
            foreach($package->getLicenseNames() as $licenseName){
                $link = $package->getLicenseLink($licenseName);
                // if license_link contains 'raw.githubusercontent.com' and license_text for licenseName is empty
                if(str_contains($link, "raw.githubusercontent.com") && null === $package->getLicenseText($licenseName))
                {
                    $content = file_get_contents($link);
                    if($content !== false){
                        $package->setLicenseText($licenseName, $content);
                    }
                }
            }
        return $package;
    }
}