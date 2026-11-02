<?php
namespace axenox\PackageManager\Common\LicenseBOM;

use axenox\PackageManager\Interfaces\BOMPackageInterface;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;

/**
 * Describe a software package and the licenses applicable to its distribution.
 *
 * Set name, ecosystem and version to identify a package. Define licenses as license objects
 * and license_used to select the applicable terms. Versionless entries supplement installed
 * packages. Composer declarations and legacy license properties remain accepted on array input.
 *
 * ## Examples
 *
 * ### Register a bundled library
 *
 * ```
 *
 * {
 *     "name": "example-library",
 *     "ecosystem": "npm",
 *     "version": "1.2.3",
 *     "license_used": "MIT",
 *     "licenses": [{"name": "MIT", "file": "example/app/js/example-library/LICENSE"}],
 *     "source": {"type": "git", "url": "https://example.org/library.git", "reference": "v1.2.3"}
 * }
 *
 * ```
 *
 * ### Require both font and code licenses
 *
 * Pure AND expressions expand into component licenses; both terms must be available.
 *
 * ```
 *
 * {
 *     "name": "example-icons",
 *     "ecosystem": "npm",
 *     "version": "1.0.0",
 *     "licenses": [{"name": "(OFL-1.1 AND MIT)"}]
 * }
 *
 * ```
 *
 */
class BOMPackage implements BOMPackageInterface
{
    use iCanBeConvertedToUxonTrait;

    private $name = '';

    private $version = null;

    private $description = null;

    private $homepage = null;

    private $ecosystem = null;

    private $bundledIn = null;

    private $sourceType = null;

    private $sourceUrl = null;

    private $sourceReference = null;

    private $licenseUsed = null;

    private $licenses = [];

    private $selectionLicenses = [];

    private $legacyLicenseFile = null;

    /**
     * Builds the same version-specific identity for inventories and audit findings.
     * 
     * @param string $name
     * @param string|null $version
     * @param string|null $ecosystem
     * @return string
     */
    public static function buildUrn(string $name, ?string $version = null, ?string $ecosystem = null) : string
    {
        $ecosystem = PackageEcosystemDataType::findEcosystem($name, $ecosystem);
        $name = PackageEcosystemDataType::convertNameFromAssetPackagist($name, $ecosystem);
        return $ecosystem . ':' . $name . ':' . ($version ?? '');
    }

    /**
     * Restores result metadata through the BOM boundary rather than configurable UXON setters.
     *
     * Accepts Composer and historical BOM fields, discarding unrelated manifest metadata.
     * Designer configuration is imported separately with importUxonObject().
     *
     * @param array<string,mixed>|null $bom
     */
    public function __construct(?array $bom = null)
    {
        $data = $bom ?? [];
        if (is_string($data['license_file'] ?? null)) {
            $this->legacyLicenseFile = $data['license_file'];
        }
        $normalized = self::normalizeArray($data);
        $licenses = $normalized['licenses'] ?? [];
        unset($normalized['licenses']);
        $this->importUxonObject(new UxonObject($normalized));
        foreach ($licenses as $license) {
            $this->addLicense(new BOMPackageLicense($license));
        }
    }

    /**
    * Normalizes historical inventories and configuration without dropping recorded license evidence.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function normalizeArray(array $input) : array
    {
        $data = array_intersect_key($input, array_flip([
            'name', 'version', 'description', 'homepage', 'ecosystem', 'bundled_in', 'license_used', 'source'
        ]));
        $licenses = [];
        $declarations = $input['license'] ?? [];
        $declarations = is_array($declarations) ? $declarations : [$declarations];
        foreach ($declarations as $declaration) {
            foreach (self::splitLicenseNames($declaration) as $name) {
                $licenses[$name] = ['name' => $name];
            }
        }
        if (! isset($data['license_used']) && $declarations !== [] && count(self::splitLicenseNames($declarations[0])) > 1) {
            $data['license_used'] = $declarations[0];
        }
        foreach (['license_text' => 'text', 'license_link' => 'link', 'license_file' => 'file'] as $old => $new) {
            $values = $input[$old] ?? [];
            if (is_string($values)) {
                $values = [($data['license_used'] ?? array_key_first($licenses) ?? 'Other') => $values];
            }
            foreach ($values as $name => $value) {
                if ($value !== null) {
                    $licenses[$name] = array_replace($licenses[$name] ?? ['name' => $name], [$new => $value]);
                }
            }
        }
        foreach ($input['license_text_source'] ?? [] as $name => $source) {
            $licenses[$name] = array_replace(['name' => $name], $licenses[$name] ?? []);
            if (! isset($licenses[$name]['text'])) {
                $licenses[$name]['source'] = $source;
                $licenses[$name]['text_available'] = in_array($source, ['embedded', 'local'], true);
            }
        }
        foreach ($input['licenses'] ?? [] as $license) {
            $name = $license['name'];
            $licenses[$name] = array_replace($licenses[$name] ?? [], $license);
        }
        if ($licenses !== []) {
            $data['licenses'] = array_values($licenses);
        }
        return $data;
    }

    /**
     * Expands conjunctions without converting alternative or exception expressions into choices.
     *
     * @param string $expression
     * @return string[]
     */
    public static function splitLicenseNames(string $expression) : array
    {
        if (trim($expression) === '') {
            return [];
        }
        if (preg_match('/\b(?:OR|WITH)\b/', $expression)) {
            return [$expression];
        }
        return array_values(array_unique(array_map(static function (string $name) : string {
            return trim($name, " \t\r\n()");
        }, preg_split('/\s+AND\s+/', $expression))));
    }

