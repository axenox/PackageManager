<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\Interfaces\AuditScannerInterface;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Exceptions\Actions\ActionInputError;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\CliTaskInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Factories\ResultFactory;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Finds vulnerabilities and end-of-life dependencies in installations or saved build artifacts.
 * 
 * Configure scanner classes in AUDIT.SCANNERS. Input rows can contain COMPOSER_LOCK and SBOM
 * JSON documents. Scans complete before returning a compact findings table and status messages.
 * Findings are available as a DataSheet for subsequent action mappings.
 * Findings use axenox.PackageManager.AUDIT_ADVISORY by default. Use result_object_alias
 * to select another output object for subsequent action mappings.
 * 
 * CLI: `vendor/bin/action axenox.PackageManager:Audit [folder] --output=vulnerabilities.json`
 * Installation: `vendor/bin/action axenox.PackageManager:Audit [folder] --install=TrivySBOMScanner`
 * 
 * See [audit docs](../Docs/Audit.md) for the result contract, prerequisites and extensibility.
 */
class Audit extends AbstractAction implements iCanBeCalledFromCLI
{
    const COLUMNS = [
        'LEVEL', 'ID', 'NAME', 'TYPE', 'PACKAGE', 'DETAILS_URL', 'SOURCE', 'SOURCE_LEVEL',
        'DESCRIPTION', 'REMEDIATION', 'VERSIONS_AFFECTED', 'VERSION_INSTALLED', 'VERSION_FIXED', 'PUBLIC_ID', 'DETECTIONS'
    ];

    /** Validates input before scanner side effects and returns completed findings for standard action processing.
     * {@inheritDoc} @see AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $scanTask = $task->copy();
        if ($task->hasInputData()) {
            $scanTask->setInputData($this->getInputDataSheet($task));
        }
        if ($scanTask->hasInputData() && $scanTask->getInputData()->countRows() > 0
            && $task->getParameter('folder') !== null && $task->getParameter('folder') !== '') {
            throw new ActionInputError($this, 'Provide either a folder or artifact input rows, not both.');
        }
        $install = $task->getParameter('install');
        $output = $task->getParameter('output');
        if (($install !== null || $output !== null) && ! ($task instanceof CliTaskInterface)) {
            throw new ActionInputError($this, 'The install and output options are only available from CLI.');
        }
        if ($output !== null && (! is_string($output) || $output === '' || strpos($output, "\0") !== false
            || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'json')) {
            throw new ActionInputError($this, 'Output must be a local .json file path.');
        }
        $scanners = $this->scanners();
        $installer = null;
        if ($install !== null) {
            $selected = [];
            foreach ($scanners as $scanner) {
                if ($install === get_class($scanner) || $install === (new \ReflectionClass($scanner))->getShortName()) {
                    $selected[] = $scanner;
                }
            }
            if (count($selected) !== 1) {
                throw new ActionInputError($this, 'Install must name exactly one scanner configured in AUDIT.SCANNERS.');
            }
            $installer = $selected[0];
        }
        $hints = [];
        $messages = [];
        if ($installer !== null) {
            $installerName = (new \ReflectionClass($installer))->getShortName();
            $messages[] = 'Installing prerequisites for ' . $installerName . '...';
            try {
                $installer->install($scanTask);
            } catch (\Throwable $error) {
                throw new ActionRuntimeError($this, 'Audit prerequisite installation failed for ' . get_class($installer) . ': ' . $error->getMessage(), null, $error);
            }
            $hint = 'Prerequisites installed for ' . $installerName . '.';
            $hints[] = $hint;
            $messages[] = $hint;
        }
        $findings = [];
        $status = [];
        foreach ($scanners as $scanner) {
            $name = get_class($scanner);
            $scannerName = (new \ReflectionClass($scanner))->getShortName();
            $messages[] = $scannerName . ': checking input...';
            try {
                if (! $scanner->supports($scanTask)) {
                    $status[$name] = 'not applicable';
                    $messages[] = $scannerName . ': not applicable.';
                    continue;
                }
                $messages[] = $scannerName . ': scanning...';
                $scannerFindings = $scanner->audit($scanTask);
                $findings = array_merge($findings, $scannerFindings);
            } catch (\Throwable $error) {
                $message = 'Audit scanner ' . $name . ' failed: ' . $error->getMessage();
                if ($error instanceof \GuzzleHttp\Exception\RequestException && ($error->getHandlerContext()['errno'] ?? null) === 60) {
                    $message .= ' Configure PHP curl.cainfo / openssl.cafile with a trusted CA bundle.';
                }
                throw new ActionRuntimeError($this, $message, null, $error);
            }
            $scannerHints = $scanner->getHints();
            $hints = array_merge($hints, $scannerHints);
            $status[$name] = $scannerHints === [] ? 'completed' : 'skipped';
            $messages[] = $scannerName . ': ' . $status[$name] . ' (' . count($scannerFindings) . ' findings).';
            foreach (array_unique($scannerHints) as $hint) {
                $messages[] = $hint;
            }
        }
        if (! in_array('completed', $status, true)) {
            $hint = 'No scanner completed an audit. Supply a folder with composer.lock or input columns COMPOSER_LOCK / SBOM and check prerequisites.';
            $hints[] = $hint;
            $messages[] = $hint;
        }
        $findings = $this->mergeFindings($findings);
        $sheet = $this->createResultSheet($findings);
        if ($output !== null) {
            $messages[] = 'Saving audit JSON...';
            $this->saveOutput($output, ['findings' => $findings, 'hints' => $hints, 'scanners' => $status]);
        }
        $messages[] = $this->table($findings);
        return ResultFactory::createDataResult($task, $sheet, implode(PHP_EOL, $messages));
    }

    /** Limits class instantiation to administrator-controlled scanner configuration. */
    protected function scanners() : array
    {
        $classes = $this->getApp()->getConfig()->getOption('AUDIT.SCANNERS');
        if ($classes instanceof UxonObject) {
            $classes = $classes->toArray();
        }
        if (! is_array($classes)) {
            throw new ActionConfigurationError($this, 'AUDIT.SCANNERS must be an array of scanner class names.');
        }
        $scanners = [];
        foreach ($classes as $class) {
            if (! is_string($class) || ! is_subclass_of($class, AuditScannerInterface::class)
                || ! (new \ReflectionClass($class))->isInstantiable()) {
                throw new ActionConfigurationError($this, 'Invalid audit scanner class in AUDIT.SCANNERS.');
            }
            $class = (new \ReflectionClass($class))->getName();
            if (! isset($scanners[$class])) {
                $scanners[$class] = new $class($this->getWorkbench());
            }
        }
        return array_values($scanners);
    }

