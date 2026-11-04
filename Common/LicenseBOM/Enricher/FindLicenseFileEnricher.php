<?php
namespace axenox\PackageManager\Common\LicenseBOM\Enricher;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

/**
 * Reads each license's configured file into that same license object.
 * 
 * @author Thomas Ressel
 *
 */
class FindLicenseFileEnricher implements BOMPackageEnricherInterface
{
    private $filePath = null;
    
    private $vendorPath = null;
    
    /**
     * Resolves per-license paths against the installation being enriched.
     *
     * @param string $vendorPath
     */
    public function __construct($vendorPath)
    {
        $this->vendorPath = $vendorPath;
    }
    
    /**
     * Fills missing terms independently without assigning one license's file to another license.
     *
     * {@inheritDoc}
     *
     * @see BOMPackageEnricherInterface::enrich()
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        foreach ($package->getLicenses() as $license) {
            $path = $license->getFile();
            if ($path !== null && ($license->getText() ?? '') === '') {
                $text = $this->getLicenseFileContent($path);
                if ($text !== null) {
                    $license->setText($text, 'local');
                }
            }
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