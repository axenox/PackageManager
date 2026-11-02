<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;

/**
 * Wraps a license BOM so exporters can provide different representations of the same package collection.
 * 
 * Package lookup, merging, and addition are delegated to the wrapped BOM rather than maintaining a
 * separate collection for each output format.
 * 
 * @author Andrej Kabachnik
 *
 */
abstract class AbstractBOMDecorator implements LicenseBOMInterface
{
    private $innerBOM = null;
    
    /**
     * 
     * @param LicenseBOMInterface $innerBOM
     */
    public function __construct(LicenseBOMInterface $innerBOM)
    {
        $this->innerBOM = $innerBOM;
    }
    
    /**
     * 
     * @param string $name
     * @return BOMPackageInterface
     */
    public function getPackage(string $name): BOMPackageInterface
    {
        return $this->innerBOM->getPackage($name);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::hasPackage()
     */
    public function hasPackage(string $name): bool
    {
        return $this->innerBOM->hasPackage($name);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::merge()
     */
    public function merge(LicenseBOMInterface $mergingBOM): LicenseBOMInterface
    {
        return $this->innerBOM->merge($mergingBOM);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::addPackage()
     */
    public function addPackage(BOMPackageInterface $package): LicenseBOMInterface
    {
        return $this->innerBOM->addPackage($package);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \axenox\PackageManager\Interfaces\LicenseBOMInterface::getPackages()
     */
    public function getPackages(): array
    {
        return $this->innerBOM->getPackages();
    }

    /**
     * {@inheritDoc}
     * 
     * @see LicenseBOMInterface::getLicenses()
     */
    public function getLicenses() : array
    {
        return $this->innerBOM->getLicenses();
    }

    /**
     * {@inheritDoc}
     * 
     * @see LicenseBOMInterface::setLicenses()
     */
    public function setLicenses($licenses) : LicenseBOMInterface
    {
        return $this->innerBOM->setLicenses($licenses);
    }

    /**
     * Combines selected component terms for text exporters without substituting another alternative.
     *
     * Partial conjunctions and compact inventories must not be presented as complete terms.
     *
     * @param BOMPackageInterface $package
     * @return string|null
     */
    protected function buildSelectedLicenseText(BOMPackageInterface $package) : ?string
    {
        $licenses = $package->getLicenses();
        $texts = [];
        foreach ($package->getSelectedLicenseNames() as $name) {
            $text = isset($licenses[$name]) ? $licenses[$name]->getText() : null;
            if ($text === null) {
                return null;
            }
            $texts[] = $text;
        }
        return $texts === [] ? null : implode(PHP_EOL . PHP_EOL, $texts);
    }
}