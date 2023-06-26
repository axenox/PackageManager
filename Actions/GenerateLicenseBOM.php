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
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use axenox\PackageManager\Common\LicenseBOM\JsonBOM;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;

/**
 * 
 *
 * @author Andrej Kabachnik
 *        
 */
class GenerateLicenseBOM extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    const FORMAT_MARKDOWN = 'markdown';
    
    const FORMAT_JSON = 'json';
    
    private $saveTo = [
        "vendor/Licenses.md" => "markdown", 
        "vendor/licenses.json" => "json"
    ];

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

        // save complete markdown as file
        foreach ($this->getSaveTo() as $path => $format) {
            yield '  Saving ' . $format . ' to ' . $path . PHP_EOL;
            switch (strtolower($format)) {
                case self::FORMAT_MARKDOWN:
                    $markdownBOM = new MarkdownBOM($bigBOM);
                    $markdownBOM->saveMarkdown($this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $path);
                    break;
                case self::FORMAT_JSON:
                    $markdownBOM = new JsonBOM($bigBOM);
                    $markdownBOM->saveJSON($this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $path);
                    break;
                default:
                    throw new ActionConfigurationError($this, 'Invalid license BOM export format "' . $format . '"!');
            }
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
        yield "DONE generating license BOM";
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
     * 
     * @return string[]
     */
    protected function getSaveTo() : array
    {
        return $this->saveTo;
    }
    
    /**
     * List of file paths relative to the installation folder and corresponding formats
     * 
     * @uxon-property save_to_files
     * @uxon-type object
     * @uxon-template {"vendor/Licenses.md": "markdown", "vendor/licenses.json": "json"}
     * @uxon-default {"vendor/Licenses.md": "markdown", "vendor/licenses.json": "json"}
     * 
     * @param UxonObject|string[] $value
     * @return GenerateLicenseBOM
     */
    public function setSaveToFiles($uxonOrArray) : GenerateLicenseBOM
    {
        $this->saveTo = $uxonOrArray instanceof UxonObject ? $uxonOrArray->toArray() : $uxonOrArray;
        return $this;
    }
}