<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseTextEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicensePackageJsonEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseGithubEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseSPDXEnricher;
use axenox\PackageManager\Common\LicenseBOM\ComposerBOM;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\IncludesBOM;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\LicenseBOMExporterInterface;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\DataTypes\StringDataType;

/**
 * Generates a global bill of materials (BOM) for the software included in an installation.
 * 
 * CLI: `vendor/bin/action axenox.PackageManager:GenerateLicenseBOM`
 * 
 * Gathers a global list of packages by scanning
 * 
 * - `composer.lock` file of the installation - resolving Composer dependencies
 * - `includes.json` files of installed apps - discovering bundled software and supplement metadata from them
 * 
 * Then validates if all discovered packages have proper license declarations and tries to enrich missing information:
 * 
 * - Scans local `package.json` files for license declarations
 * - Scans local license files for license texts
 * - Queries remote sources for license information if local data has a license name, but no text
 * 
 * The resulting BOM can then be exported in various formats for review and compliance purposes:
 * 
 * - Markdown (by default to `vendor/Licenses.md`) - to publish via DocsFacade
 * - JSON (by default to `vendor/Licenses.json`) - machine readable - e.g. for the `axenox.PackageManager.Audit` action
 * - CycloneDX (by default to `vendor/SBOM.cdx.json`) - for integration with security and compliance tools
 * 
 * For registry ownership, versionless supplements and supported license-text sources, see
 * [Bundling libraries and supplementing license metadata](https://github.com/ExFace/Core/blob/1.x-dev/Docs/developer_docs/Thrid-party_libraries/Bundling_third_party_libs.md).
 * Regenerate the full BOM after registry changes before running a license audit.
 * 
 * @author Andrej Kabachnik
 *        
 */
