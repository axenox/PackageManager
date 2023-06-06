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
use axenox\PackageManager\Common\LicenseBOM\FindLicenseTextEnricher;
use axenox\PackageManager\Common\LicenseBOM\FindLicenseGithubEnricher;
use axenox\PackageManager\Common\LicenseBOM\FindLicenseSPDXEnricher;
use axenox\PackageManager\Common\LicenseBOM\ComposerBOM;
use axenox\PackageManager\Common\LicenseBOM\FindLicenseFileEnricher;
use axenox\PackageManager\Common\LicenseBOM\IncludesBOM;
use axenox\PackageManager\Common\LicenseBOM\MarkdownBOM;

/**
 * 
 *
 * @author Andrej Kabachnik
 *        
 */
class GenerateLicenseBOM extends AbstractActionDeferred implements iCanBeCalledFromCLI
{

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
        $BOMFilename = 'Licenses.md';
        $markdownPath = $vendorPath . DIRECTORY_SEPARATOR . $BOMFilename;
        yield "Generating license BOM in {$BOMFilename}" . PHP_EOL;
        yield '  Found BOMs:' . PHP_EOL;
        $this->emptyBuffer();
        
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
        $this->emptyBuffer();
        
        // merge all includes-jsons with bigBOM
        foreach($bigBOM->getPackages() as $package) {
            $filePath = $vendorPath . DIRECTORY_SEPARATOR . $package->getName() . DIRECTORY_SEPARATOR . "includes.json";
            if(file_exists($filePath) && file_get_contents($filePath) !== false){
                // find license_text if license_file is set
                $includesBOM = new IncludesBOM($filePath, $vendorPath);
                $bigBOM->merge($includesBOM);
                yield "  - {$includesBOM->getFilePath()}/includes.json" .PHP_EOL;
                $this->emptyBuffer();
            }
        }
        $this->emptyBuffer();

        // save complete markdown as file
        $markdownBOM = new MarkdownBOM($bigBOM);
        $markdownBOM->saveMarkdown($markdownPath);
        
        // Show packages without license as a list
        yield "  MISSING license information:" . PHP_EOL;
        $this->emptyBuffer();
        foreach ($bigBOM->getPackages() as $package) {
            if ($package->hasLicense() === false) {
                yield '  - ' . $this->printPackageInfo($package) . PHP_EOL;
            }
            $this->emptyBuffer();
        }
        // Show packages without license-text as a list
        yield "  MISSING license text:" . PHP_EOL;
        $this->emptyBuffer();
        foreach ($bigBOM->getPackages() as $package) {
            if ($package->hasLicenseText() === false) {
                yield '  - ' . $this->printPackageInfo($package) . PHP_EOL;
            }
            $this->emptyBuffer();
        }
        yield "DONE generating license BOM in {$BOMFilename}";
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
}