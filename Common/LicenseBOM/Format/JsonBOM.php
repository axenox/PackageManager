<?php
namespace axenox\PackageManager\Common\LicenseBOM\Format;

use axenox\PackageManager\Common\LicenseBOM\AbstractBOMDecorator;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\LicenseBOMExporterInterface;
use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonParserError;

/**
 * This bill-of-material can create a JSON file similar to composer.lock listing its packages and their licenses
 * 
 * Use `format: full` (the default) to retain all package metadata, or `format: minimal`
 * to export only package names and available versions for vulnerability queries.
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
     * - `minimal` retains only `name` and an available `version`, without changing their values.
     * Scanner adapters must resolve the ecosystem and translate asset aliases as needed.
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
        $arr = ['packages' => []];
        foreach ($this->getPackages() as $package) {
            $data = $package->toComposerArray();
            $arr['packages'][] = $this->format === 'minimal'
                ? array_intersect_key($data, ['name' => true, 'version' => true])
                : $data;
        }
        file_put_contents($filePath, json_encode($arr, JSON_PRETTY_PRINT));
        return $this;
    }
}