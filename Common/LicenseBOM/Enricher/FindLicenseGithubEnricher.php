<?php
namespace axenox\PackageManager\Common\LicenseBOM\Enricher;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

/**
 * Downloads missing terms independently from each license's raw GitHub link.
 * 
 * @author Thomas Ressel
 *
 */
class FindLicenseGithubEnricher implements BOMPackageEnricherInterface
{
    private $vendorPath = null;
    
    /**
     * Preserves the shared enricher construction contract used by BOM generation.
     *
     * @param string $vendorPath
     */
    public function __construct($vendorPath)
    {
        $this->vendorPath = $vendorPath;
    }
    
    /**
     * Updates each downloaded license object without changing the package's selected license.
     *
     * {@inheritDoc}
     *
     * @see BOMPackageEnricherInterface::enrich()
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        foreach ($package->getLicenses() as $license) {
            $link = $license->getLink();
            if ($link !== null && str_contains($link, 'raw.githubusercontent.com') && $license->getText() === null) {
                $content = file_get_contents($link);
                if ($content !== false) {
                    $license->setText($content, 'github');
                }
            }
        }
        return $package;
    }
}