    /**
     * Combines package supplements while retaining independently collected license evidence.
     *
     * {@inheritDoc}
     *
     * @see BOMPackageInterface::merge()
     */
    public function merge(BOMPackageInterface $otherPackage) : BOMPackageInterface
    {
        if (strcasecmp(PackageEcosystemDataType::buildPackageKey($this->getName(), $this->getEcosystem()),
            PackageEcosystemDataType::buildPackageKey($otherPackage->getName(), $otherPackage->getEcosystem())) !== 0) {
            throw new RuntimeException('Cannot merge license BOM packages with different identities.');
        }
        if (($this->getVersion() ?? '') !== '' && ($otherPackage->getVersion() ?? '') !== ''
            && $this->getVersion() !== $otherPackage->getVersion()) {
            throw new RuntimeException('Cannot merge license BOM packages with different versions.');
        }
        $incoming = $otherPackage->toBOM();
        unset($incoming['urn'], $incoming['licenses']);
        foreach (['version', 'license_used'] as $property) {
            if (($incoming[$property] ?? '') === '') {
                unset($incoming[$property]);
            }
        }
        if (isset($incoming['source'])) {
            $incoming['source'] = new UxonObject(array_replace($this->toBOM()['source'] ?? [], $incoming['source']));
        }
        foreach ([
            'name' => 'setName', 'ecosystem' => 'setEcosystem', 'version' => 'setVersion',
            'description' => 'setDescription', 'homepage' => 'setHomepage', 'bundled_in' => 'setBundledIn',
            'source' => 'setSource', 'license_used' => 'setLicenseUsed'
        ] as $property => $setter) {
            if (array_key_exists($property, $incoming)) {
                $this->$setter($incoming[$property]);
            }
        }
        foreach ($otherPackage->getLicenses() as $license) {
            $this->addLicense($license);
        }
        return $this;
    }

    /**
     * Identify the package using its registry name.
     *
     * @uxon-property name
     * @uxon-type string
     * @uxon-required true
     * @param string $name
     * @return void
     */
    protected function setName(string $name) : void
    {
        $this->name = $name;
    }

    /**
     * Returns the package name used by inventory consumers.
     *
     * @return string
     */
    public function getName() : string
    {
        return $this->name;
    }

    /**
     * Select the registry owning the package identity.
     *
     * @uxon-property ecosystem
     * @uxon-type string
     * @param string $ecosystem
     * @return void
     */
    protected function setEcosystem(string $ecosystem) : void
    {
        $this->ecosystem = $ecosystem;
    }

    /**
     * Resolves asset aliases consistently with package URNs.
     *
     * @return string
     */
    public function getEcosystem() : string
    {
        return PackageEcosystemDataType::findEcosystem($this->getName(), $this->ecosystem);
    }

    /**
     * Specify the installed version, or omit it for a metadata supplement.
     *
     * @uxon-property version
     * @uxon-type string
     * @param string|null $version
     * @return void
     */
    protected function setVersion(?string $version) : void
    {
        $this->version = $version;
    }

    /**
     * Returns the installed version without inventing one for supplements.
     *
     * @return string|null
     */
    public function getVersion() : ?string
    {
        return $this->version;
    }

