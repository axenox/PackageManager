<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;

/**
 * This license BOM parses an includes.json file containing additional licenses not managed by Composer.
 * 
 * Registry format and maintenance rules:
 * [Bundling libraries and supplementing license metadata](https://github.com/ExFace/Core/blob/1.x-dev/Docs/developer_docs/Thrid-party_libraries/Bundling_third_party_libs.md).
 * 
 * @author andrej.kabachnik
 *
 */
class IncludesBOM extends LicenseBOM implements LicenseBOMInterface 
{
    private $filePath = null;
    
    private $vendorFolderPath = null;
    
    private $json = null;

    private $bundledIn = null;
    
    /**
     * 
     * @param string $includesJsonPath
     * @param string $vendorPath
     * @param string|null $bundledIn URN of the installed package owning this manifest.
     */
    public function __construct(string $includesJsonPath, string $vendorPath, ?string $bundledIn = null)
    {
        $this->filePath = $includesJsonPath;
        $this->vendorFolderPath = $vendorPath;
        $this->bundledIn = $bundledIn;
        if ($this->bundledIn === null && basename($includesJsonPath) === 'includes.json') {
            $folder = dirname($includesJsonPath);
            $manifestPath = $folder . '/composer.json';
            $manifest = is_file($manifestPath) ? JsonDataType::decodeJson(file_get_contents($manifestPath)) : [];
            $name = $manifest['name'] ?? basename(dirname($folder)) . '/' . basename($folder);
            $this->bundledIn = BOMPackage::buildUrn($name, $manifest['version'] ?? null);
        }
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
            if (isset($packageArray['packages']) || isset($packageArray['licenses'])) {
                $this->setLicenses($packageArray['licenses'] ?? []);
                $packageArray = $packageArray['packages'] ?? [];
            }
            foreach ($packageArray as $packageData) {
                $package = new BOMPackage();
                $package->importUxonObject(new UxonObject(BOMPackage::normalizeArray($packageData)));
                if ($this->bundledIn !== null && $package->isSeparatePackage()) {
                    $packageData['bundled_in'] = $this->bundledIn;
                    $package = new BOMPackage();
                    $package->importUxonObject(new UxonObject(BOMPackage::normalizeArray($packageData)));
                }
                $this->addPackage($package);
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