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
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseGithubEnricher;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseSPDXEnricher;
use axenox\PackageManager\Common\LicenseBOM\ComposerBOM;
use axenox\PackageManager\Common\LicenseBOM\Enricher\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\IncludesBOM;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\LicenseBOMExporterInterface;
use exface\Core\DataTypes\StringDataType;

/**
 * Generates a global bill of materials (BOM) for the software included in an installation.
 * 
 * Combines resolved Composer dependencies with bundled software registered in installed apps'
 * `includes.json` files, collects available license texts, and exports Markdown, JSON and CycloneDX SBOM documents.
 * Use save_to_files to configure output destinations and formats. Generation reports missing license
 * information and texts so the documents can be reviewed before distribution.
 * 
 * CLI: `vendor/bin/action axenox.PackageManager:GenerateLicenseBOM`
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
        // find license_text if licensefile is found in packagepath
        $bigBOM->addEnricher(new FindLicenseTextEnricher($vendorPath));
        // find license_text if Github-link is set
        $bigBOM->addEnricher(new FindLicenseGithubEnricher($vendorPath));
        // find license_text from SPDX-Github-Repository if licenseName is set
        $bigBOM->addEnricher(new FindLicenseSPDXEnricher($vendorPath));
        $bigBOM->addEnricher(new FindLicenseFileEnricher($vendorPath));
        
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
        foreach ($includes as $include) {
            // find license_text if license_file is set
            $includesBOM = new IncludesBOM($include, $vendorPath);
            $bigBOM->merge($includesBOM);
            yield "  - " . StringDataType::substringAfter($include, $vendorPath . '/') .  PHP_EOL;
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