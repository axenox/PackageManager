<?php
namespace axenox\PackageManager\Common\LicenseBOM\Format;

use axenox\PackageManager\Common\LicenseBOM\AbstractBOMDecorator;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\LicenseBOMExporterInterface;
use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\Exceptions\RuntimeException;

/**
 * This bill-of-material can create a well-readable markdown file listing its packages and their licenses
 * 
 * @author Thomas Ressel
 *
 */
class MarkdownBOM extends AbstractBOMDecorator implements LicenseBOMExporterInterface
{
    use iCanBeConvertedToUxonTrait;

    /**
     * {@inheritDoc}
     * 
     * @see LicenseBOMExporterInterface::saveToFile()
     */
    public function saveToFile(string $filePath) : LicenseBOMInterface
    {
        return $this->saveMarkdown($filePath);
    }
    
    /**
     * Saves markdown generated in method 'toMarkdown'
     * @param string $filePath
     * @return LicenseBOMInterface
     */
    public function saveMarkdown(string $filePath) : LicenseBOMInterface
    {
        file_put_contents($filePath, $this->toMarkdown($this->getPackages()));
        return $this;
    }

    /**
     * Generates markdown
     * @param array $packageArray
     * @return string
     */
    public function toMarkdown(array $packageArray): string
    {
        // Orders packages ascending to first license-name
        $packageArrayOrdered = $this->orderByLicense($packageArray);
        
        $md = "# Licenses\n\n";
        
        // write unique license-names into markdown
        $md .= $this->writeUniqueLicenseNames($packageArrayOrdered);
        // list variants for license-text underneath unique package-licenses
        $md .= $this->writeLicenseTextVariants($packageArrayOrdered);
        return $md;
    }

    /**
     * Sorts packages ascending to first license-name in new array
     * @param array $packageArray
     * @return array
     */
    public function orderByLicense(array $packageArray) : array
    {
        $arrayWithOnlyFirstLicense = [];
        
        foreach($packageArray as $package){
         array_push($arrayWithOnlyFirstLicense, ($package->getLicenseUsed()));
        }
        array_multisort($arrayWithOnlyFirstLicense, SORT_ASC, $packageArray);
        return $packageArray;
    }
     
     /**
      * Writes unique license-names into markdown for method 'toMarkdown'
      * @param array $packageArray
      * @return string
      */
     public function writeUniqueLicenseNames(array $packageArray) : string
     {
         $md = '';
         // Array for foreach-sorting
         $processedLics = [];

         // Writes package-information if package does not exist yet
         foreach ($packageArray as $package) {
             $licenseUsed = $package->getLicenseUsed();
             if(!in_array($licenseUsed, $processedLics)){
                 
                 $md .= PHP_EOL . "## " . ($licenseUsed)
                 . PHP_EOL . PHP_EOL;
                 $md .= '| Package | Version | Description | License variant |' . PHP_EOL;
                 $md .= '| ------- | ------- | ----------- | ----------- |' . PHP_EOL;
                 array_push($processedLics, $licenseUsed);
             }
             
             // Writes packages underneath correct license-value
             $md .= "| {$package->getName()} | " . ($package->getVersion() ?? '') . "| "
                 . ($package->getDescription() ?? '') . " |";
             // If license_text is set, return 'See version XX: YY below'
             if($package->hasLicenseText()){
                 $md .= $this->getLicenseVariantName($packageArray, $package) . PHP_EOL;
             } else if(null !== $package->getLicenseLink($licenseUsed)) {
                 // If license_link is set, return '[licenseUsed]' as link
                 $md .= "See Link: [" . $licenseUsed . "](" . $package->getLicenseLink($licenseUsed) . ")" . PHP_EOL;
             } else {
                 // if no license_text/link is set, return 'Empty'
                 $md .= "Empty" . PHP_EOL;
             }
         }
         return $md;
     }
    
