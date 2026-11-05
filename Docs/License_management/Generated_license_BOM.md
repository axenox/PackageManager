# Generated License BOM

## Summary

A license bill of materials (BOM) is a global list of the third-party software included in an installation, together with its versions, licenses, and available license texts.

Information about included software comes from different sources:

- **Composer-managed packages:** `composer.json` declares PHP dependencies and can also declare JavaScript packages from NPM and Bower through Composer's asset repositories. Composer records the resolved package information in `composer.lock`, which is used for license generation.
- **Bundled packages:** Developers may copy third-party files directly into an app instead of installing them through Composer. They must register these packages in an `includes.json` file in the app's root directory.
- **Package metadata supplements:** An app's `includes.json` can also add missing information or select a license for a Composer-installed package. A versionless entry enriches matching packages without declaring another bundled copy.
- **Installation-specific terms:** `config/Licenses.config.json` selects licenses and supplies private license definitions for one installation. It is optional and is merged after Composer and all app inventories.
- **Local npm manifests:** When a package has no known license declaration, its installed `package.json` supplies a fallback before license-text discovery. Modern `license` strings, legacy license objects and legacy `licenses` arrays are supported. License URLs in legacy entries are retained. Existing declarations and explicit selections are not replaced.

License generation combines these sources into one global list. App designers use this list to review the software delivered with their apps and prepare license information for distribution. The generated document supports that review; it is not a guarantee of completeness or legal compliance.

## Generate the List

Run the `axenox.PackageManager:GenerateLicenseBOM` action for the installation. From the installation directory, it can be called with:

```shell
vendor/bin/action axenox.PackageManager:GenerateLicenseBOM
```

By default, generation writes three files:

| File | Purpose |
| --- | --- |
| `vendor/Licenses.md` | A readable document listing packages grouped by the license used, with available license texts. |
| `vendor/licenses.json` | The combined package information, including all declared licenses and available license references. |
| `vendor/SBOM.cdx.json` | A CycloneDX 1.6 JSON software bill of materials for standards-based inventory and audit tools. |

Generation replaces existing output files and reports missing license information or texts. Review both the messages and the generated files before publishing them.

