<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;

/**
 * This license BOM parses an includes.json file containing additional licenses not managed by Composer.
 * 
 * @author andrej.kabachnik
 *
 */
class IncludesBOM extends LicenseBOM implements LicenseBOMInterface 
{
    private $filePath = null;
    
    private $vendorFolderPath = null;
    
    private $json = null;
    
    /**
     * 
     * @param string $includesJsonPath
     * @param string $vendorPath
     */
    public function __construct(string $includesJsonPath, string $vendorPath)
    {
        $this->filePath = $includesJsonPath;
        $this->vendorFolderPath = $vendorPath;
        // reads composerJson and fills BOM with packages
        $this->readIncludesJson($this->filePath);
    }
    
    /**
     * read json-file from IncludesJson-filePath
     * @param string $filePath
     * @return LicenseBOMInterface
     */
    protected function readIncludesJson(string $filePath) : LicenseBOMInterface
    {
        $this->json = file_get_contents($filePath);
        //if json-file at filePath exists, add json-data to packages
        if($this->json !== false){
            $packageArray = JsonDataType::decodeJson($this->json);
            foreach ($packageArray as $packageData) {
                $this->addPackage(new BOMPackage($packageData));
            }
        }
        return $this;
    }

    /**
     * 
     * @return string
     */
    public function getFilePath() : string
    {
        return StringDataType::substringAfter($this->filePath, $this->vendorFolderPath . DIRECTORY_SEPARATOR);
    }
}