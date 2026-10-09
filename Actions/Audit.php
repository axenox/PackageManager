<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\Audit\MergedFinding;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use axenox\PackageManager\Interfaces\AuditScannerInterface;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\DataSheets\DataCollector;
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
 * Configure scanner classes in AUDIT.SCANNERS. Scanners consume composer_lock and sbom
 * task parameters. Use composer_lock_attribute_alias to supply a lock from input data.
 * Scans complete before returning a compact findings table and status messages.
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
        'DESCRIPTION', 'REMEDIATION', 'VERSIONS_AFFECTED', 'VERSION_INSTALLED', 'VERSION_FIXED', 'PUBLIC_ID'
    ];

    private ?string $composerLockAttributeAlias = null;

    /**
     * Validates input and returns completed findings for standard action processing.
     * 
     * Validation precedes scanner side effects. Scanning and optional JSON export
     * finish before the result DataSheet and collected status messages are returned.
     * 
     * {@inheritDoc}
     * 
     * @see AbstractAction::perform()
     * @param TaskInterface $task
     * @param DataTransactionInterface $transaction
     * @return ResultInterface
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $scanTask = $task->copy();
        if ($task->hasInputData()) {
            $inputData = $this->getInputDataSheet($task);
            $collector = new DataCollector($inputData->getMetaObject());
            if (null !== $inputAttributeForComposerLock = $this->getComposerLockAttributeAlias()) {
                $collector->addAttributeAlias($inputAttributeForComposerLock);
            }
            $collector->enrich($inputData);
            if (null !== $inputAttributeForComposerLock) {
                $composerLock = $inputData->getCellValue($inputAttributeForComposerLock, 0);
                $scanTask->setParameter('composer_lock', $composerLock);
            }
        } else {
            $inputData = null;
        }
        if ($inputData && $inputData->countRows() > 0
            && $task->getParameter('folder') !== null && $task->getParameter('folder') !== '') {
            throw new ActionInputError($this, 'Provide either a folder or artifact input rows, not both.');
        }
        $needInstall = $task->getParameter('install');
        $needOutput = $task->getParameter('output');
        if (($needInstall !== null || $needOutput !== null) && ! ($task instanceof CliTaskInterface)) {
            throw new ActionInputError($this, 'The install and output options are only available from CLI.');
        }
        if ($needOutput !== null && (! is_string($needOutput) || $needOutput === '' || strpos($needOutput, "\0") !== false
            || strtolower(pathinfo($needOutput, PATHINFO_EXTENSION)) !== 'json')) {
            throw new ActionInputError($this, 'Output must be a local .json file path.');
        }
        $scanners = $this->getScanners();
        $installer = null;
        if ($needInstall !== null) {
            $selected = [];
            foreach ($scanners as $scanner) {
                if ($needInstall === get_class($scanner) || $needInstall === (new \ReflectionClass($scanner))->getShortName()) {
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
            $hint = 'No scanner completed an audit. Supply a folder with composer.lock or composer_lock / sbom task parameters and check prerequisites.';
            $hints[] = $hint;
            $messages[] = $hint;
        }
        $sheet = $this->createResultSheet($findings);
        if ($needOutput !== null) {
            $messages[] = 'Saving audit JSON...';
            $this->saveOutput($needOutput, ['findings' => $this->toDataSheetRows($findings), 'hints' => $hints, 'scanners' => $status]);
        }
        $messages[] = $this->table($findings);
        return ResultFactory::createDataResult($task, $sheet, implode(PHP_EOL, $messages));
    }

    /**
     * Instantiates scanners from administrator-controlled configuration.
     * 
     * Configured classes must implement AuditScannerInterface and be instantiable.
     * Duplicate class names produce only one scanner instance.
     * 
     * @return AuditScannerInterface[]
     */
    protected function getScanners() : array
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

    /**
     * Groups findings for this action's package-specific reporting policy.
     * 
     * A group contains one finding type, public advisory identity and package,
     * across scanners. Missing public IDs fall back to scanner-specific native IDs,
     * then titles, to avoid conflating unrelated scanner namespaces. MergedFinding
     * aggregates evidence while keeping the original findings unchanged.
     * 
     * @param FindingInterface[] $findings
    * @return MergedFinding[]
     */
    protected function groupFindings(array $findings) : array
    {
        $groups = [];
        foreach ($findings as $finding) {
            if (! $finding instanceof FindingInterface) {
                throw new ActionConfigurationError($this, 'Audit scanners must return FindingInterface instances.');
            }
            $key = $this->getFindingGroupKey($finding);
            $groups[$key][] = $finding;
        }
        $merged = [];
        foreach ($groups as $group) {
            $merged[] = new MergedFinding($group);
        }
        return $merged;
    }

    /**
    * Builds the action's reporting identity from scanner evidence.
     * 
     * Package names remain exact; advisory identifiers are compared without case.
     * A structured key prevents collisions when values contain delimiters.
    * Aggregates use their first original so combined native IDs do not change the key.
     * 
     * @param FindingInterface $finding
     * @return string
     */
    protected function getFindingGroupKey(FindingInterface $finding) : string
    {
        if ($finding instanceof MergedFinding) {
            $finding = $finding->getMergedFindings()[0];
        }
        $identity = $finding->getPublicId() !== '' ? $finding->getPublicId() : $finding->getSourceId();
        $identity = $identity !== '' ? strtoupper($identity) : $finding->getName();
        $source = $finding->getPublicId() === '' ? $finding->getSource() : '';
        return json_encode([$finding->getType(), $identity, $finding->getPackage(), $source], JSON_THROW_ON_ERROR);
    }

    /**
     * Formats package-specific finding groups in reporting order.
     * 
     * CLI, DataSheet and JSON outputs share this grouping and sorting policy.
     * Other consumers remain free to group the original findings differently.
     * 
     * @param FindingInterface[] $findings
    * @return array<int, array<string, string|int>>
     */
    protected function toDataSheetRows(array $findings) : array
    {
        $rows = array_map(function (FindingInterface $finding) {
            return $this->toDataSheetRow($finding);
        }, $this->groupFindings($findings));
        usort($rows, static function (array $left, array $right) {
            return VulnerabilityLevelDataType::compare($right['LEVEL'], $left['LEVEL'])
                ?: ([$left['PUBLIC_ID'], $left['PACKAGE'], $left['ID']]
                    <=> [$right['PUBLIC_ID'], $right['PACKAGE'], $right['ID']]);
        });
        return $rows;
    }

    /**
     * Converts typed findings into a result DataSheet.
     * 
     * The advisory model is used unless an output object is configured. Clean
     * audits return an empty sheet with the same finding columns.
     * 
     * @param FindingInterface[] $findings
     * @return DataSheetInterface
     */
    protected function createResultSheet(array $findings) : DataSheetInterface
    {
        $object = $this->getResultObjectExpected()
            ?? MetaObjectFactory::createFromString($this->getWorkbench(), 'axenox.PackageManager.AUDIT_ADVISORY');
        $sheet = DataSheetFactory::createFromObject($object);
        foreach (self::COLUMNS as $column) {
            $sheet->getColumns()->addFromExpression($column);
        }
        $sheet->addRows($this->toDataSheetRows($findings));
        return $sheet;
    }

    /**
     * Converts a finding into the action's result row.
     * 
     * Raw and merged findings share the same getter-based format. Aggregation
    * belongs to MergedFinding, not to the result serializer. The action generates
    * the output ID from its grouping key; findings have no internal IDs.
     * 
     * @param FindingInterface $finding
    * @return array<string, string|int>
     */
    protected function toDataSheetRow(FindingInterface $finding) : array
    {
        return [
            'LEVEL' => $finding->getLevel(),
            'ID' => hash('sha256', $this->getFindingGroupKey($finding)),
            'NAME' => $finding->getName(),
            'TYPE' => $finding->getType(),
            'PACKAGE' => $finding->getPackage(),
            'DETAILS_URL' => $finding->getDetailsUrl(),
            'SOURCE' => $finding->getSource(),
            'SOURCE_LEVEL' => $finding->getSourceLevel(),
            'DESCRIPTION' => $finding->getDescription(),
            'REMEDIATION' => $finding->getRemediation(),
            'VERSIONS_AFFECTED' => $finding->getVersionsAffected(),
            'VERSION_INSTALLED' => $finding->getVersionInstalled(),
            'VERSION_FIXED' => $finding->getVersionFixed(),
            'PUBLIC_ID' => $finding->getPublicId()
        ];
    }

    /**
     * Formats findings as a compact console table.
     * 
     * Public advisory identifiers are shown instead of internal hashes.
    * Numeric levels are displayed as severity names without changing result rows.
     * Full URLs and source-native severity labels are omitted.
     * 
     * @param FindingInterface[] $findings
     * @return string
     */
    protected function table(array $findings) : string
    {
        $rows = $this->toDataSheetRows($findings);
        if ($rows === []) {
            return '0 audit findings.';
        }
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders(['LEVEL', 'PUBLIC ID', 'NAME', 'TYPE', 'PACKAGE', 'SOURCE']);
        $table->setColumnMaxWidth(2, 60);
        $table->setColumnMaxWidth(4, 45);
        foreach ($rows as $findingRow) {
            $row = [];
            foreach (['LEVEL', 'PUBLIC_ID', 'NAME', 'TYPE', 'PACKAGE', 'SOURCE'] as $column) {
                $value = $column === 'LEVEL'
                    ? strtolower(VulnerabilityLevelDataType::findConstant($findingRow[$column]))
                    : $findingRow[$column];
                $row[] = \Symfony\Component\Console\Formatter\OutputFormatter::escape(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value));
            }
            $table->addRow($row);
        }
        $table->render();
        return rtrim($output->fetch());
    }

    /**
     * Atomically exports findings and scan completeness as JSON.
     * 
     * Relative paths resolve against the installation. A temporary file prevents
     * a failed write from leaving partial JSON at the requested destination.
     * 
     * @param string $path
    * @param array{findings: array<int, array<string, string|int>>, hints: string[], scanners: array<string, string>} $result
     * @return void
     */
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

    /**
     * {@inheritDoc}
     * 
     * @see iCanBeCalledFromCLI::getCliArguments()
     * @return ServiceParameter[]
     */
    public function getCliArguments() : array
    {
        return [
            new ServiceParameter($this, new UxonObject([
                'name' => 'folder', 
                'description' => 'Folder to audit; defaults to the current installation.'
            ]))
        ];
    }

    /**
     * {@inheritDoc}
     * 
     * @see iCanBeCalledFromCLI::getCliOptions()
     * @return ServiceParameter[]
     */
    public function getCliOptions() : array
    {
        return [
            new ServiceParameter($this, new UxonObject([
                'name' => 'output', 
                'description' => 'Write findings, hints and scanner status to a JSON file.'
                ])),
            new ServiceParameter($this, new UxonObject([
                'name' => 'install', 
                'description' => 'Install prerequisites for one configured scanner (short or full class name).'
                ]))
        ];
    }

    /**
     * {@inheritDoc}
     * 
     * @see AbstractAction::isTriggerWidgetRequired()
     * @return bool|null
     */
    public function isTriggerWidgetRequired() : ?bool
    {
        return false;
    }

    /**
     * @return string|null
     */
    protected function getComposerLockAttributeAlias() : ?string
    {
        return $this->composerLockAttributeAlias;
    }

    /**
     * The alias of the attribute in the input data, that contains the `composer.lock` JSON to audit.
     * 
     * If not set, the action will look for a `composer.lock` file in current installation folder or the provided 
     * `folder` command argument.
     * 
     * @uxon-property composer_lock_attribute_alias
     * @uxon-type metamodel:attribute
     * 
     * @param string|null $alias
     * @return $this
     */
    public function setComposerLockAttributeAlias(?string $alias) : Audit
    {
        $this->composerLockAttributeAlias = $alias;
        return $this;
    }
}