<?php
namespace axenox\PackageManager\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;

/**
 * Configures a license BOM representation and writes it to an output file.
 * 
 * Exporters receive the combined inventory through their constructor and expose
 * format-specific options through UXON setters.
 */
interface LicenseBOMExporterInterface extends LicenseBOMInterface, iCanBeConvertedToUxon
{
    /**
     * Saves the configured representation, replacing an existing file.
     * 
     * @param string $filePath
     * @return LicenseBOMInterface
     */
    public function saveToFile(string $filePath) : LicenseBOMInterface;
}