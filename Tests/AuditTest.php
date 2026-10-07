<?php
namespace axenox\PackageManager\Tests;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Audit\ComposerAuditScanner;
use axenox\PackageManager\Audit\ComposerNpmAuditScanner;
use axenox\PackageManager\Audit\TrivySBOMScanner;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Exceptions\CliRuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;

require dirname(__DIR__, 3) . '/autoload.php';

/** Supplies Composer fixtures without contacting advisory servers or changing dependencies. */
class FixtureComposerScanner extends ComposerAuditScanner
{
    public $response;
    public $scannedFolders = [];
    public $fail = false;
    public $stdout = null;
    public $stderr = '';

    /** Fixture commands do not depend on the host's Composer installation. */
    protected function composerAvailable(string $folder) : bool
    {
        return true;
    }

    /** Verifies lock-only scans use isolated files and disable untrusted project code. */
    protected function composer(array $arguments, string $folder, array $acceptedExitCodes = [0], ?string $composerFolder = null) : array
    {
        $this->scannedFolders[] = $folder;
        check(is_file($folder . '/composer.json') && is_file($folder . '/composer.lock'), 'Composer artifacts missing');
        check(in_array('--no-plugins', $arguments, true) && in_array('--no-scripts', $arguments, true), 'Project code was not disabled');
        check($acceptedExitCodes === [0, 1, 2, 3], 'Finding exit codes missing');
        if ($this->fail) {
            throw new RuntimeException('Simulated scanner failure');
        }
        return ['stdout' => $this->stdout ?? json_encode($this->response, JSON_THROW_ON_ERROR), 'stderr' => $this->stderr, 'exit_code' => 1];
    }
}

/** Exercises both npm input paths with a controlled HTTP transport and no Composer execution. */
class FixtureNpmScanner extends ComposerNpmAuditScanner
{
    public $client;
    public $rows = [];
    public $supportChecks = 0;
    public $auditCalls = 0;

    /** Records how often the action checks artifacts. */
    public function supports(\exface\Core\Interfaces\Tasks\TaskInterface $task) : bool
    {
        $this->supportChecks++;
        return parent::supports($task);
    }

    /** Records when the blocking request begins while retaining real HTTP normalization. */
    public function audit(\exface\Core\Interfaces\Tasks\TaskInterface $task) : array
    {
        $this->auditCalls++;
        return parent::audit($task);
    }

    /** Supplies archived rows without needing a persistent build metaobject in transport tests. */
    protected function inputRows(\exface\Core\Interfaces\Tasks\TaskInterface $task) : array
    {
        return $this->rows;
    }

    /** Replaces only the transport, keeping the real request construction and normalization. */
    protected function createHttpClient() : \GuzzleHttp\Client
    {
        return $this->client;
    }

    /** Fails explicitly if a regression reintroduces plugin installation or CLI scanning. */
    protected function composer(array $arguments, string $folder, array $acceptedExitCodes = [0], ?string $composerFolder = null) : array
    {
        throw new RuntimeException('HTTP-only npm audits must not invoke Composer.');
    }
}

/** Isolates synchronous Audit execution from persistent metaobjects. */
class FixtureAudit extends Audit
{
    public $scanner;
    public $sheet;
    public $findings = [];

    /** {@inheritDoc} @see Audit::scanners() */
    protected function scanners() : array
    {
        return [$this->scanner];
    }

    /** {@inheritDoc} @see Audit::createResultSheet() */
    protected function createResultSheet(array $findings) : \exface\Core\Interfaces\DataSheets\DataSheetInterface
    {
        $this->findings = $findings;
        return $this->sheet;
    }

}

/** Stops at the first regression instead of relying on PHP's optional assertions. */
function check(bool $condition, string $message) : void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** Tests local parsing/aggregation without bypassing authorization on the public action entry point. */
function invokeProtected($instance, string $name, array $arguments = [])
{
    $method = new \ReflectionMethod($instance, $name);
    $method->setAccessible(true);
    return $method->invokeArgs($instance, $arguments);
}

