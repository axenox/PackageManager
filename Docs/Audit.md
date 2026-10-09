# Vulnerability Audits

The `axenox.PackageManager.Audit` action combines Composer, npm-asset, OSV and Trivy
findings. It returns scanner status and a compact findings table together with
a DataSheet result for buttons, scheduled tasks and action chains.
Action authorization applies to every invocation.

## Execution

`Audit` extends `AbstractAction` and completes installation, scanning and optional
JSON export synchronously before returning a standard `ResultData`. Options and
mapped input are validated before scanner side effects. Status messages, hints
and the final deduplicated table are collected into the result message; they are
not streamed live while scanners run.

The findings DataSheet is available immediately when the action returns. Reading
the data or message does not rerun scanners. Output mappings, action-completion
events and transactions use the existing action lifecycle without changes to
`AbstractAction` or `AbstractActionDeferred`. Action chains can forward the findings
DataSheet as a normal data result.

## CLI

```console
vendor/bin/action axenox.PackageManager:Audit
vendor/bin/action axenox.PackageManager:Audit data/Deployer/myproject/
vendor/bin/action axenox.PackageManager:Audit data/Deployer/myproject/ --output=data/vulnerabilities.json
vendor/bin/action axenox.PackageManager:Audit --install=TrivySBOMScanner
```

Relative folders and output paths resolve against the workbench installation.
The output directory must exist. `--output` requires a `.json` file and is CLI-only,
as is `--install`. Installation names must identify exactly one configured scanner
by map key, short class name or fully qualified class name. For example, the default
Trivy configuration accepts `--install=trivy` or `--install=TrivySBOMScanner`.
After explicit installation, the action runs the audit normally.

No scanners are installed automatically during a normal audit. Missing prerequisites
produce visible hints; malformed artifacts, command failures and network failures
raise errors rather than reporting a clean scan. JSON exports have `findings`,
`hints`, and `scanners` properties. Scanner status is `completed`, `skipped`, or
`not applicable`, keyed by the names in the configured scanner map. An empty findings
array alone does not prove scan completeness.
Findings do not cause a non-zero action exit code: downstream build policies decide
which severities block deployment. This action does not register Composer hooks or
implement Deployer scheduling/database persistence.

## Artifact Input

Scanners consume separate task parameters, not DataSheet rows:

The shared task parameter names are declared on `Actions\Audit` for scanner authors:

| Constant | Parameter | Purpose |
| --- | --- | --- |
| `TASK_PARAM_COMPOSER_LOCK` | `composer_lock` | Composer lock artifact. |
| `TASK_PARAM_SBOM` | `sbom` | Combined package JSON, CycloneDX or SPDX artifact. |
| `TASK_PARAM_FOLDER` | `folder` | Target build directory when no explicit artifacts are supplied. |
| `TASK_PARAM_INSTALL` | `install` | CLI-only selector for prerequisite installation. |
| `TASK_PARAM_OUTPUT` | `output` | CLI-only JSON export destination. |

Use these constants when accessing task parameters in scanners. Artifact values are:

- `composer_lock`: one entire composer.lock JSON document.
- `sbom`: one combined package JSON (as exported to `vendor/licenses.json`),
  CycloneDX or SPDX JSON document. OSV accepts all three; Trivy only accepts
  CycloneDX and SPDX.

Each parameter accepts a JSON string, decoded array or `UxonObject`. Both can be
supplied on the same task; no `audit_artifacts` array is used. Artifact parameters
take precedence over `folder` and disable folder fallback for every scanner.
An invalid supplied artifact fails rather than falling back to the installation.
Without artifact parameters, scanners use `folder` or the current installation.

The action owns translating its context into these parameters. Configure
`composer_lock_attribute_alias` to enrich the input DataSheet and copy the first
row's Composer lock into `composer_lock`. SBOM content must currently be supplied
through the `sbom` task parameter. Scanners do not resolve attribute aliases or
read `COMPOSER_LOCK` / `SBOM` columns themselves. Folder and nonempty action input
rows remain mutually exclusive.
Composer scans saved locks in an isolated temporary directory without installing
packages or executing their plugins/scripts. Temporary artifacts are removed even
when scanning fails. npm sends locked npm-asset package names and versions to the
public npm advisory bulk endpoint for both folder and archived-data audits.
Trivy scans the supplied SBOM and may refresh its local vulnerability database.

