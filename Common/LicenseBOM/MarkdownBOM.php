<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;

/**
 * This bill-of-material can create a well-readable markdown file listing its packages and their licenses
 * 
 * @author Thomas Ressel
 *
 */
class MarkdownBOM extends AbstractBOMDecorator
{
    private $licenseArray = [];
    
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
     * Lists variants for license-text underneath unique package-licenses for method 'toMarkdown'
     * @param BOMPackageInterface[] $packageArray
     * @return string
     */
    public function writeLicenseTextVariants(array $packageArray) : string
    {
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
            if(! in_array($package->getLicenseText($licenseUsed), $processedLicsVariants)) {
                $md .= PHP_EOL . "### {$this->getLicenseVariantName($packageArray, $package)}" . PHP_EOL . PHP_EOL;
                array_push($processedLicsVariants, $package->getLicenseText($licenseUsed));
                $md .= PHP_EOL . ($package->getLicenseText($licenseUsed) ?? ''). PHP_EOL;
            }
        }
        return $md;
    }
    
    protected function getLicenseVariantName(array $packageArray, BOMPackageInterface $package) : string
    {
        $count = $this->countVariant($packageArray, $package);
        return "{$package->getLicenseUsed()}<sup>*{$count}</sup>";
    }

    /**
     * Counts unique license-text variants for method 'toMarkdown'
     * @param BOMPackageInterface[] $packageArray
     * @param BOMPackageInterface $item
     * @return string
     */
    protected function countVariant(array $packageArray, BOMPackage $package) : string 
    {
        $count = 0;
        $licenseUsed = $package->getLicenseUsed();
        $licenseTextArray = $this->readLicenseArray($packageArray);
        $searchArray = $licenseTextArray[$package->getLicenseUsed()];
        if(empty($searchArray)) {
            $searchArray[0] = $package->getLicenseUsed();
        }
        $count = array_search($package->getLicenseText($licenseUsed), $searchArray);
        $count = $count + 1;
        return $count;
    }
    
    /**
     * Fills array with license-text ordered by license [ 'MIT' => [0 => '...', 1 => '...'] ] for countVariant-method
     * @param array $packageArray
     * @return array|NULL
     */
    protected function readLicenseArray(array $packageArray) : ?array
    {
        $knownTexts = [];
        foreach ($packageArray as $package) {
            $licName = $package->getLicenseNames() ?? null;
            $licenseUsed = $package->getLicenseUsed();
            // lic-variable for filling with license-text
            $lic = null;
            switch (true) {
                // find license-text if array-attribute license_text ist set
                case null !== $lic = $package->getLicenseText($licenseUsed);
                break;
                // find license-text if array-attribute license_file is set
                case null !== $package->getLicenseFile($licenseUsed);
                break;
            }
            // fill array with license-text ordered by license [ 'MIT' => [0 => '...', 1 => '...'] ]
            if ($lic !== null) {
                $knownTexts = $this->licenseArray[$licName[0]] ?? [];
                if (!in_array($lic, $knownTexts)) {
                    $this->licenseArray[$licName[0]][] = $lic;
                }
            }
        }
        return $this->licenseArray;
    }  
}