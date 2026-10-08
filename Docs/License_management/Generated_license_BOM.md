# Generated License BOM

## Summary

A license bill of materials (BOM) is a global list of the third-party software included in an installation, together with its versions, licenses, and available license texts.

Information about included software comes from different sources:

- **Composer-managed packages:** `composer.json` declares PHP dependencies and can also declare JavaScript packages from NPM and Bower through Composer's asset repositories. Composer records the resolved package information in `composer.lock`, which is used for license generation.
- **Bundled packages:** Developers may copy third-party files directly into an app instead of installing them through Composer. They must register these packages in an `includes.json` file in the app's root directory.

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

### JSON Export Format

`JsonBOM` accepts `format: full` (the default) or `format: minimal`. Full exports preserve all
combined package metadata. Minimal exports keep the same `packages` array but retain only each
package's `name` and its `version` when present. License declarations, selected licenses, license
texts, descriptions, source references, dependency requirements and other metadata are omitted.
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

Minimal exports retain original names and versions, including Composer's `npm-asset` aliases.
The npm Audit adapter uses these two fields, translating `npm-asset/scope--package` to
`@scope/package` and removing a leading `v` from the version when constructing its bulk request.

[OSV version queries](https://google.github.io/osv.dev/post-v1-query/) require a package name,
ecosystem and version (or a package URL). An adapter can build these queries from minimal package
identities when it knows their ecosystem, for example `Packagist` for Composer packages or `npm`
for npm assets. The inventory is not a native OSV request and does not invent ecosystems for
untyped bundled packages. Such packages require ecosystem information from the calling context.
Unversioned packages remain listed, but cannot be used for version-based queries until their
versions are supplied. Minimal JSON is not a substitute for a license document or CycloneDX SBOM.

### CycloneDX SBOM

The `CycloneDxBOM` exporter uses the same combined and enriched package data as the license documents.
Each package becomes a library component with a stable `bom-ref`, its full package name, and
available version, description, repository and homepage references. The document includes a UTC
generation timestamp. Packages with missing metadata remain in the inventory; missing fields
are omitted.

Package URLs identify `npm-asset` and `bower-asset` packages using their original ecosystems,
including scoped npm names. Other `vendor/package` names with Composer's `type` metadata receive
Composer package URLs. Untyped bundled entries do not receive an ecosystem identifier. Do not
add Composer `type` metadata to an includes entry unless it represents a Composer package.

The effective license selection follows the rules below and is exported as a named license,
preserving custom labels and expressions without asserting an unverified SPDX ID. Available
license text is attached as base64-encoded plain text; license URLs are included as both license
metadata and external references. The fallback `Other` label is omitted when no license is known.

This is a flat package inventory, not a dependency graph or a vulnerability report. It does not
invent dependency edges, checksums, suppliers or a root application component. The source and
completeness limitations of license generation apply equally to the SBOM.

## Register Bundled Software

An app's `includes.json` lists third-party packages bundled with that app. Each entry needs a package name. Supply the version, description, source, and license information wherever possible so reviewers can identify the component and its terms.

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

### Package Information

| Field | Purpose |
| --- | --- |
| `name` | Identifies the package, normally as `vendor/package`. Use exactly the same spelling when supplementing an existing package. |
| `version` | Identifies the included release. |
| `description` | Explains what the component is. |
| `source.url` | Points to the component's source repository. |
| `license` | Lists the declared licenses, preferably using standard SPDX identifiers such as `MIT` or `Apache-2.0`. |
| `license_used` | Explicitly selects the license used for this installation when necessary. |
| `license_file` | Points to a local license file relative to `vendor`. |
| `license_link` | Provides license reference URLs, grouped by license name. |
| `license_text` | Provides complete license texts, grouped by license name. |

## Supplement Existing Packages

An includes entry can also supplement a package already installed through Composer. Use the same package name and supply only the information that needs to be added or changed.

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