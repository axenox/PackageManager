<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use exface\Core\Exceptions\RuntimeException;

/**
 * A single package inside a BOM
 * 
 * @author Thomas Ressel
 *
 */
class BOMPackage implements BOMPackageInterface
{
    /**
     * {
     *  "name": "",
     *  "license_used": "MIT",
     *  "license": [
     *      "MIT",
     *      "proprietary"
     *  ],
     *  "license_link": [
     *      "https://..."
     *  ],
     *  "license_text": {
     *      "MIT": "..."
     *  },
     *  "license_file": {
     *      "MIT": "c:\wamp\www\exface\exface\vendor\axenox\ide\Atheos\Docs\LICENSE.md
     *  }
     * }
     * 
     * @var array $packageArray
     */
    private $packageArray = null;
    
    public function __construct(array $composerLockPackageArray)
    {
        $this->packageArray = $composerLockPackageArray;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::merge()
     */
    public function merge(BOMPackageInterface $otherPackage): BOMPackageInterface
    {
        if (strcasecmp($this->getName(), $otherPackage->getName()) !== 0) {
            throw new RuntimeException('Cannot merge license BOM packages with different names: ' . $this->getName() . ' and ' . $otherPackage->getName());
        }
        $this->packageArray = array_replace($this->packageArray, $otherPackage->toComposerArray());
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::getName()
     */
    public function getName(): string
    {
        return $this->packageArray['name'];
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getDescription() : ?string
    {
        return $this->packageArray['description'] ?? null;
    }
    
    /**
     *
     * @return string|NULL
     */
    public function getVersion(): ?string
    {
        return $this->packageArray['version'] ?? null;
    }
    
    /**
     *
     * @return string|NULL
     */
    public function getSource(): ?string
    {
        return $this->packageArray['source']['url'];
    }

    /**
     *
     * @return string|NULL
     */
    public function getLicenseLink(string $licenseUsed) : ?string
    {
        if(! array_key_exists('license_link', $this->packageArray)) {
            return null;
        }
        if(is_array($this->packageArray['license_link'])) {
            return $this->packageArray['license_link'][$licenseUsed];
        } else {
            return $this->packageArray['license_link'];
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::getLicenseFile()
     */
    public function getLicenseFile() : ?string
    {
        return $this->packageArray['license_file'] ?? null;
    }
    
    /**
     * 
     * @param string $licenseNameUsed
     * @return array|NULL
     */
    public function getLicenseText(string $licenseUsed) : ?string
    {
        return $this->packageArray['license_text'][$licenseUsed] ?? null;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::getLicenseNames()
     */
    public function getLicenseNames(): array
    {
        return $this->packageArray['license'] ?? [];
    }
    
    /**
     *
     * @return string|NULL
     */
    public function getLicenseUsed() : ?string
    {
        return $this->packageArray['license_used'] ?? null;
    }
    
    /**
     *
     * @param string $name
     * @return BOMPackageInterface
     */
    public function setLicenseUsed(?string $name) : BOMPackageInterface
    {
        if($name !== null && $name !== ""){
            $this->packageArray['license_used'] = $name;
        } else $this->packageArray['license_used'] = "Other";
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::setLicenseText()
     */
    public function setLicenseText(string $licenseName, string $text) : BOMPackageInterface
    {
        $this->packageArray['license_text'][$licenseName] = $text;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::hasLicense()
     */
    public function hasLicense() : bool
    {
        if(array_key_exists('license', $this->packageArray) && $this->packageArray['license_used'] !== 'Other'){
            return true;
        } else return false;
    }
    
    /**
     *
     * @return bool
     */
    public function hasLicenseText() : bool
    {
        if(null !== $this->packageArray['license_text'][$this->getLicenseUsed()] && $this->packageArray['license_text'][$this->getLicenseUsed()] !== ""){
            return true;
        } else return false;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::toComposerArray()
     */
    public function toComposerArray(): array
    {
        return $this->packageArray;
    }
}