## Scanners

`AUDIT.SCANNERS` is a named map of scanner UXON configurations, following the same
structure as `SBOM.files`. Each entry requires a fully qualified `class`; any other
properties are imported into that scanner after removing `class`. Unknown properties
are configuration errors. The defaults are:

```json
{
	"AUDIT.SCANNERS": {
		"composer": {
			"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\ComposerAuditScanner"
		},
		"npm": {
			"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\ComposerNpmAuditScanner"
		},
		"osv": {
			"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\OsvAuditScanner"
		},
		"trivy": {
			"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\TrivySBOMScanner"
		}
	}
}
```

The action's `scanners` UXON property accepts the same named map and replaces global
defaults when supplied. An empty map disables all scanners; omission uses the
installation's `AUDIT.SCANNERS`. For example, to use only npm auditing:

```json
{
	"alias": "axenox.PackageManager.Audit",
	"scanners": {
		"npm": {
			"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\ComposerNpmAuditScanner"
		}
	}
}
```

Names identify scanner instances in status output. Two named entries selecting the
same class create independent instances with their own options. Use a map key for
`--install` when a class name would select more than one instance. Built-in scanners
currently have no additional options; custom prototypes expose options through UXON
setters. Existing class-name lists and references to the old `axenox\PackageManager\Audit`
namespace must be migrated to the named map and `Common\Audit\Scanner` namespace.

Composer requires version 2.7 or newer. A `composer.phar` in the scanned directory
is preferred and run with a PHP CLI executable resolved by
`CliCommandRunner::findPhpExecutable()` using Symfony's `PhpExecutableFinder`,
not Apache's `PHP_BINARY`. When PHP CLI is not discoverable
automatically, `PHP_PATH` can specify its executable path for the web server process.
When no project PHAR exists, the scanner uses
Composer installed under the workbench's `vendor/composer/composer`, then Composer
on PATH. Lock-only scans retain the original directory's PHAR while running against
isolated temporary artifacts. A project PHAR must be trusted; command failures
propagate rather than silently switching executables.
Composer profile variables (`COMPOSER_HOME`, `APPDATA`, `HOME`) are forwarded
explicitly to subprocesses: Apache can expose them without passing them to children.
If neither `COMPOSER_HOME` nor the platform's profile variable is set, the scanner
uses `<data>/.composer` for its child process only. This prevents web-console audits
from failing because Composer cannot determine its home directory. If Composer
returns invalid or empty JSON, its stderr and exit code are included in the error
rather than hidden behind a generic JSON syntax error.
It uses `audit --locked --format=json --abandoned=report`
with plugins and scripts disabled, and includes development dependencies.
Ignored Composer advisories are retained with their ignore reason rather than
disappearing from build-monitoring results. Valid empty locks return zero findings
without requiring JSON output from Composer's no-packages shortcut.
Abandoned Composer packages are reported as `EOL`, which here means unmaintained,
not a verified vendor support-expiration date.

The npm scanner reads npm-asset package names and exact versions from composer.lock
or the supplied `composer_lock` parameter, including development dependencies. Both paths
POST to `https://registry.npmjs.org/-/npm/v1/security/advisories/bulk` and use the same
result normalization. No Composer executable, Composer plugin, Node.js, installed
vendor directory or target composer.json is required. Empty npm dependency lists
do not trigger a request. HTTP failures propagate rather than reporting a clean scan.
No scanner installation is needed; its interface-compatible `install()` is a no-op.
The scanner never changes the target Composer setup or removes previously installed
plugins. The `ComposerNpmAuditScanner` class name remains unchanged for compatibility.

### OSV

`OsvAuditScanner` scans both `composer_lock` and `sbom` when both task parameters
are present. Composer locks include development dependencies. Combined JSON uses
its `packages` entries; CycloneDX includes nested components and its metadata
component; SPDX uses package PURL external references. Overlapping package/version
queries are deduplicated. Distinct installed versions remain separate detections.

