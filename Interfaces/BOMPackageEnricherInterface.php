<?php
namespace axenox\PackageManager\Interfaces;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;

/**
 * Supplies additional license information for a package before it is stored in a combined BOM.
 * 
 * Allows local license files and remote license sources to participate in the same enrichment workflow.
 */
interface BOMPackageEnricherInterface
{
    public function enrich(BOMPackage $package) : BOMPackage;
}