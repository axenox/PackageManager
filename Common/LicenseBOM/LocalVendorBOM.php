<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseGithubEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicensePackageJsonEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseSPDXEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseTextEnricher;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use GuzzleHttp\ClientInterface;

/**
 * Collect a top-level license inventory and explicitly enrich it against a local vendor tree.
 * 
 * Merge Composer, bundled-package and project metadata first, then call enrich() before export.
 * Construction and merging never run this inventory's default enrichers or download terms.
 * Plain LicenseBOM instances remain lightweight sources for merging.
 * 
 * Enrichment recovers manifest declarations, applies shared definitions, reads configured files
 * and local package terms, then resolves missing text through GitHub and SPDX. It updates package
 * objects in place, including objects shared with merged source inventories.
 */
class LocalVendorBOM extends LicenseBOM
{
    private $manifestEnricher;

    /**
     * @var BOMPackageEnricherInterface[]
     */
    private $textEnrichers;

    /**
     * Prepares the default enrichers without reading manifests or downloading license text.
     *
     * {@inheritDoc}
     * @see LicenseBOM::__construct()
     *
     * @param string $vendorPath
     * @param ClientInterface|null $client HTTP transport for remote license sources.
     */
    public function __construct(string $vendorPath, ?ClientInterface $client = null)
    {
        parent::__construct();
        $this->manifestEnricher = new FindLicensePackageJsonEnricher($vendorPath);
        $this->textEnrichers = [
            new FindLicenseFileEnricher($vendorPath),
            new FindLicenseTextEnricher($vendorPath),
            new FindLicenseGithubEnricher($vendorPath, $client),
            new FindLicenseSPDXEnricher($vendorPath, $client)
        ];
    }

    /**
     * Resolves declarations and terms in place after all sources and overrides have been merged.
     *
     * Manifest recovery precedes text discovery so shared definitions apply to newly found names.
     * Configured and local terms take precedence over remote sources. Download failures propagate;
     * unresolved identifiers never certify available text. Later merges require another explicit call.
     *
     * @return LicenseBOMInterface
     */
    public function enrich() : LicenseBOMInterface
    {
        foreach ($this->getPackages() as $package) {
            $this->addPackage($this->manifestEnricher->enrich($package));
        }
        foreach ($this->getPackages() as $package) {
            foreach ($this->textEnrichers as $enricher) {
                $package = $enricher->enrich($package);
            }
            $this->addPackage($package);
        }
        return $this;
    }
}