Without explicit artifacts, OSV reads the target folder's `composer.lock` and the
first available BOM in this order: `vendor/licenses.json`, `vendor/SBOM.cdx.json`,
`sbom.cdx.json`, `data/sbom.cdx.json`. Regenerate BOM files for each build; OSV does
not generate inventories or verify that a saved inventory matches installed files.

OSV maps Composer dependencies to `Packagist` and `npm-asset` aliases to `npm`,
including scoped names. PURLs take precedence. Untyped bundled package names are
not assumed to exist on Packagist: supply a PURL or an explicit `ecosystem` field.
Git source metadata with a 40-character commit reference can identify development
revisions. Missing versions, unknown bundled identities, and generic/Bower PURLs
without usable Git metadata produce coverage hints. The current action labels
scans with coverage hints as `skipped`, even if some packages produced findings.
An empty response is not proof that OSV covers every package or advisory source.

The scanner POSTs chunks of up to 100 queries to
`https://api.osv.dev/v1/querybatch`, follows per-query pagination, and fetches full
records from `/v1/vulns/{id}` once per advisory within the scan. Withdrawn advisories
are omitted. Native IDs, CVE aliases, descriptions and installed versions are
retained. Severity uses OSV's database-specific or affected ecosystem-specific
labels; records without a label retain `unknown` (conservatively high). CVSS vectors
are not converted to severity labels. Affected range events are retained as JSON
in `VERSIONS_AFFECTED`; `VERSION_FIXED` lists fix events for the queried package,
not a computed upgrade recommendation. Multiple CVE aliases produce separate
findings. API, malformed-response and pagination errors fail the scan.

Only package identities, versions or Git commits are sent to OSV; license texts
and the complete BOM are not uploaded. No executable installation is required;
`install()` is a no-op. HTTPS uses the normal trusted CA configuration.

### Trivy

Trivy looks for `sbom.cdx.json` in the target folder or its `data` subfolder. Current
installation scans also check the workbench data folder. It uses Trivy on PATH or
the binary in `<data>/audit/trivy/`. Explicit installation downloads the latest
official GitHub release and verifies its SHA-256 checksum before installing.
Windows x64 requires PHP ZipArchive; Linux x64/ARM64 uses `tar` via CliCommandRunner.
Installation is local to the data folder and does not invoke sudo or change system
packages. Network access is required for advisories, releases and Trivy DB updates.
PHP must have a trusted CA bundle configured for HTTPS (for example through
`curl.cainfo` and `openssl.cafile` in php.ini). TLS certificate validation is never
disabled; a missing issuer certificate fails the scan with a configuration hint.

## Findings

Internally scanners and the action exchange immutable `FindingInterface` objects,
implemented by [Finding](../Common/Audit/Finding.php). Named getters expose every finding
property. Each raw finding represents one advisory for exactly one package from one
scanner. `getSourceId()` retains the scanner-native advisory ID, while `is()`
compares all scalar evidence fields. Findings have no generated internal IDs;
object references and evidence comparison suffice for internal processing.
Raw findings do not merge or define a reporting order. Consumers can group them
according to their own needs.

[MergedFinding](../Common/Audit/MergedFinding.php) also implements `FindingInterface` and
owns aggregation strategies. Its constructor accepts only a nonempty group of
findings for the same case-insensitive package and type, without an ID argument. Nested aggregates
are flattened and equal evidence is omitted. `getMergedFindings()` returns the
original `FindingInterface` instances, preserving scanner-specific associations
without mutating them. The highest severity wins; distinct nonempty evidence
values are joined with `; `, except source labels, which use `, `. The first finding
supplies the title. Public IDs prefer the first CVE, then the first GHSA, then the
first nonempty remaining identifier, regardless of which scanner ran first.
Individual getters can be overridden to implement different aggregation strategies.

