<?php
namespace axenox\PackageManager\Common\LicenseBOM\Enricher;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
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
    * Reads installed terms without querying absent bundled packages or unrelated directories.
    * 
    * Native npm/Bower identities can be installed under their Asset Packagist aliases.
    * Packages declared only in includes.json need not have a standalone directory.
    * 
     * @param BOMPackage $package
     * @return BOMPackage
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        $licenseUsed = $package->getLicenseUsed();
        $data = $package->toComposerArray();
        $ecosystem = PackageEcosystemDataType::findEcosystem($package->getName(), $data['ecosystem'] ?? null);
        $packagePath = $this->vendorPath . DIRECTORY_SEPARATOR
            . PackageEcosystemDataType::convertNameToAssetPackagist($package->getName(), $ecosystem);
        if (! is_dir($packagePath)) {
            $packagePath = $this->vendorPath . DIRECTORY_SEPARATOR . $package->getName();
        }
        if (! is_dir($packagePath) || ($package->getLicenseText($licenseUsed) ?? '') !== '') {
            return $package;
        }
        $text = $this->findLicenseText($packagePath);
        if ($text !== null) {
            $package->setLicenseText($licenseUsed, $text);
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