$workbench = new Workbench();
$auditTask = new GenericTask($workbench);
$auditSheet = (new \ReflectionClass(\exface\Core\CommonLogic\DataSheets\DataSheet::class))->newInstanceWithoutConstructor();
$npm = new ComposerNpmAuditScanner($workbench);
$npmRows = invokeProtected($npm, 'normalize', [['@scope/package' => [[
    'id' => 123, 'title' => 'Fixture vulnerability', 'severity' => 'moderate',
    'cves' => ['CVE-2026-0001', 'CVE-2026-0002'], 'vulnerable_versions' => '<2',
    'patched_versions' => '>=2', 'recommendation' => 'Upgrade to version 2'
]]]]);
check(count($npmRows) === 2 && $npmRows[0]['PACKAGE'] === 'npm-asset/scope--package', 'npm IDs or scoped package conversion failed');
check(array_column($npmRows, 'CVE') === ['CVE-2026-0001', 'CVE-2026-0002'], 'npm CVEs were not retained separately');
check($npmRows[0]['LEVEL'] === 'medium' && $npmRows[0]['REMEDIATION'] === 'Upgrade to version 2', 'npm severity/remediation lost');
$noCve = invokeProtected($npm, 'finding', ['composer', 'php/package', ['advisoryId' => 'PKSA-generated', 'title' => 'No CVE', 'severity' => 'high']]);
check($noCve['ID'] === 'PKSA-generated' && $noCve['CVE'] === '', 'Non-CVE identifiers were misreported as CVEs');
$lowercaseCve = invokeProtected($npm, 'finding', ['composer', 'php/package', ['cve' => 'cve-2026-0004']]);
check($lowercaseCve['CVE'] === 'CVE-2026-0004', 'CVE casing was not normalized');
$dependencies = invokeProtected($npm, 'dependencies', [['packages' => [
    ['name' => 'npm-asset/scope--package', 'version' => 'v1.0.0'],
    ['name' => 'php/package', 'version' => '1.0.0']
]]]);
check($dependencies === ['@scope/package' => ['1.0.0']], 'npm lock extraction failed');

$auditAction = (new \ReflectionClass(FixtureAudit::class))->newInstanceWithoutConstructor();
$auditAction->sheet = $auditSheet;
$auditScanner = new FixtureNpmScanner($workbench);
$auditScanner->rows = [['COMPOSER_LOCK' => ['packages' => [['name' => 'npm-asset/fixture', 'version' => '1.0.0']]]]];
$auditScanner->client = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([
    new \GuzzleHttp\Psr7\Response(200, [], '{"fixture":[{"id":123,"title":"Fixture","severity":"high"}]}')
]))]);
$auditAction->scanner = $auditScanner;
$transaction = (new \ReflectionClass(\exface\Core\CommonLogic\DataTransaction::class))->newInstanceWithoutConstructor();
$auditResult = invokeProtected($auditAction, 'perform', [$auditTask, $transaction]);
check($auditAction instanceof \exface\Core\CommonLogic\AbstractAction && ! ($auditAction instanceof \exface\Core\CommonLogic\AbstractActionDeferred), 'Audit still depends on deferred action processing');
check($auditResult instanceof \exface\Core\CommonLogic\Tasks\ResultData && ! ($auditResult instanceof \exface\Core\Interfaces\Tasks\ResultMessageStreamInterface), 'Audit did not return a standard data result');
check($auditScanner->supportChecks === 1 && $auditScanner->auditCalls === 1, 'Audit did not complete scanning synchronously');
$auditOutput = $auditResult->getMessage();
check(strpos($auditOutput, 'FixtureNpmScanner: checking input...') !== false && strpos($auditOutput, 'FixtureNpmScanner: completed (1 findings).') !== false, 'Audit report lost scanner status');
check($auditResult->getData() === $auditSheet && $auditResult->getMessage() === $auditOutput && count($auditAction->findings) === 1, 'Audit lost findings or its completed report');
check($auditScanner->auditCalls === 1, 'Reading Audit results reran the scanner');