    /**
     * Describe the included software for published inventories.
     *
     * @uxon-property description
     * @uxon-type string
     * @param string|null $description
     * @return void
     */
    protected function setDescription(?string $description) : void
    {
        $this->description = $description;
    }

    /**
     * Returns optional descriptive metadata.
     *
     * @return string|null
     */
    public function getDescription() : ?string
    {
        return $this->description;
    }

    /**
     * Reference the package website.
     *
     * @uxon-property homepage
     * @uxon-type uri
     * @param string $homepage
     * @return void
     */
    protected function setHomepage(string $homepage) : void
    {
        $this->homepage = $homepage;
    }

    /**
     * Identify the package containing this bundled library by its URN.
     *
     * @uxon-property bundled_in
     * @uxon-type string
     * @param string $urn
     * @return void
     */
    protected function setBundledIn(string $urn) : void
    {
        $this->bundledIn = $urn;
    }

    /**
     * Describe the source repository with type, url and reference fields.
     *
     * @uxon-property source
     * @uxon-type object
     * @uxon-template {"type":"git","url":"","reference":""}
     * @param UxonObject $source
     * @return void
     */
    protected function setSource(UxonObject $source) : void
    {
        $this->sourceType = null;
        $this->sourceUrl = null;
        $this->sourceReference = null;
        foreach (['type' => 'setSourceType', 'url' => 'setSourceUrl', 'reference' => 'setSourceReference'] as $key => $setter) {
            if ($source->getProperty($key) !== null) {
                $this->$setter($source->getProperty($key));
            }
        }
    }

    /**
     * Retains the VCS kind needed to interpret archived references.
     *
     * @param string $type
     * @return void
     */
    protected function setSourceType(string $type) : void
    {
        $this->sourceType = $type;
    }

    /**
     * Retains the repository URL without importing Composer transport metadata.
     *
     * @param string $url
     * @return void
     */
    protected function setSourceUrl(string $url) : void
    {
        $this->sourceUrl = $url;
    }

    /**
     * Retains the exact Git reference for commit-based advisory lookups.
     *
     * @param string $reference
     * @return void
     */
    protected function setSourceReference(string $reference) : void
    {
        $this->sourceReference = $reference;
    }

    /**
     * Returns the repository URL for existing exporter APIs.
     *
     * @return string|null
     */
    public function getSource() : ?string
    {
        return $this->sourceUrl;
    }

    /**
     * Define license objects; pure AND expressions expand into required component licenses.
     *
     * @uxon-property licenses
     * @uxon-type \axenox\PackageManager\Common\LicenseBOM\BOMPackageLicense[]
     * @uxon-template [{"name":"MIT"}]
     * @param UxonObject $licenses
     * @return void
     */
    protected function setLicenses(UxonObject $licenses) : void
    {
        foreach ($licenses as $definition) {
            $license = new BOMPackageLicense();
            $license->importUxonObject($definition);
            $this->addLicense($license);
        }
    }

    /**
     * Adds runtime evidence without rewriting the package's imported configuration.
     *
     * @param BOMPackageLicense $license
     * @return BOMPackageInterface
     */
    public function addLicense(BOMPackageLicense $license) : BOMPackageInterface
    {
        $names = self::splitLicenseNames($license->getName());
        if (count($names) > 1 && $this->licenseUsed === null) {
            $this->setLicenseUsed($license->getName());
        }
        foreach ($names as $name) {
            $data = $license->toBOM();
            $data['name'] = $name;
            $component = new BOMPackageLicense($data);
            $existing = $this->licenses[$name] ?? $this->selectionLicenses[$name] ?? null;
            if ($existing !== null) {
                $existing->merge($component);
                $this->licenses[$name] = $existing;
            } else {
                $this->licenses[$name] = $component;
            }
            unset($this->selectionLicenses[$name]);
        }
        return $this;
    }

    /**
    * Returns live license objects, including required components supplied only by the selection.
    *
    * Selection-only objects remain distinct from declarations so changing the selection does
    * not retain unresolved placeholders as additional licensing alternatives.
     *
     * @return BOMPackageLicense[]
     */
    public function getLicenses() : array
    {
        foreach (self::splitLicenseNames($this->licenseUsed ?? '') as $name) {
            if ($name !== 'Other' && ! isset($this->licenses[$name]) && ! isset($this->selectionLicenses[$name])) {
                $this->selectionLicenses[$name] = new BOMPackageLicense(['name' => $name]);
            }
        }
        return $this->licenses + $this->selectionLicenses;
    }

