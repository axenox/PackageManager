<?php
namespace axenox\PackageManager\Common\LicenseBOM\Enricher;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\DataTypes\FilePathDataType;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;

/**
 * Searches a package directory for terms of the single selected license.
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
     * Reads terms for one selected license without attributing a generic file to a conjunction.
     *
     * Native npm/Bower identities can be installed under their Asset Packagist aliases.
     * Packages declared only in includes.json need not have a standalone directory.
     *
     * {@inheritDoc}
     *
     * @see BOMPackageEnricherInterface::enrich()
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        $licenseUsed = $package->getLicenseUsed();
        $ecosystem = $package->getEcosystem();
        $packagePath = $this->vendorPath . DIRECTORY_SEPARATOR
            . PackageEcosystemDataType::convertNameToAssetPackagist($package->getName(), $ecosystem);
        if (! is_dir($packagePath)) {
            $packagePath = $this->vendorPath . DIRECTORY_SEPARATOR . $package->getName();
        }
        if (! is_dir($packagePath) || count($package->getSelectedLicenseNames()) > 1) {
            return $package;
        }
        foreach ($package->getLicenses() as $license) {
            if ($license->getName() !== $licenseUsed || ($license->getText() ?? '') !== '') {
                continue;
            }
            $text = $this->findLicenseText($packagePath);
            if ($text !== null) {
                $license->setText($text, 'local');
            }
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