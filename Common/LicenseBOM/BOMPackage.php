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
     *      "MIT": "axenox/ide/Atheos/Docs/LICENSE.md
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
        $otherPackageArray = $otherPackage->toComposerArray();
        if (($otherPackageArray['license'] ?? null) === []) {
            unset($otherPackageArray['license']);
        }
        if (($otherPackageArray['license_used'] ?? null) === null || $otherPackageArray['license_used'] === '') {
            unset($otherPackageArray['license_used']);
        }
        $this->packageArray = array_replace($this->packageArray, $otherPackageArray);
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
        return $this->packageArray['license_link'][$licenseUsed] ?? null;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::getLicenseFile()
     */
    public function getLicenseFile(string $licenseUsed = null) : ?string
    {
        $prop = $this->packageArray['license_file'] ?? null;
        switch (true) {
            case is_array($prop):
                if ($licenseUsed !== null) {
                    $path = $prop[$licenseUsed] ?? null;
                } else {
                    $path = reset($prop);
                }
                break;
            case $prop === null: 
            case is_string($prop):
            default:
                $path = $prop;
                break;
        }
        return $path;
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
     * Resolve the effective license without persisting a default selection in package metadata.
     *
     * @return string|NULL
     */
    public function getLicenseUsed() : ?string
    {
        $licenseUsed = $this->packageArray['license_used'] ?? null;
        return $licenseUsed !== null && $licenseUsed !== '' ? $licenseUsed : ($this->getLicenseNames()[0] ?? 'Other');
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
        } else {
            unset($this->packageArray['license_used']);
        }
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
        if($this->getLicenseNames() !== [] && $this->getLicenseUsed() !== 'Other'){
            return true;
        } else return false;
    }
    
    /**
     * 
     * @return bool
     */
    public function hasLicenseText() : bool
    {
        if(null !== $this->packageArray['license_link'][$this->getLicenseUsed()] || null !== $this->packageArray['license_text'][$this->getLicenseUsed()] && $this->packageArray['license_text'][$this->getLicenseUsed()] !== ""){
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