    /** Generates stable internal IDs while merging public advisory identities and retaining original scanner evidence. */
    protected function mergeFindings(array $rows) : array
    {
        $merged = [];
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        foreach ($rows as $row) {
            foreach (array_slice(self::COLUMNS, 0, 8) as $column) {
                if (! array_key_exists($column, $row) || ! is_string($row[$column])) {
                    throw new ActionConfigurationError($this, 'Audit scanner returned a missing or non-string ' . $column . ' field.');
                }
            }
            $row['LEVEL'] = VulnerabilityLevelDataType::cast($row['LEVEL']);
            foreach (array_merge(array_slice(self::COLUMNS, 8, 6), ['CVE']) as $column) {
                if (isset($row[$column]) && ! is_string($row[$column])) {
                    throw new ActionConfigurationError($this, 'Audit scanner returned a non-string ' . $column . ' field.');
                }
            }
            if (! in_array($row['TYPE'], ['vulnerability', 'EOL'], true)) {
                throw new ActionConfigurationError($this, 'Audit scanner returned an unsupported finding TYPE.');
            }
            $row += array_fill_keys(self::COLUMNS, '');
            unset($row['DETECTIONS']);
            if ($row['PUBLIC_ID'] === '') {
                $row['PUBLIC_ID'] = $this->displayAdvisoryId($row);
            }
            $detection = array_intersect_key($row, array_flip(array_merge(self::COLUMNS, ['CVE'])));
            $row = array_intersect_key($row, array_flip(self::COLUMNS));
            $identity = $row['PUBLIC_ID'] !== '' ? $row['PUBLIC_ID'] : $row['ID'];
            $key = $row['TYPE'] . ':' . ($identity !== '' ? strtoupper($identity) : $row['PACKAGE'] . ':' . $row['NAME']);
            if (! isset($merged[$key])) {
                $merged[$key] = $row;
                $merged[$key]['ID'] = hash('sha256', $key);
                $merged[$key]['DETECTIONS'] = [$detection];
                continue;
            }
            $existing = &$merged[$key];
            if (! in_array($detection, $existing['DETECTIONS'], true)) {
                $existing['DETECTIONS'][] = $detection;
            }
            if ($rank[$row['LEVEL']] < $rank[$existing['LEVEL']]) {
                $existing['LEVEL'] = $row['LEVEL'];
            }
            foreach (['PACKAGE', 'SOURCE', 'SOURCE_LEVEL', 'DETAILS_URL', 'DESCRIPTION', 'REMEDIATION', 'VERSIONS_AFFECTED', 'VERSION_INSTALLED', 'VERSION_FIXED', 'PUBLIC_ID'] as $column) {
                $values = array_filter(array_column($existing['DETECTIONS'], $column), static function ($value) { return $value !== ''; });
                $existing[$column] = implode('; ', array_unique($values));
            }
            unset($existing);
        }
        $findings = array_values($merged);
        usort($findings, static function (array $left, array $right) use ($rank) {
            return [$rank[$left['LEVEL']], $left['PUBLIC_ID'], $left['PACKAGE']] <=> [$rank[$right['LEVEL']], $right['PUBLIC_ID'], $right['PACKAGE']];
        });
        foreach ($findings as &$finding) {
            $finding['DETECTIONS'] = json_encode($finding['DETECTIONS'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        unset($finding);
        return $findings;
    }

    /** Uses the advisory model for typed findings, including clean audits, unless an output object is configured. */
    protected function createResultSheet(array $rows) : DataSheetInterface
    {
        $object = $this->getResultObjectExpected()
            ?? MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.PackageManager.AUDIT_ADVISORY');
        $sheet = DataSheetFactory::createFromObject($object);
        foreach (self::COLUMNS as $column) {
            $sheet->getColumns()->addFromExpression($column);
        }
        $sheet->addRows($rows);
        return $sheet;
    }

    /** Shows recognizable advisory identifiers without exposing full URLs or source-native severities. */
    protected function table(array $findings) : string
    {
        if ($findings === []) {
            return '0 audit findings.';
        }
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders(['LEVEL', 'PUBLIC ID', 'NAME', 'TYPE', 'PACKAGE', 'SOURCE']);
        $table->setColumnMaxWidth(2, 60);
        $table->setColumnMaxWidth(4, 45);
        foreach ($findings as $finding) {
            $row = [];
            foreach (['LEVEL', 'PUBLIC_ID', 'NAME', 'TYPE', 'PACKAGE', 'SOURCE'] as $column) {
                $row[] = \Symfony\Component\Console\Formatter\OutputFormatter::escape(preg_replace('/[\x00-\x1f\x7f]/', ' ', $finding[$column]));
            }
            $table->addRow($row);
        }
        $table->render();
        return rtrim($output->fetch());
    }

    /** Resolves recognizable public identifiers from scanner evidence without fetching linked pages. */
    protected function displayAdvisoryId(array $finding) : string
    {
        if ($finding['TYPE'] === 'EOL') {
            return '';
        }
        if (($finding['CVE'] ?? '') !== '') {
            return $finding['CVE'];
        }
        $identifiers = [];
        foreach (explode('; ', $finding['DETAILS_URL']) as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && preg_match('~(?:^|/)(GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})(?:/|$)~i', $path, $matches)) {
                $identifiers[] = 'GHSA-' . strtolower(substr($matches[1], 5));
            }
        }
        return $identifiers !== [] ? implode('; ', array_unique($identifiers)) : $finding['ID'];
    }

    /** Atomically exports findings and scan completeness without leaving partial JSON files. */
    protected function saveOutput(string $path, array $result) : void
    {
        if (! FilePathDataType::isAbsolute($path)) {
            $path = $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $path;
        }
        if (! is_dir(dirname($path))) {
            throw new ActionInputError($this, 'Audit output directory does not exist.');
        }
        $temporary = tempnam(dirname($path), '.audit-');
        if ($temporary === false) {
            throw new ActionInputError($this, 'Cannot create audit output file.');
        }
        try {
            if (file_put_contents($temporary, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
                || ! rename($temporary, $path)) {
                throw new ActionInputError($this, 'Cannot write audit output: ' . $path);
            }
        } finally {
            if (is_file($temporary) && ! unlink($temporary)) {
                throw new ActionInputError($this, 'Cannot remove temporary audit output file.');
            }
        }
    }

    /** {@inheritDoc} @see iCanBeCalledFromCLI::getCliArguments() */
    public function getCliArguments() : array
    {
        return [new ServiceParameter($this, new UxonObject(['name' => 'folder', 'description' => 'Folder to audit; defaults to the current installation.']))];
    }

    /** {@inheritDoc} @see iCanBeCalledFromCLI::getCliOptions() */
    public function getCliOptions() : array
    {
        return [
            new ServiceParameter($this, new UxonObject(['name' => 'output', 'description' => 'Write findings, hints and scanner status to a JSON file.'])),
            new ServiceParameter($this, new UxonObject(['name' => 'install', 'description' => 'Install prerequisites for one configured scanner (short or full class name).']))
        ];
    }

    /** {@inheritDoc} @see AbstractAction::isTriggerWidgetRequired() */
    public function isTriggerWidgetRequired() : ?bool
    {
        return false;
    }
}