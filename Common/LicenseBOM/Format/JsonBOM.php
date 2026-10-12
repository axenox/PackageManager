<?php
namespace axenox\PackageManager\Common\LicenseBOM\Format;

use axenox\PackageManager\Common\LicenseBOM\AbstractBOMDecorator;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\LicenseBOMExporterInterface;
use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonParserError;

/**
 * This bill-of-material can create a JSON file similar to composer.lock listing its packages and their licenses
 * 
 * Use `format: full` (the default) to retain all package metadata, or `format: minimal`
 * to export native package names, ecosystems, available versions and Git references for vulnerability queries.
 * 
 * @author Andrej Kabachnik
 *
 */
class JsonBOM extends AbstractBOMDecorator implements LicenseBOMExporterInterface
{
    use iCanBeConvertedToUxonTrait;

    private $format = 'full';

    /**
     * Select complete package metadata or minimal package identities for scanners.
     * 
     * - `full` preserves all metadata, including licenses and license texts.
        * - `minimal` retains `name`, `ecosystem`, an available `version` and Git source type/reference.
        * Both formats translate Composer asset aliases to their native package names.
     * 
     * @uxon-property format
     * @uxon-type [minimal,full]
     * @uxon-default full
     * 
     * @param string $format
     * @return JsonBOM
     */
    public function setFormat(string $format) : JsonBOM
    {
        if (!in_array($format, ['minimal', 'full'], true)) {
            throw new UxonParserError(
                new UxonObject(['format' => $format]),
                'Invalid JSON BOM format "' . $format . '". Expected "minimal" or "full".',
                null,
                null,
                'format'
            );
        }
        $this->format = $format;
        return $this;
    }

    /**
     * {@inheritDoc}
     * 
     * @see LicenseBOMExporterInterface::saveToFile()
     */
    public function saveToFile(string $filePath) : LicenseBOMInterface
    {
        return $this->saveJSON($filePath);
    }

    /**
    * Saves the package inventory using the configured metadata format.
    * 
     * @param string $filePath
     * @return LicenseBOMInterface
     */
    public function saveJSON(string $filePath) : LicenseBOMInterface
    {
        file_put_contents($filePath, $this->buildJson());
        return $this;
    }

    /**
     * Serializes the configured inventory for file exports or archived database values.
     * 
     * Both consumers use the same format selection, so minimal snapshots retain
     * exactly the package identities emitted by the file exporter.
     * 
     * @return string
     */
    public function buildJson() : string
    {
        $arr = ['packages' => []];
        foreach ($this->getPackages() as $package) {
            $data = $package->toComposerArray();
            $data['ecosystem'] = PackageEcosystemDataType::findEcosystem($data['name'], $data['ecosystem'] ?? null);
            $data['name'] = PackageEcosystemDataType::convertNameFromAssetPackagist($data['name'], $data['ecosystem']);
            if ($this->format === 'minimal') {
                $source = $data['source'] ?? [];
                $data = array_intersect_key($data, ['name' => true, 'ecosystem' => true, 'version' => true]);
                if (($source['type'] ?? '') === 'git' && is_string($source['reference'] ?? null) && $source['reference'] !== '') {
                    $data['source'] = ['type' => 'git', 'reference' => $source['reference']];
                }
            } else {
                $data['license_used'] = $package->getLicenseUsed();
            }
            $arr['packages'][] = $data;
        }
        return json_encode($arr, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}