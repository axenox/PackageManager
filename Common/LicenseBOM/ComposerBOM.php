<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use exface\Core\DataTypes\StringDataType;
use axenox;

class ComposerBOM extends LicenseBOM implements LicenseBOMInterface
{
    private $filePath = null;
    
    public function __construct(string $composerLockPath)
    {
        $this->filePath = $composerLockPath;
        // reads composerJson and fills BOM with packages        
        $this->readComposerJson($this->filePath);
    }
    
    /**
     * read json-file from Composer.lock-filePath
     * @param string $filePath
     * @return LicenseBOMInterface
     */
    protected function readComposerJson(string $filePath) : LicenseBOMInterface
    {        
        $json = file_get_contents($filePath);
        $packageArray = JsonDataType::decodeJson($json);
        foreach (($packageArray['packages'] ?? []) as $packageData) {
            $this->addPackage(new BOMPackage($packageData));
        }
        return $this;
    }

    public function getPackageName() : string
    {
        return end(explode(DIRECTORY_SEPARATOR, $this->getFilePath()));
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