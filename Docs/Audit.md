# Vulnerability Audits

The `axenox.PackageManager.Audit` action combines Composer, npm-asset and Trivy
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
as is `--install`. Installation names must identify exactly one configured scanner.
After explicit installation, the action runs the audit normally.

No scanners are installed automatically during a normal audit. Missing prerequisites
produce visible hints; malformed artifacts, command failures and network failures
raise errors rather than reporting a clean scan. JSON exports have `findings`,
`hints`, and `scanners` properties. Scanner status is `completed`, `skipped`, or
`not applicable`. An empty findings array alone does not prove scan completeness.
Findings do not cause a non-zero action exit code: downstream build policies decide
which severities block deployment. This action does not register Composer hooks or
implement Deployer scheduling/database persistence.

## Artifact Input

Map saved artifact content to input DataSheet columns:

- `COMPOSER_LOCK`: the entire composer.lock JSON string or decoded array.
- `SBOM`: a CycloneDX or SPDX JSON string or decoded array.

Rows may contain either artifact or both. Folder and nonempty artifact input are
mutually exclusive. Supplied rows never silently fall back to the installed project.
Composer scans saved locks in an isolated temporary directory without installing
packages or executing their plugins/scripts. Temporary artifacts are removed even
when scanning fails. npm sends locked npm-asset package names and versions to the
public npm advisory bulk endpoint for both folder and archived-data audits.
Trivy scans the supplied SBOM and may refresh its local vulnerability database.

## Scanners

`AUDIT.SCANNERS` is an array of fully qualified PHP class names. Defaults are
`ComposerAuditScanner`, `ComposerNpmAuditScanner` and `TrivySBOMScanner` in
`axenox\PackageManager\Audit`.

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
or supplied `COMPOSER_LOCK` data, including development dependencies. Both paths
POST to `https://registry.npmjs.org/-/npm/v1/security/advisories/bulk` and use the same
result normalization. No Composer executable, Composer plugin, Node.js, installed
vendor directory or target composer.json is required. Empty npm dependency lists
do not trigger a request. HTTP failures propagate rather than reporting a clean scan.
No scanner installation is needed; its interface-compatible `install()` is a no-op.
The scanner never changes the target Composer setup or removes previously installed
plugins. The `ComposerNpmAuditScanner` class name remains unchanged for compatibility.

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
implemented by [Finding](../Audit/Finding.php). Named getters expose every finding
property. Each raw finding represents one advisory for exactly one package from one
scanner. `getSourceId()` retains the scanner-native advisory ID, while `is()`
compares all scalar evidence fields. Findings have no generated internal IDs;
object references and evidence comparison suffice for internal processing.
Raw findings do not merge or define a reporting order. Consumers can group them
according to their own needs.

[MergedFinding](../Audit/MergedFinding.php) also implements `FindingInterface` and
owns aggregation strategies. Its constructor accepts only a nonempty group of
findings for the same package and type, without an ID argument. Nested aggregates
are flattened and equal evidence is omitted. `getMergedFindings()` returns the
original `FindingInterface` instances, preserving scanner-specific associations
without mutating them. The highest severity wins; distinct nonempty evidence
values are joined with `; `. The first finding supplies the title and public ID.
Individual getters can be overridden to implement different aggregation strategies.

Arrays are used only at external boundaries: scanner API payloads and serialized
output. The `Audit` action owns the result column schema, selects the result
metaobject and creates the DataSheet. CLI, DataSheet and JSON output group findings
by type, case-insensitive public ID and exact package name across scanners.
Different packages always produce separate rows, so mitigation remains per-package.
The action's `groupFindings()` helper creates a `MergedFinding` for every group;
`toDataSheetRows()` serializes their getters and sorts the output. The serializer
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
the key uses the first original rather than combined scanner-native IDs. It stays
unchanged for the same advisory and package regardless of severity or scanner order.
Different packages have different output IDs even when they share an advisory.
Original scanner IDs remain accessible through `getMergedFindings()` and each
original's `getSourceId()` getter, independently of the group's output ID.
Internal levels
are `critical`, `high`, `medium`, `low`; moderate becomes medium, informational
becomes low, and unknown severity conservatively becomes high. The original level
is retained. `VulnerabilityLevelDataType` is a model-compatible static enum.

Additional fields preserve useful scanner data: `DESCRIPTION`, `REMEDIATION`,
`VERSIONS_AFFECTED`, `VERSION_INSTALLED`, and `VERSION_FIXED`. Unavailable values
are empty strings. DataSheet and JSON output contain these 14 documented columns;
the former `DETECTIONS` column and modeled attribute have been removed. Consumers
that need original scanner evidence in PHP use `getMergedFindings()` instead.
Scanners can disagree about severity, fixed versions, affected ranges or recommendations;
those associations remain available on the originals, but are not exported as nested JSON.

Rows are deduplicated by case-insensitive `PUBLIC_ID`, finding type and package.
When a public ID is unavailable, grouping falls back to the native scanner ID,
then title, within that scanner's namespace. The most severe level wins; sources
and other evidence are combined, but package names are never combined.
Different IDs for the same underlying advisory cannot be matched without an
explicit shared identifier. npm scoped packages use Composer's
`npm-asset/scope--package` notation. Trivy's OS package names remain native names.

By default findings use the modeled object `axenox.PackageManager.AUDIT_ADVISORY`,
including its configured column data types. To select another findings object for
action-chain mappings, configure the inherited `result_object_alias`; that object
must define all documented columns. The action itself does not write findings to a database.

## Extensions

Implement `Interfaces/AuditScannerInterface.php` and accept `WorkbenchInterface`
in the constructor, or extend `Audit/AbstractAuditScanner.php`. Implement `supports`,
`audit`, `install`, and `getHints`. `audit` must return `FindingInterface[]`, not row
arrays. Composer execution and version checks belong to `ComposerAuditScanner`,
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
Register the class in `AUDIT.SCANNERS`. Missing prerequisites return installation
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