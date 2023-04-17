<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use exface\Core\DataTypes\JsonDataType;

/**
 * Special license BOM generated from composer.lock
 * 
 * @author Thomas Ressel
 *
 */
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

    /**
     * 
     * @return bool
     */
    public function hasFile() : bool
    {
        if(file_exists($this->filePath)){
            return true;
        } else {
            return false;
        }
    }
}