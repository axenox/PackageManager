<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;

/**
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
}