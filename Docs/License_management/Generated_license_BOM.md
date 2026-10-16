# Generated License BOM

## Summary

A license bill of materials (BOM) is a global list of the third-party software included in an installation, together with its versions, licenses, and available license texts.

Information about included software comes from different sources:

- **Composer-managed packages:** `composer.json` declares PHP dependencies and can also declare JavaScript packages from NPM and Bower through Composer's asset repositories. Composer records the resolved package information in `composer.lock`, which is used for license generation.
- **Bundled packages:** Developers may copy third-party files directly into an app instead of installing them through Composer. They must register these packages in an `includes.json` file in the app's root directory.
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

The default [dependency audit](../Audit.md#licenses) also includes `LicenseScanner`.
It checks this full JSON inventory for missing license declarations (high severity)
and missing text for the effective license (low severity), including private packages.
A license URL alone does not satisfy the scanner's text check. Regenerate the inventory
before auditing; minimal exports deliberately omit the evidence needed for these checks.

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
SPDX expressions remain intact rather than being split into separate alternatives. Missing
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
the internal BOM structure: a `packages` array and an optional `licenses` object keyed by
license name. List each private package with the same name used in `composer.lock`.
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
  "licenses": {
    "Client-License": {
      "license_link": "https://example.org/client-license",
      "license_text": "The complete license terms agreed for this installation."
    }
  }
}
```

Version, source and other omitted package fields remain available from Composer or includes.
An installation's explicit license selection takes precedence over app declarations. Declared
license alternatives remain unchanged unless the configuration supplies a nonempty `license` list.
You only need `license_used` when selecting a license. If no nonempty `license` list is available
after merging, its effective value is generated as `[license_used]`, including in full JSON exports.
This fallback does not replace alternatives already declared by Composer or an app.
Setting `ecosystem: private` on a Composer package updates that package rather than adding a duplicate.

Both `config/Licenses.config.json` and app `includes.json` files accept this object structure.
Existing flat includes arrays remain supported. A file may contain only `licenses`, allowing
several apps to reference a shared license without repeating its terms. Packages reference a
definition using its exact name in `license` or `license_used`. The definition's `license_link`
and `license_text` are strings, unlike the per-package maps keyed by license name. These fields
can be supplied independently. Later definitions override matching fields of earlier definitions;
shared definitions override the corresponding per-package metadata for that named license.
Definitions are applied before missing license text is searched for locally or downloaded.

Full JSON exports retain the top-level `licenses` object and resolve its metadata onto packages
for existing consumers. Minimal exports omit definitions and license metadata. Markdown and
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

`JsonBOM` accepts `format: full` (the default) or `format: minimal`. Full exports preserve all
combined package metadata and include `license_used` with the effective license: the explicit
selection when configured, otherwise the first declared license, or `Other` when none is declared.
The `license` array still contains all declared alternatives.
Minimal exports keep the same `packages` array and retain each
package's `name`, `ecosystem` and its `version` when present. Git sources with a nonempty
reference also retain `source.type` and `source.reference` for commit-based auditing.
License declarations, selected licenses, license texts, descriptions, repository URLs,
non-Git source metadata, dependency requirements and other metadata are omitted.
Exporting does not change the combined inventory or other exporters' output.

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
    "license": ["MIT"],
    "license_file": "example/host-app/resources/bundled-library/LICENSE"
  }
]
```

The `license_file` path is relative to the installation's `vendor` directory, not to the app directory. Include the library's own license file and copyright notices when available.

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
| `description` | Explains what the component is. |
| `source.url` | Points to the component's source repository. |
| `license` | Lists the declared licenses, preferably using standard SPDX identifiers such as `MIT` or `Apache-2.0`. |
| `license_used` | Explicitly selects the license used for this installation when necessary. |
| `license_file` | Points to a local license file relative to `vendor`. |
| `license_link` | Provides license reference URLs, grouped by license name. |
| `license_text` | Provides complete license texts, grouped by license name. |

## Supplement Existing Packages

An includes entry can also supplement a package already installed through Composer.
Use the same package identity and supply only the information that needs to be added or changed.
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
    "license_file": "example/library/LICENSE-APACHE"
  }
]
```

The global list retains version `1.2.3` and the original declared licenses, while using the additional license selection and file. Other information from Composer, such as the description and source, is also retained when the includes entry omits it.

The following rules apply when combining information about the same package:

- Supplied fields replace earlier values; omitted fields are preserved.
- An empty `license` list does not erase previously declared licenses. A non-empty list replaces the previous list.
- The last non-empty explicit `license_used` takes precedence. An omitted or empty selection preserves an earlier explicit selection.
- If you supply grouped information such as `license_text` or `license_link`, supply the complete map you want to retain; it replaces the earlier map.

Avoid conflicting declarations for the same package across apps. Different versions of a package with the same name are not listed separately.

## License Selection

The readable document groups each package under one effective license:

- An explicit `license_used` takes precedence.
- Without an explicit selection, the first entry in `license` is used for display and license-text lookup. This does not add a selection to the JSON package information.
- If neither a selection nor a declared license is available, the package is grouped under `Other`.

Choosing the first license is only a display default. It does not determine whether multiple licenses are alternatives or must be satisfied together. Review the package's actual terms before choosing a license, and ensure the selected license agrees with the license text supplied.

## License Texts

Generation looks for license texts supplied with packages, supported raw GitHub license links, and standard SPDX license texts. An explicitly configured local `license_file` takes precedence over automatically found text.

For bundled software, an explicit path to its own license file is the most direct way to supply the text. For a package with several license files, use a single path to the file matching the selected license; do not rely on the order of a license-file map.

A source repository URL alone does not supply a license text. Downloading remote texts requires network access. Standard license templates may not include the package's own copyright notices, so prefer the distributed license file when available.

## Review Before Distribution

1. Confirm that every bundled third-party component is registered in its app's `includes.json`.
2. Check package names and versions against the software actually delivered.
3. Review explicit license selections, especially packages with multiple declared licenses.
4. Confirm that the readable document contains the expected license texts and copyright notices. A reference URL or a clean generation report does not prove that the text is included.
5. Resolve missing-information warnings with the app developer and confirm that all configured output files were written successfully.

The generated list covers resolved production dependencies from `composer.lock` and includes manifests at the root of installed apps under `vendor`. Development dependencies, the installation's root package, and unregistered or externally stored components are not automatically included. Consider these boundaries when preparing the license documentation for a release.