<?php
namespace axenox\PackageManager\Audit;

use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Exceptions\CliRuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use axenox\PackageManager\Interfaces\FindingInterface;

/**
 * Audits locked Composer dependencies without requiring the build to be installed.
 * 
 * Folder scans and archived locks use Composer's advisory results. Lock-only
 * scans run in isolated temporary projects without executing plugins or scripts.
 * Abandoned packages are retained as end-of-life findings.
 */
class ComposerAuditScanner extends AbstractAuditScanner
{
    /**
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::supports()
     * @param TaskInterface $task
     * @return bool
     */
    public function supports(TaskInterface $task) : bool
    {
        foreach ($this->inputRows($task) as $row) {
            if (isset($row['COMPOSER_LOCK'])) {
                return true;
            }
        }
        $folder = $this->folder($task);
        return $folder !== null && is_file($folder . '/composer.lock');
    }

    /**
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::audit()
     * @param TaskInterface $task
     * @return FindingInterface[]
     */
    public function audit(TaskInterface $task) : array
    {
        $folder = $this->folder($task);
        if ($folder !== null && is_file($folder . '/composer.json')) {
            return $this->scanFolder($folder);
        }
        $findings = [];
        $inputs = $folder === null ? $this->inputRows($task) : [['COMPOSER_LOCK' => $this->readJson($folder . '/composer.lock')]];
        foreach ($inputs as $row) {
            if (! isset($row['COMPOSER_LOCK'])) {
                continue;
            }
            $lock = $this->decode($row['COMPOSER_LOCK']);
            if (! isset($lock['packages']) || ! is_array($lock['packages'])) {
                throw new RuntimeException('COMPOSER_LOCK must contain a packages array.');
            }
            $temporary = tempnam(sys_get_temp_dir(), 'exf-audit-');
            if ($temporary === false || ! unlink($temporary) || ! mkdir($temporary, 0700)) {
                throw new RuntimeException('Cannot create temporary Composer audit directory.');
            }
            try {
                if (file_put_contents($temporary . '/composer.json', '{"name":"audit/locked-build","require":{}}') === false
                    || file_put_contents($temporary . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Cannot write temporary Composer audit artifacts.');
                }
                $findings = array_merge($findings, $this->scanFolder($temporary, $folder));
            } finally {
                foreach (glob($temporary . '/*') as $file) {
                    if (! unlink($file)) {
                        throw new RuntimeException('Cannot remove temporary audit artifact: ' . $file);
                    }
                }
                if (! rmdir($temporary)) {
                    throw new RuntimeException('Cannot remove temporary audit directory.');
                }
            }
        }
        return $findings;
    }

    /**
     * Audits the locked dependencies in a Composer project folder.
     * 
     * Composer audit exit codes 1-3 describe findings or abandoned packages,
     * not execution failure. The original Composer folder remains available
     * for PHAR resolution when lock artifacts are isolated.
     * 
     * @param string $folder
     * @param string|null $composerFolder
     * @return FindingInterface[]
     */
    protected function scanFolder(string $folder, ?string $composerFolder = null) : array
    {
        if (! $this->isComposerAvailable($composerFolder ?? $folder)) {
            return [];
        }
        $lock = $this->readJson($folder . '/composer.lock');
        if (! isset($lock['packages']) || ! is_array($lock['packages'])
            || (isset($lock['packages-dev']) && ! is_array($lock['packages-dev']))) {
            throw new RuntimeException('Composer lock must contain valid package arrays.');
        }
        $versions = [];
        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            $versions[$package['name']] = $package['version'] ?? '';
        }
        if ($versions === []) {
            return [];
        }
        $result = $this->runComposer(['--no-plugins', '--no-scripts', 'audit', '--locked', '--format=json', '--abandoned=report'], $folder, [0, 1, 2, 3], $composerFolder);
        try {
            $data = $this->decode($result['stdout']);
        } catch (RuntimeException $error) {
            $diagnostic = trim($result['stderr']);
            throw new RuntimeException('Composer audit returned invalid JSON (exit code ' . $result['exit_code'] . '): '
                . ($diagnostic !== '' ? $diagnostic : $error->getMessage()), null, $error);
        }
        if (! array_key_exists('advisories', $data)) {
            throw new RuntimeException('Composer audit response has no advisories field.');
        }
        $findings = [];
        foreach ($data['advisories'] as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $findings[] = $this->createFinding($package, $advisory, $versions[$package] ?? '');
            }
        }
        foreach ($data['ignored-advisories'] ?? [] as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $findings[] = $this->createFinding($package, $advisory, $versions[$package] ?? '', true);
            }
        }
        foreach ($data['abandoned'] ?? [] as $package => $replacement) {
            $findings[] = new Finding(
                'abandoned:' . $package,
                'Abandoned package',
                FindingInterface::TYPE_EOL,
                $package,
                'composer',
                'unknown',
                '',
                '',
                '',
                $replacement ? 'Replace with ' . $replacement : 'Replace this unmaintained package.',
                '',
                $versions[$package] ?? ''
            );
        }
        return $findings;
    }

    /**
     * Creates a finding directly from a native Composer advisory.
     * 
     * Ignored advisories retain the policy reason without rewriting the response.
     * The finding handles severity normalization and public identifier resolution.
     * 
     * @param string $package
     * @param array{advisoryId?: string, cve?: string|null, title?: string, severity?: string, link?: string, description?: string, affectedVersions?: string, ignoreReason?: string} $advisory
     * @param string $versionInstalled
     * @param bool $ignored
     * @return FindingInterface
     */
    protected function createFinding(string $package, array $advisory, string $versionInstalled = '', bool $ignored = false) : FindingInterface
    {
        $description = (string) ($advisory['description'] ?? '');
        if ($ignored) {
            $description = trim($description . ' Ignored by Composer policy: ' . ($advisory['ignoreReason'] ?? 'no reason provided'));
        }
        return new Finding(
            (string) ($advisory['cve'] ?? $advisory['advisoryId'] ?? ''),
            (string) ($advisory['title'] ?? 'Known vulnerability'),
            FindingInterface::TYPE_VULNERABILITY,
            $package,
            'composer',
            (string) ($advisory['severity'] ?? 'unknown'),
            (string) ($advisory['link'] ?? ''),
            (string) ($advisory['cve'] ?? ''),
            $description,
            '',
            (string) ($advisory['affectedVersions'] ?? ''),
            $versionInstalled
        );
    }

    /**
     * Runs Composer while preferring the scanned project's PHAR.
     * 
     * Isolated artifact scans remain shell-free and can retain the original
     * project folder for PHAR resolution.
     * Resolves a CLI executable because PHP_BINARY can point to Apache in web consoles.
     * Supplies a Composer home when service accounts lack the usual profile environment.
     * Forwards profile variables explicitly because Apache's getenv() values may not reach child processes.
     * 
     * @param string[] $arguments
     * @param string $folder
     * @param int[] $acceptedExitCodes
     * @param string|null $composerFolder
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    protected function runComposer(array $arguments, string $folder, array $acceptedExitCodes = [0], ?string $composerFolder = null) : array
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

    /**
     * Checks whether a supported Composer version is available.
     * 
     * Missing or outdated Composer produces a prerequisite hint. Other command
     * failures propagate instead of being treated as a skipped scan.
     * 
     * @param string $folder
     * @return bool
     */
    protected function isComposerAvailable(string $folder) : bool
    {
        try {
            $result = $this->runComposer(['--version'], $folder);
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

    /**
     * Verifies Composer as a host prerequisite without replacing it.
     * 
     * Composer must already be installed at a supported version. This method
     * reports missing prerequisites rather than modifying the host installation.
     * 
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::install()
     * @param TaskInterface $task
     * @return void
     */
    public function install(TaskInterface $task) : void
    {
        if (! $this->isComposerAvailable($this->folder($task) ?? $this->workbench->getInstallationPath())) {
            throw new RuntimeException(implode(PHP_EOL, $this->getHints()));
        }
    }
}