Arrays are used only at external boundaries: scanner API payloads and serialized
output. The `Audit` action owns the result column schema, selects the result
metaobject and creates the DataSheet. CLI, DataSheet and JSON output group findings
by finding type and case-insensitive package name, matching either advisory identity
or a nonempty case-insensitive title across scanners. Different packages always
produce separate rows, so mitigation remains per-package.
The action's `groupFindings()` helper creates a `MergedFinding` for every group;
`toDataSheetRows()` serializes their getters when populating the result sheet. The serializer
also accepts raw findings, without implementing merging logic itself. The action
generates package-specific output IDs; removing internal finding IDs does not
change those output IDs.

Every result finding has `LEVEL`, `ID`, `PUBLIC_ID`, `NAME`, `TYPE`, `PACKAGE`,
`DETAILS_URL`, `SOURCE` and `SOURCE_LEVEL`. Scanner-provided CVEs are normalized
to uppercase and used as public identifiers. Original CVEs remain accessible
through each raw finding's `getCve()` getter.
The npm bulk API does not supply CVEs; it supplies numeric IDs and advisory URLs.
`PUBLIC_ID` stores the recognizable identifier: CVE when available, otherwise a GHSA
extracted from the details URL, otherwise the scanner's ID. An explicitly supplied
`PUBLIC_ID` is retained. EOL entries normally leave this value blank.
CLI tables show this field in a `PUBLIC ID` column, not the generated output `ID`.
GHSA extraction uses existing URLs and does not fetch linked pages or add API requests.
Full URLs and source-native levels are omitted from the table. The modeled output uses
`PUBLIC_ID` instead of a separate `CVE` column. `ID` is a generated, deterministic
64-character SHA-256 identifier,
available alongside `PUBLIC_ID` in DataSheet and JSON results. The action generates
it from the output grouping key during row serialization. For merged findings,
the key uses the lexically smallest original key rather than combined scanner-native
IDs. Package names are lowercased in keys. For the same set of detections, IDs stay
unchanged regardless of severity or scanner order. Adding a different advisory
identity to a title-matched group can change its output ID.
Different packages have different output IDs even when they share an advisory.
Original scanner IDs remain accessible through `getMergedFindings()` and each
original's `getSourceId()` getter, independently of the group's output ID.
Internal levels
are integers: `100` (low), `200` (medium), `300` (high), `400` (critical).
Moderate becomes `200`, informational becomes `100`, and unknown severity
conservatively becomes `300`. The original scanner label is retained in `SOURCE_LEVEL`.
`VulnerabilityLevelDataType` is an integer-based static enum with translated labels.
Its static `compare()` method returns -1, 0 or 1 in ascending severity order;
`isHigher()` and `isLower()` provide strict comparisons of normalized levels.
DataSheet and JSON outputs retain numeric `LEVEL` values; the CLI table displays
translated datatype labels (`Low`, `Medium`, `High` and `Critical` in English).
`VulnerabilityLevelDataType::getLabelsStatic($workbench)` returns labels indexed
by numeric level; `getLabelOfValueStatic($workbench, $value)` returns one label
or `null` for an unknown value. Both use the same translations as the enum's
instance labels. The action builds one result DataSheet and
uses `DataSheet::sort()` with explicit sorters to order its rows by level
descending, then package ascending, with public identifier and internal ID as
stable tie-breakers. The CLI formatter and JSON exporter receive that same sorted
DataSheet rather than findings or row arrays. They extract rows only for rendering
and serialization, so all three outputs share one grouping and sorting pass.

Additional fields preserve useful scanner data: `DESCRIPTION`, `REMEDIATION`,
`VERSIONS_AFFECTED`, `VERSION_INSTALLED`, and `VERSION_FIXED`. Unavailable values
are empty strings. DataSheet and JSON output contain these 14 documented columns;
the former `DETECTIONS` column and modeled attribute have been removed. Consumers
that need original scanner evidence in PHP use `getMergedFindings()` instead.
Scanners can disagree about severity, fixed versions, affected ranges or recommendations;
those associations remain available on the originals, but are not exported as nested JSON.

Rows are deduplicated by finding type, case-insensitive package and either
case-insensitive `PUBLIC_ID` or nonempty case-insensitive `NAME`. The title check
matches npm GHSA findings with OSV CVE findings even when their public IDs differ.
When a public ID is unavailable, the primary identity still falls back to the native
scanner ID, then title, within that scanner's namespace. A finding matching one
group's identity and another group's title joins both groups. Empty titles do not
provide an additional match.

