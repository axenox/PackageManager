<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
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
    
    /**
     * Builds the same version-specific identity for inventories and audit findings.
     * 
     * Native registry names prevent Composer asset aliases from creating duplicate identities.
     * Unknown versions retain an empty final segment rather than inventing a version.
     * 
     * @param string $name
     * @param string|null $version
     * @param string|null $ecosystem
     * @return string
     */
    public static function buildUrn(string $name, ?string $version = null, ?string $ecosystem = null) : string
    {
        $ecosystem = PackageEcosystemDataType::findEcosystem($name, $ecosystem);
        $name = PackageEcosystemDataType::convertNameFromAssetPackagist($name, $ecosystem);
        return $ecosystem . ':' . $name . ':' . ($version ?? '');
    }

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
        $otherPackageArray = $otherPackage->toComposerArray();
        $key = PackageEcosystemDataType::buildPackageKey($this->getName(), $this->packageArray['ecosystem'] ?? null);
        $otherKey = PackageEcosystemDataType::buildPackageKey($otherPackage->getName(), $otherPackageArray['ecosystem'] ?? null);
        if (strcasecmp($key, $otherKey) !== 0) {
            throw new RuntimeException('Cannot merge license BOM packages with different identities: ' . $key . ' and ' . $otherKey);
        }
        if ($this->getVersion() !== null && $this->getVersion() !== ''
            && $otherPackage->getVersion() !== null && $otherPackage->getVersion() !== ''
            && $this->getVersion() !== $otherPackage->getVersion()) {
            throw new RuntimeException('Cannot merge license BOM packages with different versions.');
        }
        if (($otherPackageArray['version'] ?? '') === '') {
            unset($otherPackageArray['version']);
        }
        $otherPackageArray['ecosystem'] = PackageEcosystemDataType::findEcosystem($otherPackage->getName(), $otherPackageArray['ecosystem'] ?? null);
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
     * {@inheritDoc}
     * 
     * @see BOMPackageInterface::getUrn()
     */
    public function getUrn() : string
    {
        return self::buildUrn($this->getName(), $this->getVersion(), $this->packageArray['ecosystem'] ?? null);
    }

    /**
     * {@inheritDoc}
     * 
     * @see BOMPackageInterface::isSeparatePackage()
     */
    public function isSeparatePackage() : bool
    {
        return ($this->getVersion() ?? '') !== '';
    }

    /**
     * {@inheritDoc}
     * 
     * @see BOMPackageInterface::isSupplementalInfo()
     */
    public function isSupplementalInfo() : bool
    {
        return ! $this->isSeparatePackage();
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
     * Returns an optional repository URL for packages supplied without source metadata.
     * 
     * @return string|null
     */
    public function getSource(): ?string
    {
        return $this->packageArray['source']['url'] ?? null;
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
     * Resolves concrete license evidence before falling back to archived source metadata.
     * 
     * Nonempty embedded terms take precedence over local file references and links.
     * A file reference records availability in the source inventory, not a filesystem check
     * on the machine reading an archived build. Blank inline text overrides archived evidence.
     * 
     * {@inheritDoc}
     * 
     * @see BOMPackageInterface::getLicenseTextSource()
     */
    public function getLicenseTextSource(string $licenseUsed) : string
    {
        $text = $this->getLicenseText($licenseUsed);
        if (trim($text ?? '') !== '') {
            return self::LICENSE_TEXT_SOURCE_EMBEDDED;
        }
        if (trim($this->getLicenseFile($licenseUsed) ?? '') !== '') {
            return self::LICENSE_TEXT_SOURCE_LOCAL;
        }
        if (trim($this->getLicenseLink($licenseUsed) ?? '') !== '') {
            return self::LICENSE_TEXT_SOURCE_LINK;
        }
        return $text === null
            ? ($this->packageArray['license_text_source'][$licenseUsed] ?? self::LICENSE_TEXT_SOURCE_MISSING)
            : self::LICENSE_TEXT_SOURCE_MISSING;
    }
    
    /**
     * Returns declared licenses, or the explicit selection when no nonempty list is available.
     * 
     * The fallback is not persisted in merge metadata, so selection-only overrides cannot erase
     * alternatives declared by another source.
     * 
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::getLicenseNames()
     * @return string[]
     */
    public function getLicenseNames(): array
    {
        $licenses = $this->packageArray['license'] ?? [];
        $licenseUsed = $this->packageArray['license_used'] ?? null;
        return $licenses !== [] ? $licenses : ($licenseUsed !== null && $licenseUsed !== '' ? [$licenseUsed] : []);
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
     * Accepts either a license reference or inline text without requiring both metadata maps.
     * 
     * @return bool
     */
    public function hasLicenseText() : bool
    {
        $license = $this->getLicenseUsed();
        return $this->getLicenseLink($license) !== null || ($this->getLicenseText($license) ?? '') !== '';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\BOMPackageInterface::toComposerArray()
     */
    public function toComposerArray(): array
    {
        return array_replace($this->packageArray, [
            'urn' => $this->getUrn()
        ]);
    }
}