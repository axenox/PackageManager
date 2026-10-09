<?php
namespace axenox\PackageManager\Actions;

use axenox\PackageManager\Common\Audit\MergedFinding;
use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;
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
 * Configure named scanners in AUDIT.SCANNERS or override them with the scanners property.
 * Each scanner configuration selects a class and supplies its UXON options.
 * Scanners consume composer_lock and sbom
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
    /**
     * Composer lock artifact supplied as JSON, a decoded array or UxonObject.
     */
    public const TASK_PARAM_COMPOSER_LOCK = 'composer_lock';

    /**
     * Combined package, CycloneDX or SPDX artifact supported by the selected scanner.
     */
    public const TASK_PARAM_SBOM = 'sbom';

    /**
     * Local build directory; defaults to the installation when no artifact is supplied.
     */
    public const TASK_PARAM_FOLDER = 'folder';

    /**
     * CLI-only scanner selector for explicit prerequisite installation.
     */
    public const TASK_PARAM_INSTALL = 'install';

    /**
     * CLI-only destination path for findings, hints and scanner status JSON.
     */
    public const TASK_PARAM_OUTPUT = 'output';
    
    const COLUMNS = [
        'LEVEL', 'ID', 'NAME', 'TYPE', 'PACKAGE', 'DETAILS_URL', 'SOURCE', 'SOURCE_LEVEL',
        'DESCRIPTION', 'REMEDIATION', 'VERSIONS_AFFECTED', 'VERSION_INSTALLED', 'VERSION_FIXED', 'PUBLIC_ID'
    ];

    private ?string $composerLockAttributeAlias = null;

    private ?UxonObject $scannersUxon = null;

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
                $scanTask->setParameter(self::TASK_PARAM_COMPOSER_LOCK, $composerLock);
            }
        } else {
            $inputData = null;
        }
        if ($inputData && $inputData->countRows() > 0
            && $task->getParameter(self::TASK_PARAM_FOLDER) !== null && $task->getParameter(self::TASK_PARAM_FOLDER) !== '') {
            throw new ActionInputError($this, 'Provide either a folder or artifact input rows, not both.');
        }
        $needInstall = $task->getParameter(self::TASK_PARAM_INSTALL);
        $needOutput = $task->getParameter(self::TASK_PARAM_OUTPUT);
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
            foreach ($scanners as $key => $scanner) {
                if ($needInstall === $key || $needInstall === get_class($scanner) || $needInstall === (new \ReflectionClass($scanner))->getShortName()) {
                    $selected[] = $scanner;
                }
            }
            if (count($selected) !== 1) {
                throw new ActionInputError($this, 'Install must name exactly one configured audit scanner.');
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
        foreach ($scanners as $key => $scanner) {
            $name = is_string($key) ? $key : get_class($scanner);
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
            $messages[] = $scannerName . ': ' . count($scannerFindings) . ' findings.';
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
            $this->saveOutput($needOutput, $sheet, $hints, $status);
        }
        $messages[] = $this->table($sheet);
        return ResultFactory::createDataResult($task, $sheet, implode(PHP_EOL, $messages));
    }

    /**
     * Instantiates scanners from administrator-controlled configuration.
     * 
     * Configured classes must implement AuditScannerInterface and be instantiable.
     * Each named entry creates its own instance and imports all options except class.
     * An action-specific map replaces the installation defaults, including an empty map.
     * 
     * @return array<string,AuditScannerInterface>
     */
    protected function getScanners() : array
    {
        $configurations = $this->scannersUxon ?? $this->getApp()->getConfig()->getOption('AUDIT.SCANNERS');
        if ($configurations instanceof UxonObject) {
            $configurations = $configurations->toArray();
        }
        if (! is_array($configurations)) {
            throw new ActionConfigurationError($this, 'Audit scanners must be a named map of scanner configurations.');
        }
        $scanners = [];
        foreach ($configurations as $name => $configuration) {
            if (! is_string($name) || $name === '' || (! is_array($configuration) && ! $configuration instanceof UxonObject)) {
                throw new ActionConfigurationError($this, 'Audit scanners must be a named map of scanner configurations.');
            }
            $uxon = $configuration instanceof UxonObject ? $configuration->copy() : new UxonObject($configuration);
            $class = $uxon->getProperty('class');
            if (! is_string($class) || ! is_subclass_of($class, AuditScannerInterface::class)
                || ! (new \ReflectionClass($class))->isInstantiable()) {
                throw new ActionConfigurationError($this, 'Invalid audit scanner class for "' . $name . '".');
            }
            $scanner = new $class($this->getWorkbench());
            $uxon->unsetProperty('class');
            $scanner->importUxonObject($uxon);
            $scanners[$name] = $scanner;
        }
        return $scanners;
    }

    /**
     * Configure the named scanners used by this action instead of installation defaults.
     * 
     * Each entry requires a class and may include that scanner's configuration options.
     * Keys identify scanners in status output and can select a scanner for CLI installation.
     * The map replaces AUDIT.SCANNERS; an empty map disables all scanners.
     * When omitted, the action uses the installation's AUDIT.SCANNERS option.
     * 
     * @uxon-property scanners
     * @uxon-type object
     * @uxon-template {"composer": {"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\ComposerAuditScanner"}}
     * 
     * @param UxonObject|array<string,array<string,mixed>> $uxonOrArray
     * @return Audit
     */
    public function setScanners($uxonOrArray) : Audit
    {
        $this->scannersUxon = $uxonOrArray instanceof UxonObject ? $uxonOrArray->copy() : new UxonObject($uxonOrArray);
        return $this;
    }

    /**
     * Groups findings for this action's package-specific reporting policy.
     * 
     * A group contains one finding type and package, matched by advisory identity
     * or a nonempty title. Package names, public IDs and titles are compared without
     * case. Matches can link existing groups across scanner-specific public IDs.
     * Missing public IDs still use scanner-specific native identity for the primary
     * match. MergedFinding retains the original findings and their public identities.
     * 
     * @param FindingInterface[] $findings
     * @return MergedFinding[]
     */
    protected function groupFindings(array $findings) : array
    {
        $groups = [];
        $matches = [];
        $nextGroup = 0;
        foreach ($findings as $finding) {
            if (! $finding instanceof FindingInterface) {
                throw new ActionConfigurationError($this, 'Audit scanners must return FindingInterface instances.');
            }
            $originals = $finding instanceof MergedFinding ? $finding->getMergedFindings() : [$finding];
            foreach ($originals as $original) {
                $keys = ['identity:' . $this->getFindingGroupKey($original)];
                if ($original->getName() !== '') {
                    $keys[] = 'title:' . json_encode([
                        $original->getType(), strtolower($original->getPackage()), strtolower($original->getName())
                    ], JSON_THROW_ON_ERROR);
                }
                $matchingGroups = [];
                foreach ($keys as $key) {
                    if (isset($matches[$key])) {
                        $matchingGroups[] = $matches[$key];
                    }
                }
                $matchingGroups = array_unique($matchingGroups);
                $group = $matchingGroups !== [] ? min($matchingGroups) : $nextGroup++;
                foreach ($matchingGroups as $matchingGroup) {
                    if ($matchingGroup === $group) {
                        continue;
                    }
                    $groups[$group] = array_merge($groups[$group], $groups[$matchingGroup]);
                    unset($groups[$matchingGroup]);
                    foreach ($matches as $key => $mappedGroup) {
                        if ($mappedGroup === $matchingGroup) {
                            $matches[$key] = $group;
                        }
                    }
                }
                $groups[$group][] = $original;
                foreach ($keys as $key) {
                    $matches[$key] = $group;
                }
            }
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
      * Package names and advisory identifiers are compared without case.
      * A structured key prevents collisions when values contain delimiters.
      * Aggregates use the smallest original key so title-based merges have stable
      * output IDs regardless of scanner order or combined native identifiers.
      * 
      * @param FindingInterface $finding
      * @return string
      */
    protected function getFindingGroupKey(FindingInterface $finding) : string
    {
        if ($finding instanceof MergedFinding) {
            $keys = array_map(function (FindingInterface $original) {
                return $this->getFindingGroupKey($original);
            }, $finding->getMergedFindings());
            sort($keys, SORT_STRING);
            return $keys[0];
        }
        $identity = $finding->getPublicId() !== '' ? $finding->getPublicId() : $finding->getSourceId();
        $identity = $identity !== '' ? strtoupper($identity) : $finding->getName();
        $source = $finding->getPublicId() === '' ? $finding->getSource() : '';
        return json_encode([$finding->getType(), $identity, strtolower($finding->getPackage()), $source], JSON_THROW_ON_ERROR);
    }

     /**
      * Serializes package-specific finding groups without choosing their row order.
      * 
      * The result DataSheet owns sorting. Arrays are only used when inserting rows
      * into that sheet or serializing an external output.
      * 
      * @param FindingInterface[] $findings
      * @return array<int, array<string, string|int>>
      */
    protected function toDataSheetRows(array $findings) : array
    {
        return array_map(function (FindingInterface $finding) {
            return $this->toDataSheetRow($finding);
        }, $this->groupFindings($findings));
    }

    /**
     * Converts typed findings into a result DataSheet.
     * 
     * The advisory model is used unless an output object is configured. Clean
     * audits return an empty sheet with the same finding columns. The sheet sorts
     * the already normalized finding values and is reused by CLI and JSON output.
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
        $sheet->getSorters()->addFromString('LEVEL', 'DESC');
        $sheet->getSorters()->addFromString('PACKAGE', 'ASC');
        $sheet->getSorters()->addFromString('PUBLIC_ID', 'ASC');
        $sheet->getSorters()->addFromString('ID', 'ASC');
        return $sheet->sort(null, false);
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
     * Formats the sorted result DataSheet as a compact console table.
     * 
     * Public advisory identifiers are shown instead of internal hashes.
    * Levels and advisory types use translated names without changing result rows.
     * Full URLs and source-native severity labels are omitted.
     * 
     * @param DataSheetInterface $sheet
     * @return string
     */
    protected function table(DataSheetInterface $sheet) : string
    {
        if ($sheet->isEmpty()) {
            return '0 audit findings.';
        }
        $output = new BufferedOutput();
        $table = new Table($output);
        $table->setHeaders(['LEVEL', 'PUBLIC ID', 'NAME', 'TYPE', 'PACKAGE', 'SOURCE']);
        $table->setColumnMaxWidth(2, 60);
        $table->setColumnMaxWidth(4, 45);
        foreach ($sheet->getRows() as $findingRow) {
            $row = [];
            foreach (['LEVEL', 'PUBLIC_ID', 'NAME', 'TYPE', 'PACKAGE', 'SOURCE'] as $column) {
                $value = $findingRow[$column];
                if ($column === 'LEVEL') {
                    $value = VulnerabilityLevelDataType::getLabelOfValueStatic($sheet->getWorkbench(), $value) ?? $value;
                } elseif ($column === 'TYPE') {
                    $value = AdvisoryTypeDataType::getLabelOfValueStatic($sheet->getWorkbench(), $value) ?? $value;
                }
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
     * @param DataSheetInterface $sheet The already sorted result DataSheet.
     * @param string[] $hints
     * @param array<string, string> $scanners
     * @return void
     */
    protected function saveOutput(string $path, DataSheetInterface $sheet, array $hints, array $scanners) : void
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
            $result = ['findings' => $sheet->getRows(), 'hints' => $hints, 'scanners' => $scanners];
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
                'name' => self::TASK_PARAM_FOLDER, 
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
                'name' => self::TASK_PARAM_OUTPUT, 
                'description' => 'Write findings, hints and scanner status to a JSON file.'
                ])),
            new ServiceParameter($this, new UxonObject([
                'name' => self::TASK_PARAM_INSTALL, 
                'description' => 'Install prerequisites for one configured scanner (map key, short or full class name).'
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