Title matching is a heuristic: distinct advisories with the same package and title
will also merge, including multiple CVE identities. Every original identity remains
available through `getMergedFindings()`; the first finding supplies the displayed
title and package spelling. The displayed public ID prefers CVE over GHSA over
other identities. The most severe level wins, and sources and other evidence are
combined. Source labels are separated by a comma and space, for example `npm, osv`.
The shared `VulnerabilityLevelDataType::isCVE()` and `isGHSA()` helpers classify
complete identifiers without case sensitivity. npm scoped packages use Composer's
`npm-asset/scope--package` notation. Trivy's OS package names remain native names.

By default findings use the modeled object `axenox.PackageManager.AUDIT_ADVISORY`,
including its configured column data types. To select another findings object for
action-chain mappings, configure the inherited `result_object_alias`; that object
must define all documented columns. The action itself does not write findings to a database.

## Extensions

Implement `Interfaces/AuditScannerInterface.php` and accept `WorkbenchInterface`
in the constructor, or extend `Common/Audit/Scanner/AbstractAuditScanner.php`. The
interface also requires Core's `iCanBeConvertedToUxon` contract. Use
`iCanBeConvertedToUxonTrait` for direct implementations; the shared base already uses it.
Expose scanner options with annotated UXON setters, which are called after construction.
Implement `supports`,
`audit`, `install`, and `getHints`. `audit` must return `FindingInterface[]`, not row
arrays. Read artifact content from `composer_lock` and `sbom` task parameters and
leave DataSheet enrichment and context mapping to the action. Composer execution and version checks belong to `ComposerAuditScanner`,
their only consumer, rather than the generic base. npm and Trivy scanners do not
inherit these methods. Construct `Finding` with the native ID, name, type, package, source and
source-native severity; optional string arguments hold URLs, CVE, description,
remediation and version evidence, plus an explicit public identifier when needed.
Severity normalization and public-identity resolution belong to the finding.
Each scanner owns a protected `createFinding()` method that maps its decoded native
response directly to the `Finding` constructor. Composer uses `advisoryId`, `link`
and `affectedVersions`; npm uses registry IDs, `overview`, `vulnerable_versions`
and `patched_versions`; Trivy uses `VulnerabilityID`, `PkgName`, `InstalledVersion`
and `FixedVersion`. Their methods document the native response shapes and need not
share a signature. There is no intermediate advisory array schema or shared
`finding()` helper in `AbstractAuditScanner`.
Ignored Composer advisories append policy notes while being mapped, and npm CVEs
are supplied as separate arguments rather than injected into response arrays.
Lifecycle findings are constructed directly from abandoned-package or OS metadata.
Custom scanners should own their native-response mapping and return the typed
contract; arrays are not accepted as internal findings.
Register a named configuration containing `class` and any options in `AUDIT.SCANNERS`
or in the action's `scanners` property. Scanner prototypes belong in
`Common/Audit/Scanner`; findings and aggregates belong in `Common/Audit`.
Missing prerequisites return installation
hints, while actual scanner failures must throw. Use
`CliCommandRunner::runCliCommandIntoArray($exec, $arguments, $cwd, $acceptedExitCodes, $timeout, $envVars)`
for shell-free commands with separate executable and argument values. For PHP scripts,
resolve the executable with `CliCommandRunner::findPhpExecutable()` and pass the script
path as the first argument. Clean temporary artifacts in `finally` blocks.

## Validation

Run the PHPUnit unit and integration suites from the installation root:

```console
php vendor/bin/phpunit -c vendor/axenox/packagemanager/phpunit.xml.dist
vendor/bin/action axenox.PackageManager:Audit --help
```

Use `--testsuite unit` for parsing, deduplication, mocked scanner I/O and synchronous
action checks, or `--testsuite integration` for real local PHP subprocess checks.
Both suites use real Workbench instances where needed, without database access.
They do not install tools or contact advisory services. See [test conventions](../Tests/PHPUnit/README.md)
for setup and focused execution. Verify live scans and explicit tool installation
separately in the intended build-server environment.