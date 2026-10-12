<?php
namespace axenox\PackageManager\Common\LicenseBOM\Format;

use axenox\PackageManager\Common\LicenseBOM\AbstractBOMDecorator;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use axenox\PackageManager\Interfaces\LicenseBOMExporterInterface;
use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\Exceptions\RuntimeException;

/**
 * Exports the combined package inventory as a CycloneDX 1.6 JSON SBOM.
 * 
 * Includes available versions, descriptions, source references and effective license texts.
 * Missing metadata and unknown dependency relationships are omitted rather than invented.
 * 
 * See https://cyclonedx.org for the CycloneDX specification. 
 * See also the JSON schema: https://cyclonedx.org/schema/bom-1.6.schema.json
 */
class CycloneDxBOM extends AbstractBOMDecorator implements LicenseBOMExporterInterface
{
    use iCanBeConvertedToUxonTrait;

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
     * Saves the current package inventory as a CycloneDX JSON document.
     * 
     * The destination directory must already exist. Existing files are replaced.
     * 
     * @param string $filePath
     * @return LicenseBOMInterface
     */
    public function saveJSON(string $filePath) : LicenseBOMInterface
    {
        $json = $this->toJSON();
        if (@file_put_contents($filePath, $json) !== strlen($json)) {
            throw new RuntimeException('Cannot write CycloneDX BOM to "' . $filePath . '".');
        }
        return $this;
    }

    /**
     * Serializes the current package inventory without modifying its metadata.
     * 
     * License names are retained as named licenses, including custom labels and expressions.
     * No unverified SPDX identifiers are emitted.
     * 
     * @return string
     */
    public function toJSON() : string
    {
        $components = [];
        foreach ($this->getPackages() as $package) {
            $components[] = $this->buildComponent($package);
        }
        $json = json_encode([
            '$schema' => 'https://cyclonedx.org/schema/bom-1.6.schema.json',
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'version' => 1,
            'metadata' => ['timestamp' => gmdate('Y-m-d\TH:i:s\Z')],
            'components' => $components
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode CycloneDX BOM: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Maps one combined package to a library component with a stable reference.
     * 
     * @param BOMPackageInterface $package
     * @return array<string,mixed>
     */
    protected function buildComponent(BOMPackageInterface $package) : array
    {
        $data = $package->toComposerArray();
        $component = [
            'type' => 'library',
            'bom-ref' => 'package-' . hash('sha256', json_encode([
                PackageEcosystemDataType::buildPackageKey($package->getName(), $data['ecosystem'] ?? null), $package->getVersion()
            ])),
            'name' => $package->getName()
        ];
        if ($package->getVersion() !== null && $package->getVersion() !== '') {
            $component['version'] = $package->getVersion();
        }
        if ($package->getDescription() !== null && $package->getDescription() !== '') {
            $component['description'] = $package->getDescription();
        }
        $purl = $this->buildPackageUrl($package, $data);
        if ($purl !== null) {
            $component['purl'] = $purl;
        }
        $references = [];
        foreach (['vcs' => $data['source']['url'] ?? null, 'website' => $data['homepage'] ?? null] as $type => $url) {
            if ($url !== null && $url !== '') {
                $references[] = ['type' => $type, 'url' => $url];
            }
        }
        $licenseUsed = $package->getLicenseUsed();
        if ($licenseUsed !== null && $licenseUsed !== '' && $licenseUsed !== 'Other') {
            $license = ['name' => $licenseUsed];
            $text = $package->getLicenseText($licenseUsed);
            if ($text !== null && $text !== '') {
                $license['text'] = [
                    'contentType' => 'text/plain',
                    'encoding' => 'base64',
                    'content' => base64_encode($text)
                ];
            }
            $url = $package->getLicenseLink($licenseUsed);
            if ($url !== null && $url !== '') {
                $license['url'] = $url;
                $references[] = ['type' => 'license', 'url' => $url];
            }
            $component['licenses'] = [['license' => $license]];
        }
        if ($references !== []) {
            $component['externalReferences'] = $references;
        }
        return $component;
    }

    /**
    * Builds package URLs for Composer, npm, Bower and NuGet inventory identities.
     * 
    * Includes entries default to Composer. Asset aliases resolve to their native registries.
     * 
     * @param BOMPackageInterface $package
     * @param array<string,mixed> $data
     * @return string|null
     */
    protected function buildPackageUrl(BOMPackageInterface $package, array $data) : ?string
    {
        $ecosystem = PackageEcosystemDataType::findEcosystem($package->getName(), $data['ecosystem'] ?? null);
        $name = PackageEcosystemDataType::convertNameFromAssetPackagist($package->getName(), $ecosystem);
        switch ($ecosystem) {
            case PackageEcosystemDataType::COMPOSER:
                $parts = explode('/', $name);
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    return null;
                }
                $name = strtolower($name);
                break;
            case PackageEcosystemDataType::NPM:
            case PackageEcosystemDataType::BOWER:
            case PackageEcosystemDataType::NUGET:
                break;
            default:
                return null;
        }
        $purl = 'pkg:' . $ecosystem . '/' . implode('/', array_map('rawurlencode', explode('/', $name)));
        $version = $package->getVersion();
        return $purl . ($version !== null && $version !== '' ? '@' . rawurlencode($version) : '');
    }
}