The [dependency audit's LicenseScanner](../Audit.md#licenses), when selected through
`AUDIT.SCANNERS` or the action's `scanners` property, checks this full JSON inventory for missing license declarations (medium severity by default)
and missing text for the effective license (low severity by default), including private packages.
Both severities are configurable; see the linked audit guide for scanner UXON properties.
A license URL alone does not satisfy the scanner's text check. Regenerate the inventory
before auditing. Current minimal exports preserve these checks using declarations and
resolution metadata without including license texts. File references and URLs alone do not
prove that terms were read; successful enrichment records availability.

To change the installation-wide defaults, configure PackageManager's `SBOM.files` option.
To override those defaults for one action, configure its `save_to_files` property using the
same file map. An action map replaces the defaults rather than extending them; an empty map
disables file output. Paths are relative to the installation directory; destination directories
must already exist.

```json
{
  "save_to_files": {
    "vendor/Licenses.md": {
      "class": "\\axenox\\PackageManager\\Common\\LicenseBOM\\Format\\MarkdownBOM"
    },
    "vendor/licenses.json": {
      "class": "\\axenox\\PackageManager\\Common\\LicenseBOM\\Format\\JsonBOM"
    },
    "vendor/SBOM.cdx.json": {
      "class": "\\axenox\\PackageManager\\Common\\LicenseBOM\\Format\\CycloneDxBOM"
    }
  }
}
```

Each file configuration requires an exporter `class`. Other properties are imported as UXON
configuration into that exporter after removing `class`. Unknown properties are rejected.
The JSON exporter supports the `format` option described below. Custom exporters must implement
`LicenseBOMExporterInterface`, accept the combined BOM in their constructor, and provide
`saveToFile()`. Use Core's `iCanBeConvertedToUxonTrait` to support configuration through setters.
PHP class namespaces start with `axenox`, not the filesystem directory `vendor`.

The old string format values (`markdown`, `json`, `cdx`) must be replaced with class configurations.
Regenerate the list whenever dependencies, bundled libraries, or license information change.

Manifest recovery reads the package's directory below `vendor`, also resolving native npm
names to Composer asset paths when necessary. It does not download packages or query npm.
Pure SPDX `AND` expressions expand into component licenses while retaining the complete
expression as the effective selection. Every component needs terms. Expressions containing
`OR` or `WITH` remain intact rather than being flattened into incorrect alternatives. Missing
manifests, absent declarations and `SEE LICENSE IN ...` references do not establish a license
name; malformed JSON fails generation. Shared named definitions apply to recovered license
names before license-text enrichment, so installation-specific metadata retains precedence.

### Markdown License Variants

Markdown groups texts under their effective license name. Texts that differ only in letter
case or whitespace layout share one variant and one reference in the package table. Line
endings, line wrapping, repeated spaces, tabs and nonbreaking spaces are ignored during
comparison. Word boundaries remain significant: separate words are not joined together.

The first original text encountered for a variant is printed unchanged. Copyright holders,
years, punctuation and license clauses are not removed or rewritten; changes to those values
still produce separate variants. This avoids discarding attribution or altered terms merely
because two packages use the same license name. Different effective license names remain
separate even when their texts match. JSON and CycloneDX retain their original package texts.

### Installation-Specific Licenses

Create `config/Licenses.config.json` in the installation, outside the app packages. It uses
the internal BOM structure: a `packages` array and an optional `licenses` array of named
license objects. List each private package with the same name used in `composer.lock`.
For example:

```json
{
  "packages": [
    {
      "name": "example/client-app",
      "ecosystem": "private",
      "license_used": "Client-License"
    },
    {
      "name": "example/dual-licensed-library",
      "license_used": "Apache-2.0"
    }
  ],
  "licenses": [
    {
      "name": "Client-License",
      "link": "https://example.org/client-license",
      "text": "The complete license terms agreed for this installation."
    }
  ]
}
```

Version, source and other omitted package fields remain available from Composer or includes.
An installation's explicit license selection takes precedence over app declarations. Declared
license objects are supplemented by name rather than array position; omitted licenses and
fields remain available. You only need `license_used` when selecting a license. If no declared
licenses exist after merging, exporters generate a named license object for that selection.
This fallback does not replace alternatives already declared by Composer or an app.
Setting `ecosystem: private` on a Composer package updates that package rather than adding a duplicate.

Both `config/Licenses.config.json` and app `includes.json` files accept this object structure.
Existing flat includes arrays remain supported. A file may contain only `licenses`, allowing
several apps to reference a shared license without repeating its terms. Packages reference a
definition using its exact name in a package's `licenses` array or `license_used`. Each definition
has `name` and optional `link`, `text` and `file` properties, just like per-package configuration.
`source` and `text_available` belong only to BOM results and are rejected in UXON configuration.
Configurable fields can be supplied independently. Later definitions
override matching fields of earlier definitions;
shared definitions override the corresponding per-package metadata for that named license.
Definitions are applied before missing license text is searched for locally or downloaded.

Full JSON exports retain the top-level `licenses` array and resolve its metadata onto packages
for existing consumers. Minimal exports omit definitions and terms, but retain resolved
license declarations, selections and resolution metadata. Markdown and
CycloneDX exports use the same resolved license terms. Do not publish generated license documents
without checking whether installation-specific terms may be disclosed.

### Private Packages And Audits

Use `ecosystem: private` for packages that must not be queried against public advisory registries.
They remain in the license inventory, including minimal JSON, but OSV and the npm adapter ignore
them without reporting missing-coverage findings. Private names in the combined inventory also
exclude matching entries from a parallel Composer lock. Regenerate the inventory after changing
the configuration. A lockfile alone does not carry these installation-specific exclusions.

Private packages receive no public package URL in CycloneDX. Instead, the generated component
carries the property `axenox:packagemanager:ecosystem` with value `private`, which the OSV adapter
also honors. This property is not a universal exclusion mechanism for third-party tools.
Composer's own audit and external scanners that do not consume the ecosystem classification
retain their own coverage and exclusion rules.

### JSON Export Format

`JsonBOM` accepts `format: full` (the default) or `format: minimal`. Both export a `packages`
array. Full exports retain supported package metadata and include `license_used`: the explicit
selection, otherwise the first declared license, or `Other` when none is declared. Each package's
`licenses` array contains named license objects rather than parallel name-keyed maps.

| License Property | Meaning |
| --- | --- |
| `name` | Required SPDX identifier, expression or custom license name. |
| `link` | URL referencing the terms; a URL alone does not establish available text. |
| `file` | Local terms reference relative to the installation's `vendor` directory. |
| `text` | Complete license text, including applicable attribution. |
| `source` | Result-only provenance recorded with text, such as `config`, `local`, `github` or `spdx`. Not configurable. |
| `text_available` | Result-only availability, retained even when a compact export omits the terms. Not configurable. |

`source` is provenance, not resolution success: a name or attempted lookup does not prove that
terms were obtained. Successful enrichers explicitly record their source when setting text.
Inline text records `config`; successfully reading a local file records `local`. Configuring
a path alone does not establish resolution or provenance. Designers cannot set either result field.
Legacy provenance values `embedded`, `link` and `missing` remain readable for archived inputs.
Concrete inline text controls availability, including a blank text that invalidates older
compact evidence. A link-only supplement does not erase previously resolved text or provenance.

Minimal exports retain `name`, `ecosystem`, `urn`, optional `version` and `bundled_in`, the
effective `license_used`, and `licenses` entries reduced to `name`, optional `source` and
`text_available`. Git sources with a nonempty reference also retain package-level `source.type`
and `source.reference` for commit auditing. This repository source object is distinct from a
license's provenance string. Undeclared packages do not acquire a synthetic `Other` declaration
or selection during compact export.

For example, a package selecting BSD terms while only MIT text is available carries:

```json
{
  "name": "example/library",
  "ecosystem": "composer",
  "license_used": "BSD-3-Clause",
  "licenses": [
    {"name": "MIT", "source": "spdx", "text_available": true},
    {"name": "BSD-3-Clause", "text_available": false}
  ]
}
```

These values record available evidence at export time, not legal validity or the contents
of the terms. Compact inventories can be re-exported without losing their source evidence.
License texts, links, file paths, shared definitions, descriptions, repository URLs,
non-Git source metadata, dependency requirements and other metadata are omitted.
Exporting does not change the combined inventory or other exporters' output.
Existing archived minimal inventories lack the new evidence and cannot be repaired by
re-auditing alone. Generate a fresh full inventory and build snapshot; do not substitute
today's installation metadata for a historical build's licenses.

Readers accept Composer's `license` declaration and legacy `license_text`, `license_link`,
`license_file` and `license_text_source` fields, including old shared name-keyed definitions.
Canonical license fields override corresponding legacy fields; definitions merge by name.
Exports emit only the new structure. No database or file migration is required. Old compact
`embedded`/`local` evidence remains usable; a discarded or unresolved text cannot be recovered
merely by recognizing its SPDX name. Archived audits use recorded resolution evidence rather
than checking file references on a different machine. An unqualified file reference is unresolved.

#### PHP UXON Prototypes

`LicenseBOM`, `BOMPackage` and `BOMPackageLicense` implement UXON conversion using Core's
`iCanBeConvertedToUxonTrait`. Their annotated setters define supported configuration;
class docs provide runnable configuration shapes. A package accepts legacy/Composer arrays
at construction, discarding unrelated Composer fields such as requirements, autoload and
transport metadata. Supported package fields are the properties listed below, with repository
`source.type`, `source.url` and `source.reference`. Supplied URNs are recalculated.

Use `toBOM(): array` for current inventory, package and license results instead of `toArray()`
or the removed `toComposerArray()` API. All three prototypes accept `?array $bom = null` in their
constructors to restore BOM results, including compact evidence. For designer configuration,
create an empty instance and call `importUxonObject()` separately; that method returns `void`.
Never pass includes or installation configuration to the result constructor. File readers use
`BOMPackage::normalizeArray()` to adapt legacy package fields before strict UXON import.
Values live in private class properties. The trait's
`exportUxonObject()` returns imported configuration, not a snapshot of runtime state;
selection, enrichment, additions and merging do not rewrite that configuration.
`exportUxonObject()->toArray()` therefore describes configuration, while `toBOM()` describes results.

```php
use axenox\PackageManager\Common\LicenseBOM\BOMPackageLicense;
use exface\Core\CommonLogic\UxonObject;

$recordedLicense = new BOMPackageLicense($savedLicense);
$configuredLicense = new BOMPackageLicense();
$configuredLicense->importUxonObject(new UxonObject([
  'name' => 'MIT',
  'file' => 'example/library/LICENSE'
]));
```

`getLicenseUsed()` retains effective selection; package merging and selection-aware completeness
checks remain available. `getLicenses()` returns live typed license objects keyed by name, including
components named only by an explicit selection. Selection-only objects remain stable while selected;
changing the selection does not retain previous unresolved placeholders as additional declarations.
Objects containing actual terms remain available as recorded evidence when the selection changes.
Read `getName()`, `getText()`, `getLink()`, `getFile()`, `getSource()` and `hasText()` on each license.
Package-level `getLicenseName()`, `getLicenseNames()`, `getLicenseText()`, `getLicenseLink()`,
`getLicenseFile()` and `getLicenseTextSource()` are not available.

Per-license enrichers iterate `foreach ($package->getLicenses() as $license)` and call
`$license->setText($text, $source)` on that same object when terms are obtained. `addLicense()`
supplies new typed declarations without configuration import. Source identifiers are producer-defined
strings passed with resolved text; there are no configurable setters for source or availability.
Custom enrichers are not restricted to built-in source names. JSON and CycloneDX serializers
read current state, including later changes to nested license objects.
Generic package-directory discovery only assigns an unqualified license file to a single selected
license, never to every alternative or every component of a conjunction. Per-license file references
and SPDX lookup can resolve those components independently. Markdown and CycloneDX explicitly
assemble selected component terms; missing component text is not replaced by another alternative.

Use this configuration in `save_to_files`, or use its file map as the `SBOM.files` option:

```json
{
  "save_to_files": {
    "vendor/packages.minimal.json": {
      "class": "\\axenox\\PackageManager\\Common\\LicenseBOM\\Format\\JsonBOM",
      "format": "minimal"
    }
  }
}
```

Both formats always emit lowercase `ecosystem` values and native package names.
For example, `npm-asset/openui5--sap.ui.core` becomes `@openui5/sap.ui.core` with
`ecosystem: npm`; `bower-asset/jquery` becomes `jquery` with `ecosystem: bower`.
Other packages default to `ecosystem: composer` unless explicitly configured.
Versions are retained unchanged, and metadata normalization does not mutate the source packages.
Every package has a generated `urn` in the form `ecosystem:package:version`, using its lowercase
ecosystem, native registry name and unchanged version, for example `npm:jquery:3.0.0`.
An unknown version leaves the final segment empty, such as `composer:example/library:`.
Supplied stale URNs are recalculated from package metadata. PHP package collections are keyed
by these URNs rather than package names.
PHP consumers obtain a package instance's current identity through `BOMPackageInterface::getUrn()`.
`BOMPackage::buildUrn()` builds the same identity from raw name, version and ecosystem values.

Versioned entries read from app `includes.json` files also receive `bundled_in`, the owning package's URN.
Generation uses the owner's installed version from `composer.lock`; without a lock entry, it
uses the local Composer manifest's name/version when available, otherwise the vendor directory
identity with an unknown version. Entries with an omitted, null or empty version do not receive
automatic bundle ownership: they can supplement existing packages without adding or replacing
their `bundled_in`. To register a genuinely bundled package whose version is unknown, supply
`bundled_in` explicitly. Installation-specific license configuration does not add automatic
bundle ownership either. Both full and minimal JSON retain these links. For bundled
components, CycloneDX exports the matching `axenox:packagemanager:urn` and
`axenox:packagemanager:bundled_in` properties. The Audit action reads this explicit
ownership evidence; they do not infer bundle ownership from component nesting.
The field is singular: if several apps declare the exact same package URN, their metadata merges
and the later `bundled_in` value replaces the earlier one.

PHP consumers can call `JsonBOM::buildJson()` on a configured exporter to obtain
the same JSON without writing a file, for example to archive a minimal build inventory.
The npm Audit adapter accepts both native npm names with `ecosystem: npm` and legacy
`npm-asset/scope--package` aliases. It removes a leading `v` from versions in its bulk request.

[OSV version queries](https://google.github.io/osv.dev/post-v1-query/) require a package name,
ecosystem and version (or a package URL). An adapter can build these queries from minimal package
identities using their ecosystem. The scanner maps `composer` to OSV's `Packagist`,
`nuget` to `NuGet`, and preserves the native npm identity. The inventory is not a
native OSV request. Bower packages require usable Git metadata for OSV coverage.
Unversioned packages remain listed, but cannot be used for version-based queries until their
versions are supplied. Minimal JSON is not a substitute for a license document or CycloneDX SBOM.

### CycloneDX SBOM

The `CycloneDxBOM` exporter uses the same combined and enriched package data as the license documents.
Each package becomes a library component with a stable `bom-ref`, its full package name, and
available version, description, repository and homepage references. The document includes a UTC
generation timestamp. Packages with missing metadata remain in the inventory; missing fields
are omitted.

Package URLs identify Composer, npm, Bower and NuGet packages using their ecosystems,
including native names and legacy asset aliases. Untyped `vendor/package` entries default
to Composer package URLs. Native NuGet entries receive `pkg:nuget` URLs, so standards-based
audit tools can resolve them too. Invalid Composer names without a vendor/package pair
do not receive a package URL. Other enum ecosystems currently use the combined JSON for OSV;
this exporter does not create PURLs for those registries.

For Bower auditing, OSV uses locked Git commit metadata rather than a Bower registry
query or an assumed npm alias. Minimal JSON inventories preserve the Git source type
and reference, so they can support commit queries without a Composer lock. OSV requires
a 40-character commit hash; retaining a branch name or short reference does not make it
queryable. Existing archived inventories are not automatically backfilled.

The effective license selection follows the rules below and is exported as a named license,
preserving custom labels and expressions without asserting an unverified SPDX ID. Available
license text is attached as base64-encoded plain text; license URLs are included as both license
metadata and external references. The fallback `Other` label is omitted when no license is known.

This is a flat package inventory, not a dependency graph or a vulnerability report. It does not
invent dependency edges, checksums, suppliers or a root application component. The source and
completeness limitations of license generation apply equally to the SBOM.

## Register Bundled Software

An app's `includes.json` lists third-party packages bundled with that app. Each entry needs a
package name. Use lowercase `ecosystem` values: `composer`, `npm`, `bower` or `nuget`.
Omission defaults to `composer`; legacy `npm-asset/` and `bower-asset/` names still identify
their respective registries. Native names require their ecosystem, such as `@scope/package`
with `npm` or `Example.Library` with `nuget`. Mixed-case ecosystem input is normalized;
unsupported values fail rather than silently defaulting to Composer.

Supply the version, description, source, and license information wherever possible so
reviewers can identify the component and its terms. Regenerate the BOM after changing
includes entries: audits read the generated inventory, not includes files directly.

For example, a library copied into an app's resources directory can be registered as follows:

```json
[
  {
    "name": "example/bundled-library",
    "version": "1.0.0",
    "description": "Bundled library",
    "source": {"url": "https://github.com/example/bundled-library"},
    "licenses": [{"name": "MIT", "file": "example/host-app/resources/bundled-library/LICENSE"}]
  }
]
```

The license's `file` path is relative to the installation's `vendor` directory, not to the app directory. Include the library's own license file and copyright notices when available.

Packages from other registries use native names, for example:

```json
[
  {"name": "@scope/package", "ecosystem": "npm", "version": "1.0.0"},
  {"name": "Example.Library", "ecosystem": "nuget", "version": "2.0.0"}
]
```

OSV scans versioned NuGet entries from the generated JSON inventory. The shared
`PackageEcosystemDataType` enum also accepts lowercase `maven`, `pypi`, `go`, `crates.io`,
`rubygems`, `hex`, `pub`, `swifturl`, `hackage`, `conancenter`, `cran` and `opam` identities
for OSV queries. An ecosystem being queryable does not guarantee complete advisory coverage.

### Package Information

| Field | Purpose |
| --- | --- |
| `name` | Native registry name, or a legacy Composer asset alias. |
| `ecosystem` | Lowercase package registry; defaults to `composer`. |
| `version` | Identifies the included release. |
| `urn` | Generated identity `ecosystem:package:version`, using the native name. |
| `bundled_in` | Owning package's URN, assigned automatically for versioned app includes entries. Versionless entries preserve existing ownership unless this field is supplied explicitly. |
| `description` | Explains what the component is. |
| `homepage` | Component's public homepage. |
| `source.type`, `source.url`, `source.reference` | Repository type, URL and locked revision. Other source fields are discarded. |
| `licenses` | Named license objects, preferably using standard SPDX identifiers such as `MIT` or `Apache-2.0`. |
| `license_used` | Explicitly selects the license used for this installation when necessary. |

## Supplement Existing Packages

An includes entry can also supplement a package already installed through Composer.
Use the same package identity and supply only the information that needs to be added or changed.
Omit `version` for a metadata-only supplement that must not claim bundle ownership.
Native npm/Bower names with their ecosystems match the corresponding Composer asset aliases.
The same name in different public ecosystems remains a separate package. The `private`
classification shares the Composer package identity so installation overrides can mark existing
Composer packages private.

For example, suppose Composer supplies a package's version and these licenses:

```json
{
  "name": "example/library",
  "version": "1.2.3",
  "license": ["GPL-2.0-only", "Apache-2.0"]
}
```

An app can supply the intended license selection and a license file without repeating that information:

```json
[
  {
    "name": "example/library",
    "license_used": "Apache-2.0",
    "licenses": [{"name": "Apache-2.0", "file": "example/library/LICENSE-APACHE"}]
  }
]
```

The global list retains version `1.2.3` and the original declared licenses, while using the additional license selection and file. Other information from Composer, such as the description and source, is also retained when the includes entry omits it. A versionless supplement applies to every matching installed version; specify a version to target only that version.

For example, an asset-packagist package can be installed correctly but lack license metadata.
Adding a versionless includes entry with its `name` and `license_used: MIT` selects MIT for the
existing package; it does not declare a separate installation or a copy bundled inside the app.
The installed version and its version-specific URN remain unchanged. A Composer-installed
package without `bundled_in` remains without it, and an already-bundled package retains its
original owner. This behavior applies to omitted, null and empty versions.

A supplement has an unknown-version URN while read separately, because all internal package
collections use URN keys. When merged into matching installed versions, its metadata is applied
to those entries; no additional unknown-version package is exported. Do not remove URNs from
versionless packages: a genuinely installed package can also have an unknown version.

PHP consumers distinguish the two declaration forms through `BOMPackageInterface`:
`isSeparatePackage()` returns true for a nonempty version, and `isSupplementalInfo()` returns
its complement for an omitted, null or empty version. Both methods reflect the current metadata:
an unversioned entry that adopts a known version during merging becomes a separate-package
declaration. These methods describe the version-based convention, not proof of installation
or the original source's intent. A version-specific metadata override also counts as a separate
declaration; an unknown-version package still counts as supplemental even when retained in the
inventory. Explicit `bundled_in` alone does not change this classification. The methods do not
change URN generation or exclude entries from exports.

During generation, all versioned bundles from app manifests and local license overrides are
registered before versionless supplements are applied. A supplement can therefore match a
package declared by a later manifest. Supplements then apply in manifest order, with local
license overrides last. A versionless entry with no matching installed or bundled package is
excluded from the generated inventory with a warning. Review such entries for misspelled names
or missing dependencies. Genuinely installed packages with unknown versions remain present;
this filtering applies to inclusion supplements, not installed Composer entries. Regenerate
the BOM to remove unmatched supplements from older inventories; archived build BOMs are unchanged.

Supplying a version in an app's includes manifest is also a bundled-package declaration for
automatic ownership, even when it matches an existing package. For a version-specific license
override without claiming bundle ownership, use `config/Licenses.config.json` instead.
An explicitly supplied `bundled_in` is metadata and follows the normal replacement rules.

The following rules apply when combining information about the same package:

- Supplied fields replace earlier values; omitted fields are preserved.
- License objects merge by name. Omitted names and fields remain available; an empty list does not erase earlier declarations.
- The last non-empty explicit `license_used` takes precedence. An omitted or empty selection preserves an earlier explicit selection.
- Later supplied fields override corresponding fields for the same license. A link-only supplement preserves resolved terms.

Avoid conflicting declarations for the same package URN across apps. Different known versions
or public ecosystems remain separate entries, even when package names match. Matching URNs
merge metadata. A previously unversioned entry adopts an incoming known version when merged.
The private classification can still replace the Composer classification for the same identity.

## License Selection

The readable document groups each package under one effective license:

- An explicit `license_used` takes precedence.
- Without an explicit selection, the first entry in `licenses` is used for display and license-text lookup. Exporters emit this effective selection without changing the source package.
- If neither a selection nor a declared license is available, the package is grouped under `Other`.

Choosing the first license is only a display default. Pure `AND` declarations expand into component
objects and preserve their complete expression as the selection; all components require terms.
`OR` and `WITH` expressions remain intact. Review the actual terms before choosing a license.

## License Texts

Generation looks for supplied texts, explicit per-license `file` references, supported raw GitHub
`link` URLs and standard SPDX license texts. Explicit file enrichment fills missing text; it does
not replace nonempty supplied terms.

For bundled software, an explicit path to its own license file is the most direct way to supply text.
For several licenses, assign the correct file to each named object. Automatic whole-file discovery
does not assign one generic file to every component of an AND expression. SPDX lookup attempts each
component separately, even when a URL already exists, and records `spdx` only on a successful
nonempty response. A recognized identifier alone does not satisfy the scanner.

A source repository URL alone does not supply a license text. Downloading remote texts requires network access. Standard license templates may not include the package's own copyright notices, so prefer the distributed license file when available.

## Review Before Distribution

1. Confirm that every bundled third-party component is registered in its app's `includes.json`.
2. Check package names and versions against the software actually delivered.
3. Review explicit license selections, especially packages with multiple declared licenses.
4. Confirm that the readable document contains the expected license texts and copyright notices. A reference URL or a clean generation report does not prove that the text is included.
5. Resolve missing-information warnings with the app developer and confirm that all configured output files were written successfully.

The generated list covers resolved production dependencies from `composer.lock` and includes manifests at the root of installed apps under `vendor`. Development dependencies, the installation's root package, and unregistered or externally stored components are not automatically included. Consider these boundaries when preparing the license documentation for a release.