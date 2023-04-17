<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Interfaces\BOMPackageInterface;
use exface\Core\DataTypes\StringDataType;

class IncludesBOM extends LicenseBOM implements LicenseBOMInterface 
{
    private $filePath = null;
    
    private $vendorFolderPath = null;
    
    private $json = null;
    
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
    
    public function getPackageName() : string
    {
        return StringDataType::substringAfter($this->getFilePath(), $this->vendorFolderPath . DIRECTORY_SEPARATOR);
    }

    /**
     * 
     * @return string
     */
    public function getFilePath() : string
    {
        return $this->filePath;
    }
    
    /**
     * return all packages
     * @return array
     */
    public function getPackageData() : array
    {
        return $this->getPackages();
    }
}