$npmFolder = sys_get_temp_dir() . '/exf-npm-http-test-' . bin2hex(random_bytes(8));
check(mkdir($npmFolder, 0700), 'Cannot create npm fixture directory');
try {
    $lock = ['packages' => [['name' => 'npm-asset/scope--package', 'version' => 'v1.0.0']],
        'packages-dev' => [['name' => 'npm-asset/dev-package', 'version' => '2.0.0']]];
    $lockJson = json_encode($lock, JSON_THROW_ON_ERROR);
    check(file_put_contents($npmFolder . '/composer.lock', $lockJson) !== false, 'Cannot create npm lock fixture');
    $requests = [];
    $mock = new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], '{"@scope/package":[{"id":123,"url":"https://github.com/advisories/GHSA-jpcq-cgw6-v4j6","title":"Fixture","severity":"high"}]}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{"@scope/package":[{"id":123,"url":"https://github.com/advisories/GHSA-jpcq-cgw6-v4j6","title":"Fixture","severity":"high"}]}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{}'),
        new \GuzzleHttp\Psr7\Response(503, [], 'Registry unavailable')
    ]);
    $handler = \GuzzleHttp\HandlerStack::create($mock);
    $handler->push(\GuzzleHttp\Middleware::history($requests));
    $scanner = new FixtureNpmScanner($workbench);
    $scanner->client = new \GuzzleHttp\Client(['handler' => $handler]);
    $folderTask = new GenericTask($workbench);
    $folderTask->setParameter('folder', $npmFolder);
    check($scanner->supports($folderTask), 'npm lock-only folder was not supported');
    $folderRows = $scanner->audit($folderTask);
    $scanner->install($folderTask);
    check(! is_file($npmFolder . '/composer.json') && file_get_contents($npmFolder . '/composer.lock') === $lockJson, 'npm audit or installation modified target files');
    $scanner->rows = [['COMPOSER_LOCK' => $lockJson]];
    $dataTask = new GenericTask($workbench);
    $dataRows = $scanner->audit($dataTask);
    check($folderRows === $dataRows && $folderRows[0]['ID'] === 'npm:123', 'Folder and archived npm findings differ');
    check($folderRows[0]['CVE'] === '', 'npm advisories without CVEs should leave CVE empty');
    foreach ($requests as $request) {
        check($request['request']->getMethod() === 'POST'
            && (string) $request['request']->getUri() === 'https://registry.npmjs.org/-/npm/v1/security/advisories/bulk', 'Wrong npm API request');
        check(json_decode((string) $request['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)
            === ['@scope/package' => ['1.0.0'], 'dev-package' => ['2.0.0']], 'npm API payload lost locked versions or development packages');
    }
    check($scanner->audit($dataTask) === [], 'Empty registry response produced findings');
    try {
        $scanner->audit($dataTask);
        throw new RuntimeException('Registry failure was swallowed');
    } catch (\GuzzleHttp\Exception\ServerException $error) {
        check($error->getResponse()->getStatusCode() === 503, 'Registry failure status lost');
    }
    $scanner->rows = [['COMPOSER_LOCK' => ['packages' => []]]];
    check(! $scanner->supports($dataTask) && $scanner->audit($dataTask) === [] && count($requests) === 4, 'Empty npm locks should not query the registry');
} finally {
    unlink($npmFolder . '/composer.lock');
    rmdir($npmFolder);
}

$trivy = new TrivySBOMScanner($workbench);
$trivyRows = invokeProtected($trivy, 'normalize', [[
    'SchemaVersion' => 2,
    'Results' => [['Type' => 'node-pkg', 'Vulnerabilities' => [[
        'PkgName' => '@scope/package', 'VulnerabilityID' => 'CVE-2026-0001',
        'Severity' => 'CRITICAL', 'InstalledVersion' => '1.0.0', 'FixedVersion' => '2.0.0'
    ]]]],
    'Metadata' => ['OS' => ['Family' => 'alpine', 'Name' => '3.10', 'EOSL' => true]]
]]);
check($trivyRows[0]['LEVEL'] === 'critical' && $trivyRows[1]['TYPE'] === 'EOL', 'Trivy severity or EOL failed');
check($trivyRows[0]['CVE'] === 'CVE-2026-0001' && $trivyRows[1]['CVE'] === '', 'Trivy CVE or empty EOL CVE was lost');
check($trivyRows[0]['PACKAGE'] === 'npm-asset/scope--package', 'Trivy Node.js package normalization failed');
$trivyWithoutCve = invokeProtected($trivy, 'normalize', [[
    'SchemaVersion' => 2,
    'Results' => [['Vulnerabilities' => [['PkgName' => 'php/package', 'VulnerabilityID' => 'GHSA-fixture']]]]
]]);
check($trivyWithoutCve[0]['ID'] === 'GHSA-fixture' && $trivyWithoutCve[0]['CVE'] === '', 'Trivy non-CVE identifier was misreported as a CVE');

$action = (new \ReflectionClass(Audit::class))->newInstanceWithoutConstructor();
$findings = invokeProtected($action, 'mergeFindings', [array_merge($npmRows, $trivyRows, [$npmRows[0]])]);
check(count($findings) === 3 && $findings[0]['LEVEL'] === 'critical', 'ID deduplication or severity ordering failed');
check($findings[0]['SOURCE'] === 'npm; trivy', 'Sources lost during deduplication');
check(! array_key_exists('CVE', $findings[0]), 'Removed model CVE column remained in output');
check(json_decode($findings[0]['DETECTIONS'], true, 512, JSON_THROW_ON_ERROR)[0]['CVE'] === 'CVE-2026-0001', 'Source CVE was lost from detection evidence');
check($findings[0]['PUBLIC_ID'] === 'CVE-2026-0001', 'Public CVE identifier was not stored');
foreach ($findings as $finding) {
    check(preg_match('/^[a-f0-9]{64}$/', $finding['ID']) === 1, 'Internal advisory ID was not generated');
}
$internalIds = array_column($findings, 'ID');
check(count(array_unique($internalIds)) === count($findings), 'Internal IDs are not unique across advisories');
$reorderedFindings = invokeProtected($action, 'mergeFindings', [array_reverse(array_merge($npmRows, $trivyRows, [$npmRows[0]]))]);
$reorderedIds = array_column($reorderedFindings, 'ID');
sort($internalIds);
sort($reorderedIds);
check($internalIds === $reorderedIds, 'Internal IDs changed with scanner order');
check(count(json_decode($findings[0]['DETECTIONS'], true, 512, JSON_THROW_ON_ERROR)) === 2, 'Duplicate evidence was not removed');
$anotherPackage = $npmRows[0];
$anotherPackage['PACKAGE'] = 'npm-asset/other';
$merged = invokeProtected($action, 'mergeFindings', [[$npmRows[0], $anotherPackage]]);
check(count($merged) === 1 && $merged[0]['PACKAGE'] === 'npm-asset/scope--package; npm-asset/other', 'Affected packages lost during deduplication');
check($merged[0]['ID'] === $findings[0]['ID'], 'Severity or package changes altered the advisory identity');
check(VulnerabilityLevelDataType::normalize('unknown') === 'high', 'Unknown severity was understated');
$publicFindings = invokeProtected($action, 'mergeFindings', [array_merge($npmRows, $trivyRows, [$noCve, $folderRows[0]])]);
$anotherNpmId = $folderRows[0];
$anotherNpmId['ID'] = 'npm:456';
$sharedPublicId = invokeProtected($action, 'mergeFindings', [[$folderRows[0], $anotherNpmId]]);
check(count($sharedPublicId) === 1 && $sharedPublicId[0]['PUBLIC_ID'] === 'GHSA-jpcq-cgw6-v4j6', 'Shared public advisory IDs were not deduplicated');
check(array_column(json_decode($sharedPublicId[0]['DETECTIONS'], true, 512, JSON_THROW_ON_ERROR), 'ID') === ['npm:123', 'npm:456'], 'Original scanner IDs were lost from detections');
$singlePublicId = invokeProtected($action, 'mergeFindings', [[$folderRows[0]]]);
check($singlePublicId[0]['ID'] === $sharedPublicId[0]['ID'], 'Adding a detection changed the internal advisory ID');
$table = invokeProtected($action, 'table', [$publicFindings]);
check(strpos($table, 'SOURCE_LEVEL') === false && strpos($table, 'DETAILS_URL') === false, 'Verbose fields leaked into CLI table');
check(strpos($table, 'CVE-2026-0001') !== false && strpos($table, 'CVE') !== false, 'CLI table did not display CVEs');
check(strpos($table, 'PUBLIC ID') !== false && strpos($table, 'GHSA-jpcq-cgw6-v4j6') !== false
    && strpos($table, 'npm:123') === false, 'CLI table did not display the linked public advisory identifier');
check(strpos($table, 'PKSA-generated') !== false && strpos($table, 'EOL:alpine') === false, 'CLI identifier fallback or empty EOL cell failed');
check($folderRows[0]['ID'] === 'npm:123' && $folderRows[0]['CVE'] === '', 'CLI display changed stored identifiers');
check(strpos($table, $publicFindings[0]['ID']) === false, 'Internal generated ID leaked into the public CLI column');
$withCve = $folderRows[0];
$withCve['CVE'] = 'CVE-2020-11023';
check(invokeProtected($action, 'displayAdvisoryId', [$withCve]) === 'CVE-2020-11023', 'CLI did not prefer CVE over a linked GHSA');
$repeatedLinks = $folderRows[0];
$repeatedLinks['DETAILS_URL'] .= '; ' . $repeatedLinks['DETAILS_URL'] . '?source=fixture';
check(invokeProtected($action, 'displayAdvisoryId', [$repeatedLinks]) === 'GHSA-jpcq-cgw6-v4j6', 'Merged details links duplicated the CLI advisory identifier');

$folder = sys_get_temp_dir() . '/exf-audit-test-' . bin2hex(random_bytes(8));
check(mkdir($folder, 0700), 'Cannot create fixture directory');
try {
    $lock = ['packages' => [['name' => 'php/package', 'version' => '1.0.0']], 'packages-dev' => []];
    check(file_put_contents($folder . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR)) !== false, 'Cannot create lock fixture');
    $task = new GenericTask($workbench);
    $task->setParameter('folder', $folder);
    $composer = new FixtureComposerScanner($workbench);
    $composer->response = ['advisories' => ['php/package' => [[
        'cve' => 'CVE-2026-0003', 'advisoryId' => 'PKSA-fixture', 'title' => 'Composer fixture', 'severity' => 'high'
    ]]], 'abandoned' => ['old/package' => 'new/package']];
    check($composer->supports($task), 'Lock-only folder was rejected');
    $rows = $composer->audit($task);
    check(count($rows) === 2 && $rows[1]['TYPE'] === 'EOL', 'Composer findings were not parsed');
    check($rows[0]['CVE'] === 'CVE-2026-0003' && $rows[1]['CVE'] === '', 'Composer CVE or empty abandoned-package CVE was lost');
    check(! is_dir($composer->scannedFolders[0]) && ! is_file($folder . '/composer.json'), 'Temporary Composer files leaked');
    $composer->fail = true;
    try {
        $composer->audit($task);
        throw new RuntimeException('Scanner failure was swallowed');
    } catch (RuntimeException $error) {
        check($error->getMessage() === 'Simulated scanner failure', 'Unexpected failure during cleanup');
    }
    check(! is_dir($composer->scannedFolders[1]), 'Temporary directory leaked after scanner failure');
    $composer->fail = false;
    $composer->stdout = '';
    $composer->stderr = 'Simulated Composer initialization failure';
    try {
        $composer->audit($task);
        throw new RuntimeException('Empty Composer output was accepted');
    } catch (RuntimeException $error) {
        check(strpos($error->getMessage(), 'Composer audit returned invalid JSON (exit code 1)') === 0
            && strpos($error->getMessage(), $composer->stderr) !== false, 'Composer stderr was hidden by a generic JSON error');
    }
    check(! is_dir($composer->scannedFolders[2]), 'Temporary directory leaked after invalid Composer output');
    $composer->stdout = null;
    $composer->stderr = '';
    file_put_contents($folder . '/composer.lock', '{"packages":[],"packages-dev":[]}');
    check($composer->audit($task) === [] && count($composer->scannedFolders) === 3, 'Empty locks should not require JSON output from Composer');
    file_put_contents($folder . '/composer.lock', '{invalid-json');
    try {
        $composer->audit($task);
        throw new RuntimeException('Malformed lock was accepted');
    } catch (RuntimeException $error) {
        check(strpos($error->getMessage(), 'Invalid scanner JSON:') === 0, 'Malformed lock did not fail explicitly');
    }
} finally {
    unlink($folder . '/composer.lock');
    rmdir($folder);
}