class GenerateLicenseBOM extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    private $saveToFiles = null;

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::init()
     */
    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::LIST_);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [];
    }
    
    /**
     * Resolves declarations before text discovery so terms use the correct license name.
     *
     * Manifest recovery precedes a separate text-enrichment pass so shared license definitions
     * apply to newly discovered names before local files or remote sources are consulted.
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred() : \Generator
    {
        $vendorPath = $this->getWorkbench()->filemanager()->getPathToVendorFolder();
        yield "Generating license BOMs" . PHP_EOL;
        yield '  Found BOMs:' . PHP_EOL;
        
        // Create empty BigBom
        $bigBOM = new LicenseBOM();
        
        // Create BOM from Composer.lock
        $composerLockPath = $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . 'composer.lock';
        if (file_exists($composerLockPath)) {
            yield '  - composer.lock' . PHP_EOL;
            $composerBOM = new ComposerBOM($composerLockPath);
            // Merge BigBom with Composer-BOM
            $bigBOM->merge($composerBOM);
        }
        
        
        // merge all includes-jsons with bigBOM
        // Get the subfolders within the "vendor" directory
        $includes = glob($vendorPath . '/*/*/includes.json');
        $bundleUrns = [];
        foreach ($bigBOM->getPackages() as $package) {
            $bundleUrns[$package->getName()] = $package->getUrn();
        }
        $inclusionBOMs = [];
        foreach ($includes as $include) {
            // find license_text if license_file is set
            $bundleName = basename(dirname($include, 2)) . '/' . basename(dirname($include));
            $inclusionBOMs[] = new IncludesBOM($include, $vendorPath, $bundleUrns[$bundleName] ?? null);
            yield "  - " . StringDataType::substringAfter($include, $vendorPath . '/') .  PHP_EOL;
        }

        $licensesPath = $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Licenses.config.json';
        if (is_file($licensesPath)) {
            $inclusionBOMs[] = new IncludesBOM($licensesPath, $vendorPath);
            yield '  - config/Licenses.config.json' . PHP_EOL;
        }
        foreach ($this->mergeInclusions($bigBOM, $inclusionBOMs) as $name) {
            yield '  WARNING: Ignoring license supplement without an installed or bundled package: ' . $name . PHP_EOL;
        }

        $manifestEnricher = new FindLicensePackageJsonEnricher($vendorPath);
        foreach ($bigBOM->getPackages() as $package) {
            $bigBOM->addPackage($manifestEnricher->enrich($package));
        }

        $bigBOM->addEnricher(new FindLicenseTextEnricher($vendorPath));
        $bigBOM->addEnricher(new FindLicenseGithubEnricher($vendorPath));
        $bigBOM->addEnricher(new FindLicenseSPDXEnricher($vendorPath));
        $bigBOM->addEnricher(new FindLicenseFileEnricher($vendorPath));
        foreach ($bigBOM->getPackages() as $package) {
            $bigBOM->addPackage($package);
        }

        foreach ($this->getSaveTo() as $path => $configuration) {
            $uxon = $configuration instanceof UxonObject ? $configuration->copy() : new UxonObject($configuration);
            $exporter = $this->createExporter($bigBOM, $uxon);
            yield '  Saving ' . get_class($exporter) . ' to ' . $path . PHP_EOL;
            $exporter->saveToFile($this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $path);
        }
        
        // Show packages without license as a list
        yield "  MISSING license information:" . PHP_EOL;
        foreach ($bigBOM->getPackages() as $package) {
            if ($package->hasLicense() === false) {
                yield '  - ' . $this->printPackageInfo($package) . PHP_EOL;
            }
        }
        // Show packages without license-text as a list
        yield "  MISSING license text:" . PHP_EOL;
        foreach ($bigBOM->getPackages() as $package) {
            if ($package->hasLicenseText() === false) {
                yield '  - ' . $this->printPackageInfo($package) . PHP_EOL;
            }
        }
        yield "DONE generating license BOM" . PHP_EOL;
    }
    
    /**
     * Registers bundled packages before applying supplements so absent software is not inventoried.
     * 
     * All manifests are considered before matching supplements, including local license overrides.
     * Matching uses registry identities rather than versions so installed packages with unknown
     * versions remain present and each known installed version receives the supplement.
     * 
     * @param LicenseBOMInterface $bom Installed inventory to enrich in place.
     * @param LicenseBOMInterface[] $inclusions
     * @return string[] Unmatched supplement names for generation warnings.
     */
    protected function mergeInclusions(LicenseBOMInterface $bom, array $inclusions) : array
    {
        foreach ($inclusions as $inclusion) {
            $bundles = new LicenseBOM();
            $bundles->setLicenses($inclusion->getLicenses());
            foreach ($inclusion->getPackages() as $package) {
                if ($package->isSeparatePackage()) {
                    $bundles->addPackage($package);
                }
            }
            $bom->merge($bundles);
        }
        $identities = [];
        foreach ($bom->getPackages() as $package) {
            $data = $package->toComposerArray();
            $identities[PackageEcosystemDataType::buildPackageKey($package->getName(), $data['ecosystem'] ?? null)] = true;
        }
        $unmatched = [];
        foreach ($inclusions as $inclusion) {
            $supplements = new LicenseBOM();
            foreach ($inclusion->getPackages() as $package) {
                if (! $package->isSupplementalInfo()) {
                    continue;
                }
                $data = $package->toComposerArray();
                $identity = PackageEcosystemDataType::buildPackageKey($package->getName(), $data['ecosystem'] ?? null);
                if (isset($identities[$identity])) {
                    $supplements->addPackage($package);
                } else {
                    $unmatched[] = $package->getName();
                }
            }
            $bom->merge($supplements);
        }
        return array_values(array_unique($unmatched));
    }

    /**
     * 
     * @param BOMPackage $package
     * @return string|NULL
     */
    protected function printPackageInfo(BOMPackage $package) : ?string
    {
        return "{$package->getName()}, {$package->getVersion()} ({$package->getSource()})";
    }

    /**
     * 
     */
    protected function emptyBuffer()
    {
        ob_flush();
        flush();
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments() : array
    {
        return [];
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() : array
    {
        return [];
    }
    
    /**
     * 
     * @return string
     */
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
    
    /**
     * 
     * @return \Generator
     */
    public function generateMarkdownBOM() : \Generator
    {
        yield from $this->performDeferred();
    }
    
    /**
     * Returns action-specific destinations or the installation's SBOM.files defaults.
     * 
     * @return array<string,array<string,mixed>>
     */
    protected function getSaveTo() : array
    {
        if ($this->saveToFiles !== null) {
            return $this->saveToFiles;
        }
        $files = $this->getApp()->getConfig()->getOption('SBOM.files');
        return $files instanceof UxonObject ? $files->toArray() : $files;
    }

    /**
     * Creates an exporter and imports its configuration without the class selector.
     * 
     * @param LicenseBOMInterface $bom
     * @param UxonObject $uxon
     * @return LicenseBOMExporterInterface
     */
    protected function createExporter(LicenseBOMInterface $bom, UxonObject $uxon) : LicenseBOMExporterInterface
    {
        $class = $uxon->getProperty('class');
        if (!is_string($class) || !is_subclass_of($class, LicenseBOMExporterInterface::class)) {
            throw new ActionConfigurationError($this, 'License BOM exporter class must implement ' . LicenseBOMExporterInterface::class . '.');
        }
        $exporter = new $class($bom);
        $uxon = $uxon->copy();
        $uxon->unsetProperty('class');
        $exporter->importUxonObject($uxon);
        return $exporter;
    }

    /**
     * Define output paths and the exporter configuration for each file.
     * 
     * Paths are relative to the installation folder. Each configuration requires a `class`
     * selecting the exporter; remaining properties configure that exporter.
     * Defaults come from the PackageManager `SBOM.files` configuration option.
     * An explicit map replaces these defaults; an empty map disables file output.
     * Destination directories must already exist. Existing files are replaced.
     * 
     * @uxon-property save_to_files
     * @uxon-type {string => object}
    * @uxon-template {"vendor/Licenses.md": {"class": "\\axenox\\PackageManager\\Common\\LicenseBOM\\Format\\MarkdownBOM"}}
     * 
     * @param UxonObject|array<string,array<string,mixed>> $uxonOrArray
     * @return GenerateLicenseBOM
     */
    public function setSaveToFiles($uxonOrArray) : GenerateLicenseBOM
    {
        $this->saveToFiles = $uxonOrArray instanceof UxonObject ? $uxonOrArray->toArray() : $uxonOrArray;
        return $this;
    }
}