    /**
    * Prints one original text per effective license and normalized variant.
    *
    * Case and whitespace differences share a variant without modifying the displayed terms.
    * Copyright notices, dates, punctuation and changes to license clauses remain significant.
    *
     * @param BOMPackageInterface[] $packageArray
     * @return string
     */
    public function writeLicenseTextVariants(array $packageArray) : string
    {
        $md = '';
        // array-counter for already processed licenses
        $processedLicsHeader = [];
        $processedLicsVariants = [];

        foreach ($packageArray as $package) {
            $licenseUsed = $package->getLicenseUsed();
            // Writes new license-name for License-text variants if license-name does not exist yet
            if(! in_array($package->getLicenseUsed(), $processedLicsHeader)) {
                $md .= PHP_EOL 
                    . "## " . $package->getLicenseUsed()
                    . PHP_EOL . PHP_EOL;
                array_push($processedLicsHeader, $package->getLicenseUsed());
            }
            // Writes version-number beneath License-text variant if license-text does not exist yet
            $text = $package->getLicenseText($licenseUsed) ?? '';
            $normalized = $this->normalizeLicenseText($text);
            if (! isset($processedLicsVariants[$licenseUsed][$normalized])) {
                $md .= PHP_EOL . "### {$this->getLicenseVariantName($packageArray, $package)}" . PHP_EOL . PHP_EOL;
                $processedLicsVariants[$licenseUsed][$normalized] = true;
                $md .= PHP_EOL . $text . PHP_EOL;
            }
        }
        return $md;
    }
    
    /**
     * Keeps package table references aligned with the printed normalized variants.
     *
     * @param BOMPackageInterface[] $packageArray
     * @param BOMPackageInterface $package
     * @return string
     */
    protected function getLicenseVariantName(array $packageArray, BOMPackageInterface $package) : string
    {
        $count = $this->countVariant($packageArray, $package);
        return "{$package->getLicenseUsed()}<sup>*{$count}</sup>";
    }

    /**
    * Finds the variant number using the same comparison as the printed license sections.
    *
     * @param BOMPackageInterface[] $packageArray
    * @param BOMPackage $package
     * @return string
     */
    protected function countVariant(array $packageArray, BOMPackage $package) : string 
    {
        $count = 0;
        $licenseUsed = $package->getLicenseUsed();
        $licenseTextArray = $this->readLicenseArray($packageArray);
        $searchArray = $licenseTextArray[$package->getLicenseUsed()] ?? [];
        if(empty($searchArray)) {
            $searchArray[0] = '';
        }
        $normalizedTexts = array_map([$this, 'normalizeLicenseText'], $searchArray);
        $count = array_search($this->normalizeLicenseText($package->getLicenseText($licenseUsed) ?? ''), $normalizedTexts, true);
        $count = $count + 1;
        return $count;
    }
    
    /**
    * Collects representative original texts per effective license for the current export.
    *
    * Rebuilding the collection prevents previous exports from influencing variant numbering.
    *
    * @param BOMPackageInterface[] $packageArray
    * @return array<string, string[]>|null
     */
    protected function readLicenseArray(array $packageArray) : ?array
    {
        $licenseArray = [];
        $knownTexts = [];
        foreach ($packageArray as $package) {
            $licenseUsed = $package->getLicenseUsed();
            $text = $package->getLicenseText($licenseUsed) ?? '';
            $normalized = $this->normalizeLicenseText($text);
            if (! isset($knownTexts[$licenseUsed][$normalized])) {
                $knownTexts[$licenseUsed][$normalized] = true;
                $licenseArray[$licenseUsed][] = $text;
            }
        }
        return $licenseArray;
    }  

    /**
     * Ignores letter case and whitespace layout without discarding legally meaningful wording.
     *
     * Whitespace is collapsed rather than removed so distinct words cannot be joined together.
     * The original text remains untouched for display and other BOM exporters.
     *
     * @param string $text
     * @return string
     */
    protected function normalizeLicenseText(string $text) : string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text);
        if ($normalized === null) {
            throw new RuntimeException('Cannot compare license text: expected valid UTF-8.');
        }
        return mb_strtolower(trim($normalized), 'UTF-8');
    }
}