$pharFolder = sys_get_temp_dir() . '/exf-composer-phar-test-' . bin2hex(random_bytes(8));
check(mkdir($pharFolder, 0700), 'Cannot create Composer PHAR fixture directory');
define('axenox\\PackageManager\\Audit\\PHP_BINARY', $pharFolder . '/httpd.exe');
try {
    $phar = <<<'PHP'
<?php
if (in_array('--probe-home', $argv, true)) {
    echo getenv('COMPOSER_HOME');
    exit;
}
if (in_array('--version', $argv, true)) {
    echo 'Composer version 2.10.0';
    exit;
}
if (! in_array('audit', $argv, true) || ! in_array('--no-plugins', $argv, true)
    || ! in_array('--no-scripts', $argv, true) || ! is_file('composer.lock')) {
    exit(4);
}
echo json_encode(['advisories' => ['php/package' => [[
    'advisoryId' => 'local-phar', 'title' => 'Local PHAR fixture', 'severity' => 'high',
    'description' => getcwd()
]]], 'abandoned' => []]);
exit(1);
PHP;
    check(file_put_contents($pharFolder . '/composer.phar', $phar) !== false, 'Cannot create Composer PHAR fixture');
    $lockJson = json_encode(['packages' => [['name' => 'php/package', 'version' => '1.0.0']], 'packages-dev' => []], JSON_THROW_ON_ERROR);
    check(file_put_contents($pharFolder . '/composer.lock', $lockJson) !== false, 'Cannot create PHAR lock fixture');
    $pharTask = new GenericTask($workbench);
    $pharTask->setParameter('folder', $pharFolder);
    $localComposer = new ComposerAuditScanner($workbench);
    $lockRows = $localComposer->audit($pharTask);
    check(count($lockRows) === 1 && $lockRows[0]['ID'] === 'local-phar', 'Lock-only scan ignored the project Composer PHAR');
    check($lockRows[0]['DESCRIPTION'] !== realpath($pharFolder) && ! is_dir($lockRows[0]['DESCRIPTION']), 'PHAR lock-only scan lost isolation or temporary cleanup');
    check(file_put_contents($pharFolder . '/composer.json', '{"name":"fixture/project","require":{}}') !== false, 'Cannot create PHAR manifest fixture');
    $manifestRows = $localComposer->audit($pharTask);
    check(count($manifestRows) === 1 && $manifestRows[0]['ID'] === 'local-phar'
        && $manifestRows[0]['DESCRIPTION'] === realpath($pharFolder), 'Manifest scan ignored the project PHAR or working directory');
    $homeProbe = <<<'PHP'
require $argv[1];
$scanner = new \axenox\PackageManager\Audit\ComposerAuditScanner(new \exface\Core\CommonLogic\Workbench());
$method = new \ReflectionMethod($scanner, 'composer');
$method->setAccessible(true);
echo $method->invoke($scanner, ['--probe-home'], $argv[2])['stdout'];
PHP;
    $homeResult = CliCommandRunner::runCliCommandIntoArray(PHP_BINARY, ['-r', $homeProbe, dirname(__DIR__, 3) . '/autoload.php', $pharFolder], __DIR__, [0], 300,
        ['COMPOSER_HOME' => false, 'APPDATA' => false, 'HOME' => false]);
    check($homeResult['stdout'] === $workbench->filemanager()->getPathToDataFolder() . '/.composer', 'Composer home fallback was not supplied to the child process');
    check(file_get_contents($pharFolder . '/composer.lock') === $lockJson, 'Local PHAR scan changed the project lock');
    check(file_put_contents($pharFolder . '/composer.phar', '<?php exit(4);') !== false, 'Cannot create failing PHAR fixture');
    try {
        $localComposer->audit($pharTask);
        throw new RuntimeException('Broken project Composer PHAR was silently bypassed');
    } catch (CliRuntimeException $error) {
        check($error->getExitCode() === 4, 'Project PHAR failure lost its exit code');
    }
} finally {
    foreach (glob($pharFolder . '/*') as $file) {
        unlink($file);
    }
    rmdir($pharFolder);
}

