<?php
namespace axenox\PackageManager\Interfaces;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;

interface BOMPackageEnricherInterface
{
    public function enrich(BOMPackage $package) : BOMPackage;
}