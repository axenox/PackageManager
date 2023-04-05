<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
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
        // Create empty BigBom
        $bigBOM = new LicenseBOM();
        yield "LicenseBOM created" .PHP_EOL;
        $this->emptyBuffer();
        // find license_text if licensefile is found in packagepath
        $bigBOM->addEnricher(new FindLicenseTextEnricher($vendorPath));
        yield "FindLicenseTextEnricher created" .PHP_EOL;
        $this->emptyBuffer();
        // find license_text if Github-link is set
        $bigBOM->addEnricher(new FindLicenseGithubEnricher($vendorPath));
        yield "FindLicenseGithubEnricher created" .PHP_EOL;
        $this->emptyBuffer();
        // find license_text from SPDX-Github-Repository if licenseName is set
        $bigBOM->addEnricher(new FindLicenseSPDXEnricher($vendorPath));
        yield "FindLicenseSPDXEnricher created" .PHP_EOL;
        $this->emptyBuffer();
        // Create BOM from Composer.lock
        $composerLockPath = $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . 'composer.lock';
        $composerBOM = new ComposerBOM($composerLockPath);
        yield "ComposerBOM created" .PHP_EOL;
        $this->emptyBuffer();
        // Merge BigBom with Composer-BOM
        $bigBOM->merge($composerBOM);
        // merge all includes-jsons with bigBOM
        $includesArray = [];
        foreach($composerBOM->getPackageData() as $package) {
            $filePath = $vendorPath . DIRECTORY_SEPARATOR . $package->getName() . DIRECTORY_SEPARATOR . "includes.json";
            if(file_get_contents($filePath) !== false){
                // find license_text if license_file is set
                $bigBOM->addEnricher(new FindLicenseFileEnricher($filePath));
                $includesArray[$package->getName()] = new IncludesBOM($filePath, $vendorPath);
                yield "IncludesBOM created from: {$package->getName()}" .PHP_EOL;
                $this->emptyBuffer();
                $bigBOM->merge($includesArray[$package->getName()]);
            }
        }
        yield $this->printLineDelimiter();
        
        // save complete markdown as file
        $markdownPath = $vendorPath . DIRECTORY_SEPARATOR . 'Licenses.md';
        $markdownBOM = new MarkdownBOM($bigBOM);
        $markdownBOM->saveMarkdown($markdownPath);
        
        // Show all Bill of materials (BOMs) found
        yield '# Found BOMs:' . PHP_EOL. PHP_EOL;
        $this->emptyBuffer();
        yield $composerBOM->getPackageName(). PHP_EOL;
        $this->emptyBuffer();
        // show all includes-BOMs with includes-jsons
        foreach($includesArray as $includesBOM) {
            yield $includesBOM->getPackageName(). PHP_EOL;
            $this->emptyBuffer();
        }
        
        // Show packages without license as a list
        yield PHP_EOL . "# Packages without License:" . PHP_EOL . PHP_EOL;
        $this->emptyBuffer();
        foreach ($bigBOM->getPackages() as $package) {
            if ($package->hasLicense() === false) {
                yield 'Name: ' . $package->getName() . PHP_EOL;
                yield 'Version: ' . $package->getVersion() . PHP_EOL;
                yield 'Source: ' . $package->getSource() . PHP_EOL. PHP_EOL;
                $this->emptyBuffer();
            }
        }
        
        // Show packages without license-text as a list
        yield PHP_EOL . "# Packages without License-Text:" . PHP_EOL . PHP_EOL;
        $this->emptyBuffer();
        foreach ($bigBOM->getPackages() as $package) {
            if ($package->hasLicenseText() === false) {
                yield 'Name: ' . $package->getName() . PHP_EOL;
                yield 'Version: ' . $package->getVersion() . PHP_EOL;
                yield 'Source: ' . $package->getSource() . PHP_EOL. PHP_EOL;
                $this->emptyBuffer();
            }
        }
        yield $this->printLineDelimiter();
        yield "Refreshed license information in Licenses.md";
    }
    
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
    
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
}