    /**
     * Select the applicable license or conjunction without erasing declared alternatives.
     *
     * @uxon-property license_used
     * @uxon-type string
     * @param string|null $name
     * @return BOMPackageInterface
     */
    public function setLicenseUsed(?string $name) : BOMPackageInterface
    {
        foreach ($this->selectionLicenses as $licenseName => $license) {
            if ($license->getText() !== null || $license->hasText()) {
                $this->licenses[$licenseName] = $license;
                unset($this->selectionLicenses[$licenseName]);
            }
        }
        $this->selectionLicenses = array_intersect_key(
            $this->selectionLicenses,
            array_flip(self::splitLicenseNames($name ?? ''))
        );
        if (($name ?? '') === '') {
            $this->licenseUsed = null;
        } else {
            $this->licenseUsed = $name;
            if ($this->legacyLicenseFile !== null) {
                foreach (self::splitLicenseNames($name) as $component) {
                    if (! isset($this->licenses[$component])) {
                        $this->licenses[$component] = new BOMPackageLicense([
                            'name' => $component, 'file' => $this->legacyLicenseFile
                        ]);
                    }
                }
            }
        }
        return $this;
    }

    /**
     * Defaults to the first declared license when no explicit selection was supplied.
     *
     * @return string|null
     */
    public function getLicenseUsed() : ?string
    {
        return $this->licenseUsed ?? (array_key_first($this->licenses) ?? 'Other');
    }

    /**
     * Returns every license required by the effective conjunction.
     *
     * @return string[]
     */
    public function getSelectedLicenseNames() : array
    {
        return self::splitLicenseNames($this->getLicenseUsed() ?? '');
    }

    /**
     * Records enricher provenance at the moment terms are obtained.
     *
     * @param string $licenseName
     * @param string $text
     * @param string $source
     * @return BOMPackageInterface
     */
    public function setLicenseText(string $licenseName, string $text, string $source = 'config') : BOMPackageInterface
    {
        if (! isset($this->licenses[$licenseName])) {
            $this->licenses[$licenseName] = $this->getLicenses()[$licenseName] ?? new BOMPackageLicense(['name' => $licenseName]);
        }
        $this->licenses[$licenseName]->setText($text, $source);
        return $this;
    }

    /**
     * Distinguishes an actual declaration from an unidentified license.
     *
     * @return bool
     */
    public function hasLicense() : bool
    {
        return $this->getLicenses() !== [] && $this->getLicenseUsed() !== 'Other';
    }

    /**
     * Requires resolved terms for every selected AND component, not just its first name.
     *
     * @return bool
     */
    public function hasLicenseText() : bool
    {
        $licenses = $this->getLicenses();
        foreach ($this->getSelectedLicenseNames() as $name) {
            if (! isset($licenses[$name]) || ! $licenses[$name]->hasText()) {
                return false;
            }
        }
        return $this->hasLicense();
    }

    /**
     * Computes inventory identity from the current package properties.
     *
     * @return string
     */
    public function getUrn() : string
    {
        return self::buildUrn($this->getName(), $this->getVersion(), $this->getEcosystem());
    }

    /**
     * Distinguishes a versioned declaration from supplemental metadata.
     *
     * @return bool
     */
    public function isSeparatePackage() : bool
    {
        return ($this->getVersion() ?? '') !== '';
    }

    /**
     * Identifies metadata that applies to any installed version of the package.
     *
     * @return bool
     */
    public function isSupplementalInfo() : bool
    {
        return ! $this->isSeparatePackage();
    }

    /**
     * Serializes the current package and evidence separately from imported configuration.
     *
     * @return array<string,mixed>
     */
    public function toBOM() : array
    {
        $data = array_filter([
            'name' => $this->name,
            'ecosystem' => $this->ecosystem,
            'version' => $this->version,
            'description' => $this->description,
            'homepage' => $this->homepage,
            'bundled_in' => $this->bundledIn,
            'license_used' => $this->licenseUsed
        ], static function ($value) : bool {
            return $value !== null;
        });
        $source = array_filter([
            'type' => $this->sourceType,
            'url' => $this->sourceUrl,
            'reference' => $this->sourceReference
        ], static function ($value) : bool {
            return $value !== null;
        });
        if ($source !== []) {
            $data['source'] = $source;
        }
        $data['licenses'] = array_values(array_map(static function (BOMPackageLicense $license) : array {
            return $license->toBOM();
        }, $this->getLicenses()));
        $data['urn'] = $this->getUrn();
        return $data;
    }
}