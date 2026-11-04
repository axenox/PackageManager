<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Describe the terms and provenance of one license in a package or shared license registry.
 *
 * Use name to identify the license, text or file for its terms, and link for a reference.
 * Enrichers record provenance and availability in the resulting BOM, not in configuration.
 *
 * ## Examples
 *
 * ### Reference a distributed license file
 *
 * File paths are relative to the installation's vendor folder.
 *
 * ```
 *
 * {
 *     "name": "MIT",
 *     "file": "example/library/LICENSE",
 *     "link": "https://example.org/library/license"
 * }
 *
 * ```
 *
 * ### Supply installation-specific terms
 *
 * ```
 *
 * {
 *     "name": "Commercial",
 *     "text": "The complete license terms agreed for this installation."
 * }
 *
 * ```
 *
 */
class BOMPackageLicense implements iCanBeConvertedToUxon
{
    use iCanBeConvertedToUxonTrait;

    private $name = '';

    private $text = null;

    private $link = null;

    private $file = null;

    private $source = null;

    private $textAvailable = null;

    /**
     * Restores recorded evidence from BOM output without exposing it as designer configuration.
     *
     * Concrete text controls availability when both text and compact evidence are present.
     *
     * @param array<string,mixed>|null $bom
     */
    public function __construct(?array $bom = null)
    {
        $data = $bom ?? [];
        if (array_key_exists('source', $data) && ! is_string($data['source'])) {
            throw new InvalidArgumentException('A BOM license source must be a string.');
        }
        if (array_key_exists('text_available', $data) && ! is_bool($data['text_available'])) {
            throw new InvalidArgumentException('BOM license text_available must be a boolean.');
        }
        $configuration = $data;
        unset($configuration['source'], $configuration['text_available']);
        $this->importUxonObject(new UxonObject($configuration));
        $this->source = $data['source'] ?? $this->source;
        if (! array_key_exists('text', $data)) {
            $this->textAvailable = $data['text_available'] ?? null;
        }
    }

    /**
     * The name of the license - either an SPDX identifier or a custom license name.
     *
     * @uxon-property name
     * @uxon-type string
     * @uxon-required true
     * @param string $name
     * @return void
     */
    protected function setName(string $name) : void
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A BOM license must have a nonempty name.');
        }
        $this->name = $name;
    }

    /**
     * Returns the identifier used to merge license evidence.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * Supply the license terms and record where they were obtained.
     *
     * @uxon-property text
     * @uxon-type string
     * @param string $text
     * @param string $source
     * @return self
     */
    public function setText(string $text, string $source = 'config') : self
    {
        $this->text = $text;
        $this->source = $source;
        $this->textAvailable = trim($text) !== '';
        return $this;
    }

    /**
     * Returns terms when this inventory retains their contents.
     *
     * @return string|null
     */
    public function getText() : ?string
    {
        return $this->text;
    }

    /**
     * Reference the page or download containing the license terms.
     *
     * @uxon-property link
     * @uxon-type uri
     * @param string $link
     * @return void
     */
    protected function setLink(string $link) : void
    {
        $this->link = $link;
    }

    /**
     * Returns a reference without claiming it has been downloaded.
     *
     * @return string|null
     */
    public function getLink() : ?string
    {
        return $this->link;
    }

    /**
     * Reference a license file relative to the installation's vendor folder.
     *
     * @uxon-property file
     * @uxon-type string
     * @param string $file
     * @return void
     */
    protected function setFile(string $file) : void
    {
        $this->file = $file;
    }

    /**
     * Returns the local terms reference retained by the inventory.
     *
     * @return string|null
     */
    public function getFile() : ?string
    {
        return $this->file;
    }

    /**
     * Returns recorded provenance without inferring successful resolution from a name.
     *
     * @return string|null
     */
    public function getSource() : ?string
    {
        return $this->source;
    }

    /**
     * Distinguishes resolved terms from an unresolved declaration or URL.
     *
     * @return bool
     */
    public function hasText() : bool
    {
        return $this->textAvailable ?? false;
    }

    /**
     * Serializes current evidence without changing the imported UXON configuration.
     *
     * @return array<string,mixed>
     */
    public function toBOM() : array
    {
        return array_filter([
            'name' => $this->name,
            'text' => $this->text,
            'link' => $this->link,
            'file' => $this->file,
            'source' => $this->source,
            'text_available' => $this->textAvailable
        ], static function ($value) : bool {
            return $value !== null;
        });
    }

    /**
     * Supplements metadata without replacing resolved provenance with a link-only declaration.
     *
     * @param self $other
     * @return self
     */
    public function merge(self $other) : self
    {
        if ($this->getName() !== $other->getName()) {
            throw new InvalidArgumentException('Cannot merge BOM licenses with different names.');
        }
        if ($other->text !== null || $other->hasText() || ! $this->hasText()) {
            $this->source = $other->source ?? $this->source;
            $this->textAvailable = $other->textAvailable ?? $this->textAvailable;
        }
        $this->text = $other->text ?? $this->text;
        $this->link = $other->link ?? $this->link;
        $this->file = $other->file ?? $this->file;
        return $this;
    }
}