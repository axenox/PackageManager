<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Interfaces\AuditScannerInterface;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Traits\iCanBeConvertedToUxonTrait;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;

/**
 * Shares artifact handling and prerequisite diagnostics across audit engines.
 * 
 * Subclasses implement scanner-specific input detection, auditing and installation.
 * The shared helpers resolve task inputs without depending on an action or
 * trigger widget. Engine-specific execution belongs to the concrete scanners.
 * Scanner options are configured through UXON setters after construction; the
 * action removes the class selector before importing the remaining properties.
 */
abstract class AbstractAuditScanner implements AuditScannerInterface
{
    use iCanBeConvertedToUxonTrait;

    /**
     * @var WorkbenchInterface
     */
    protected $workbench;

    /**
     * @var string[]
     */
    protected $hints = [];

    /**
     * Initializes the scanner with its workbench.
     * 
     * Scanners use the workbench for installation paths and services without
     * depending on an action or its trigger widget.
     * 
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    /**
     * {@inheritDoc}
     * 
     * @see AuditScannerInterface::getHints()
     * @return string[]
     */
    public function getHints() : array
    {
        return array_values(array_unique($this->hints));
    }

    /**
     * Resolves a build folder against the installation directory.
     * 
     * Explicit artifact input returns null instead of falling back to the
     * installation. Relative paths do not depend on the server's working directory.
     * 
     * @param TaskInterface $task
     * @return string|null
     */
    protected function getTargetFolder(TaskInterface $task) : ?string
    {
        if ($task->hasParameter(Audit::TASK_PARAM_COMPOSER_LOCK) || $task->hasParameter(Audit::TASK_PARAM_SBOM)) {
            return null;
        }
        $path = $task->hasParameter(Audit::TASK_PARAM_FOLDER) ? $task->getParameter(Audit::TASK_PARAM_FOLDER) : null;
        if ($path === null || $path === '') {
            $path = $this->workbench->getInstallationPath();
        }
        if (! is_string($path) || strpos($path, "\0") !== false) {
            throw new RuntimeException('Audit folder must be a local directory path.');
        }
        if (! FilePathDataType::isAbsolute($path)) {
            $path = $this->workbench->getInstallationPath() . DIRECTORY_SEPARATOR . $path;
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('Audit folder does not exist: ' . $path);
        }
        return $resolved;
    }

    /**
     * Decodes scanner JSON or accepts an already decoded artifact value.
     * 
     * @param string|array|UxonObject $value
     * @return array<array-key, mixed>
     */
    protected function decode($value) : array
    {
        if ($value instanceof UxonObject) {
            $value = $value->toArray();
        }
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new RuntimeException('Invalid scanner JSON: ' . $error->getMessage(), null, $error);
            }
        }
        if (! is_array($value)) {
            throw new RuntimeException('Scanner artifact or response must be a JSON object or array.');
        }
        return $value;
    }

    /**
     * Reads and decodes a JSON artifact.
     * 
     * Unreadable or invalid artifacts fail instead of producing a misleading clean scan.
     * 
     * @param string $path
     * @return array<array-key, mixed>
     */
    protected function readJson(string $path) : array
    {
        $value = file_get_contents($path);
        if ($value === false) {
            throw new RuntimeException('Cannot read audit artifact: ' . $path);
        }
        return $this->decode($value);
    }

    /**
     * Records a prerequisite hint and returns an empty findings list.
     * 
     * The hint makes an omitted scan visible and includes the opt-in installation command.
     * 
     * @param string $reason
     * @return FindingInterface[]
     */
    protected function missing(string $reason) : array
    {
        $name = (new \ReflectionClass($this))->getShortName();
        $this->hints[] = $name . ': ' . $reason . ' Run axenox.PackageManager:Audit [folder] --install=' . $name . '.';
        return [];
    }

}