$phpExecutable = CliCommandRunner::findPhpExecutable();
$phpResult = CliCommandRunner::runCliCommandIntoArray($phpExecutable, ['-r', 'echo PHP_SAPI;']);
check($phpResult['stdout'] === 'cli', 'PHP executable resolver did not provide a usable CLI interpreter');
$literalArguments = ['two words', '"quoted"', 'semi;colon', ''];
$literalResult = CliCommandRunner::runCliCommandIntoArray($phpExecutable, array_merge(['-r', 'echo json_encode(array_slice($argv, 1));', '--'], $literalArguments));
check(json_decode($literalResult['stdout'], true, 512, JSON_THROW_ON_ERROR) === $literalArguments, 'Process arguments lost their literal boundaries');
$result = CliCommandRunner::runCliCommandIntoArray(PHP_BINARY, ['-r', 'fwrite(STDOUT, getcwd()); fwrite(STDERR, "diagnostic"); exit(1);'], __DIR__, [0, 1]);
check($result['stderr'] === 'diagnostic' && $result['exit_code'] === 1 && realpath($result['stdout']) === realpath(__DIR__), 'Structured command execution regressed');
$originalComposerHome = getenv('COMPOSER_HOME');
$environmentResult = CliCommandRunner::runCliCommandIntoArray(PHP_BINARY, ['-r', 'echo getenv("COMPOSER_HOME");'], __DIR__, [0], 300, ['COMPOSER_HOME' => 'audit-fixture-home']);
check($environmentResult['stdout'] === 'audit-fixture-home' && getenv('COMPOSER_HOME') === $originalComposerHome, 'Child environment overrides were lost or changed the parent environment');
try {
    CliCommandRunner::runCliCommandIntoArray(PHP_BINARY, ['-r', 'exit(2);'], __DIR__);
    throw new RuntimeException('Unexpected command failure was swallowed');
} catch (CliRuntimeException $error) {
    check($error->getExitCode() === 2, 'Command failure exit code lost');
}
try {
    CliCommandRunner::runCliCommandIntoArray('exf-definitely-missing-audit-command', ['--version']);
    throw new RuntimeException('Missing executable was not reported');
} catch (CliRuntimeException $error) {
    check($error->getExitCode() === 127, 'Missing executable exit code differs across operating systems');
}

echo 'Audit fixture checks passed.' . PHP_EOL;