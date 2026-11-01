<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use axenox\PackageManager\Interfaces\AuditScannerInterface;
use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Reports missing license declarations and texts in full or compact combined package inventories.
 * 
 * Supply JsonBOM output through sbom, or scan a folder containing vendor/licenses.json.
 * Missing licenses produce high advisories; missing text for the effective license produces
 * low advisories. Compact exports carry license-keyed text-source evidence instead of terms.
 * Private packages are included. Embedded terms and local file references count as available
 * text; license URLs alone do not. Local references are not rechecked on the audit machine.
 * This scanner neither downloads terms nor generates inventories and needs no installation.
 * 
 * Select this prototype in a named AUDIT.SCANNERS or action scanners entry:
 * `{"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\LicenseScanner"}`.
 * This scanner has no additional UXON options.
 */
class LicenseScanner extends AbstractAuditScanner
{
    /**
     * Loads license evidence without falling back from explicit artifacts to the local installation.
     * 
     * Composer locks and standard SBOMs are not full internal license inventories. Shared
     * definitions are resolved through LicenseBOM before inspecting individual packages.
     * 
     * @param TaskInterface $task
     * @return LicenseBOM|null
     */
    protected function getBOM(TaskInterface $task) : ?LicenseBOM
    {
        if ($task->hasParameter(Audit::TASK_PARAM_SBOM)) {
            $data = $this->decode($task->getParameter(Audit::TASK_PARAM_SBOM));
            if (isset($data['bomFormat']) || isset($data['spdxVersion'])) {
                return null;
            }
        } else {
            $folder = $this->getTargetFolder($task);
            if ($folder === null || ! is_file($folder . '/vendor/licenses.json')) {
                return null;
            }
            $data = $this->readJson($folder . '/vendor/licenses.json');
        }
        if (! is_array($data['packages'] ?? null)) {
            throw new RuntimeException('LicenseScanner requires a combined BOM with a packages array.');
        }
        if (isset($data['licenses']) && ! is_array($data['licenses'])) {
            throw new RuntimeException('LicenseScanner requires named licenses to be an object.');
        }
        $bom = new LicenseBOM();
        $bom->setLicenses($data['licenses'] ?? []);
        foreach ($data['packages'] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || trim($package['name']) === '') {
                throw new RuntimeException('LicenseScanner inventory packages must contain a nonempty name.');
            }
            if (array_key_exists('license_text_source', $package)) {
                if (! is_array($package['license_text_source'])) {
                    throw new RuntimeException('LicenseScanner requires license_text_source to be a license-keyed source map.');
                }
                foreach ($package['license_text_source'] as $source) {
                    if (! in_array($source, [
                        BOMPackageInterface::LICENSE_TEXT_SOURCE_EMBEDDED,
                        BOMPackageInterface::LICENSE_TEXT_SOURCE_LOCAL,
                        BOMPackageInterface::LICENSE_TEXT_SOURCE_LINK,
                        BOMPackageInterface::LICENSE_TEXT_SOURCE_MISSING
                    ], true)) {
                        throw new RuntimeException('LicenseScanner license_text_source values must be embedded, local, link or missing.');
                    }
                }
            }
            $bom->addPackage(new BOMPackage($package));
        }
        return $bom;
    }

    /**
     * {@inheritDoc}
     * 
     * @see AuditScannerInterface::supports()
     */
    public function supports(TaskInterface $task) : bool
    {
        return $this->getBOM($task) !== null;
    }

    /**
    * Checks each effective license independently for a declaration and available terms.
     * 
        * Both advisories are returned when both fields are missing. Concrete evidence takes
        * precedence over compact source metadata. Links alone do not contain the terms.
     * 
     * {@inheritDoc}
     * 
     * @see AuditScannerInterface::audit()
     * @return FindingInterface[]
     */
    public function audit(TaskInterface $task) : array
    {
        $bom = $this->getBOM($task);
        if ($bom === null) {
            return [];
        }
        $findings = [];
        foreach ($bom->getPackages() as $package) {
            $data = $package->toComposerArray();
            $ecosystem = PackageEcosystemDataType::findEcosystem($package->getName(), $data['ecosystem'] ?? null);
            $name = PackageEcosystemDataType::convertNameToAssetPackagist($package->getName(), $ecosystem);
            $license = $package->getLicenseUsed();
            if (in_array(strtoupper(trim($license ?? '')), ['', 'OTHER', 'NONE', 'NOASSERTION'], true)) {
                $findings[] = new Finding(
                    'missing-license', 'Package has no license', AdvisoryTypeDataType::LICENSE, $name, 'license', 'high',
                    description: 'No license is recorded for this package in the supplied inventory.',
                    remediation: 'Declare the applicable license in includes.json or config/Licenses.config.json and regenerate the full BOM.',
                    versionInstalled: $package->getVersion() ?? '',
                    ecosystem: $ecosystem
                );
            }
            $textSource = $package->getLicenseTextSource($license);
            if (! in_array($textSource, [
                BOMPackageInterface::LICENSE_TEXT_SOURCE_EMBEDDED,
                BOMPackageInterface::LICENSE_TEXT_SOURCE_LOCAL
            ], true)) {
                $findings[] = new Finding(
                    'missing-license-text', 'Package has no license text', AdvisoryTypeDataType::LICENSE, $name, 'license', 'low',
                    description: 'The inventory records no embedded terms or local license file for the effective license "' . $license . '". A URL alone does not provide the text.',
                    remediation: 'Supply the license text or a local license file for the effective license and regenerate the full BOM.',
                    versionInstalled: $package->getVersion() ?? '',
                    ecosystem: $ecosystem
                );
            }
        }
        return $findings;
    }

    /**
     * Requires no executable, network service or changes to the scanned installation.
     * 
     * {@inheritDoc}
     * 
     * @see AuditScannerInterface::install()
     */
    public function install(TaskInterface $task) : void
    {
    }
}