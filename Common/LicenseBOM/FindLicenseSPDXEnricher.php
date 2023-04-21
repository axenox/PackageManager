<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

/**
 * Searches for a license text for each of the licenses of a package in the SPDX database
 * 
 * @author Thomas Ressel
 *
 */
class FindLicenseSPDXEnricher implements BOMPackageEnricherInterface
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
        // set license_text for each licenseName with SPDX-text from github.com/spdx/license-list-data/tree/main/text
        foreach($package->getLicenseNames() as $licenseName){
            // if license_text and license_link for licensename is empty
            if(null === $package->getLicenseText($licenseName) && null === $package->getLicenseLink($licenseName))
            {
                $url = "https://raw.githubusercontent.com/spdx/license-list-data/main/text/" . $licenseName . ".txt";
                $content = file_get_contents($url);
                if($content === false){
                    $url = "https://raw.githubusercontent.com/spdx/license-list-data/main/text/deprecated_" . $licenseName . ".txt";
                    $content = file_get_contents($url);
                }
                if($content !== false){
                    $package->setLicenseText($licenseName, $content);
                }
            }
        }
        return $package;
    }
}