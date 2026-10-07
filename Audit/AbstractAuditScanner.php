<?php
namespace axenox\PackageManager\Audit;

use axenox\PackageManager\Interfaces\AuditScannerInterface;
use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Exceptions\CliRuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;

/** Shares artifact handling and prerequisite diagnostics across audit engines. */
abstract class AbstractAuditScanner implements AuditScannerInterface
{
    protected $workbench;
    protected $hints = [];

    /** Keeps scanners independent of the action and its trigger widget. */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    /** {@inheritDoc} @see AuditScannerInterface::getHints() */
    public function getHints() : array
    {
        return array_values(array_unique($this->hints));
    }

    /** Requires explicit artifacts for data-based tasks rather than scanning unrelated installed packages. */
    protected function inputRows(TaskInterface $task) : array
    {
        return $task->hasInputData() ? $task->getInputData()->getRows() : [];
    }

    /** Resolves relative build folders against the installation, not the web server's working directory. */
    protected function folder(TaskInterface $task) : ?string
    {
        $path = $task->hasParameter('folder') ? $task->getParameter('folder') : null;
        if ($path === null || $path === '') {
            if ($this->inputRows($task) !== []) {
                return null;
            }
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

    /** Accepts JSON strings and already decoded artifact values from DataSheets. */
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

    /** Fails on unreadable artifacts instead of returning a misleading clean scan. */
    protected function readJson(string $path) : array
    {
        $value = file_get_contents($path);
        if ($value === false) {
            throw new RuntimeException('Cannot read audit artifact: ' . $path);
        }
        return $this->decode($value);
    }

    /** Prefers the scanned project's Composer PHAR while keeping isolated artifact scans shell-free.
     * Resolves a CLI executable because PHP_BINARY can point to Apache in web consoles.
    * Supplies a Composer home when service accounts lack the usual profile environment.
    * Forwards profile variables explicitly because Apache's getenv() values may not reach child processes.
     */
    protected function composer(array $arguments, string $folder, array $acceptedExitCodes = [0], ?string $composerFolder = null) : array
    {
        $phar = ($composerFolder ?? $folder) . '/composer.phar';
        $local = $this->workbench->getInstallationPath() . '/vendor/composer/composer/bin/composer';
        $script = is_file($phar) ? $phar : (is_file($local) ? $local : null);
        $exec = $script === null ? 'composer' : CliCommandRunner::findPhpExecutable();
        $arguments = array_merge($script === null ? [] : [$script], ['--no-interaction', '--no-ansi'], $arguments);
        $envVars = array_filter([
            'COMPOSER_HOME' => getenv('COMPOSER_HOME'),
            'APPDATA' => getenv('APPDATA'),
            'HOME' => getenv('HOME')
        ], static function ($value) { return is_string($value) && $value !== ''; });
        if (! isset($envVars['COMPOSER_HOME']) && ! isset($envVars[PHP_OS_FAMILY === 'Windows' ? 'APPDATA' : 'HOME'])) {
            $envVars['COMPOSER_HOME'] = $this->workbench->filemanager()->getPathToDataFolder() . '/.composer';
        }
        return CliCommandRunner::runCliCommandIntoArray($exec, $arguments, $folder, $acceptedExitCodes, 300, $envVars);
    }

    /** Only a missing executable is a skippable prerequisite; other command failures must propagate. */
    protected function composerAvailable(string $folder) : bool
    {
        try {
            $result = $this->composer(['--version'], $folder);
        } catch (CliRuntimeException $error) {
            if (! in_array($error->getExitCode(), [127, 9009], true)) {
                throw $error;
            }
            $this->missing('Composer is not available on PATH. Install Composer 2.7 or newer first.');
            return false;
        }
        if (! preg_match('/Composer(?: version)?\s+(\d+\.\d+\.\d+)/i', $result['stdout'], $matches)
            || version_compare($matches[1], '2.7.0', '<')) {
            $this->missing('Composer 2.7 or newer is required.');
            return false;
        }
        return true;
    }

    /** Makes an omitted scan visible and gives the exact opt-in installation command. */
    protected function missing(string $reason) : array
    {
        $name = (new \ReflectionClass($this))->getShortName();
        $this->hints[] = $name . ': ' . $reason . ' Run axenox.PackageManager:Audit [folder] --install=' . $name . '.';
        return [];
    }

    /** Creates the shared result contract while retaining source-specific remediation data. */
    protected function finding(string $source, string $package, array $advisory) : array
    {
        $sourceLevel = (string) ($advisory['severity'] ?? 'unknown');
        $id = (string) ($advisory['cve'] ?? $advisory['id'] ?? $advisory['advisoryId'] ?? '');
        $cve = (string) ($advisory['cve'] ?? $id);
        return [
            'LEVEL' => VulnerabilityLevelDataType::normalize($sourceLevel),
            'ID' => $id,
            'CVE' => preg_match('/^CVE-\d{4}-\d{4,}$/i', $cve) ? strtoupper($cve) : '',
            'NAME' => (string) ($advisory['title'] ?? 'Known vulnerability'),
            'TYPE' => 'vulnerability',
            'PACKAGE' => $package,
            'DETAILS_URL' => (string) ($advisory['link'] ?? $advisory['url'] ?? ''),
            'SOURCE' => $source,
            'SOURCE_LEVEL' => $sourceLevel,
            'DESCRIPTION' => (string) ($advisory['overview'] ?? $advisory['description'] ?? ''),
            'REMEDIATION' => (string) ($advisory['recommendation'] ?? ''),
            'VERSIONS_AFFECTED' => (string) ($advisory['affectedVersions'] ?? $advisory['vulnerable_versions'] ?? ''),
            'VERSION_INSTALLED' => '',
            'VERSION_FIXED' => (string) ($advisory['patched_versions'] ?? '')
        ];
    }
}