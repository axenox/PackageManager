<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\LicenseBOMInterface;

/**
 * This bill-of-material can create a JSON file similar to composer.lock listing its packages and their licenses
 * 
 * @author Andrej Kabachnik
 *
 */
class JsonBOM extends AbstractBOMDecorator
{
    /**
     * Saves JSON generated in method 'toMarkdown'
     * @param string $filePath
     * @return LicenseBOMInterface
     */
    public function saveJSON(string $filePath) : LicenseBOMInterface
    {
        $arr = ['packages' => []];
        foreach ($this->getPackages() as $package) {
            $arr['packages'][] = $package->toComposerArray();
        }
        file_put_contents($filePath, json_encode($arr, JSON_PRETTY_PRINT));
        return $this;
    }
}