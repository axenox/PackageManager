<?php
namespace axenox\PackageManager\Audit;

use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Exceptions\RuntimeException;

/** Audits locked Composer dependencies without requiring the build to be installed. */
class ComposerAuditScanner extends AbstractAuditScanner
{
    /** {@inheritDoc} @see \axenox\PackageManager\Interfaces\AuditScannerInterface::supports() */
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

    /** {@inheritDoc} @see \axenox\PackageManager\Interfaces\AuditScannerInterface::audit() */
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

    /** Composer audit exit codes 1-3 describe findings/abandoned packages, not execution failure.
     * Keeps the original Composer folder available when lock artifacts are isolated.
     */
    protected function scanFolder(string $folder, ?string $composerFolder = null) : array
    {
        if (! $this->composerAvailable($composerFolder ?? $folder)) {
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
        $result = $this->composer(['--no-plugins', '--no-scripts', 'audit', '--locked', '--format=json', '--abandoned=report'], $folder, [0, 1, 2, 3], $composerFolder);
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
        $rows = [];
        foreach ($data['advisories'] as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $row = $this->finding('composer', $package, $advisory);
                $row['VERSION_INSTALLED'] = $versions[$package] ?? '';
                $rows[] = $row;
            }
        }
        foreach ($data['ignored-advisories'] ?? [] as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $row = $this->finding('composer', $package, $advisory);
                $row['VERSION_INSTALLED'] = $versions[$package] ?? '';
                $row['DESCRIPTION'] = trim($row['DESCRIPTION'] . ' Ignored by Composer policy: ' . ($advisory['ignoreReason'] ?? 'no reason provided'));
                $rows[] = $row;
            }
        }
        foreach ($data['abandoned'] ?? [] as $package => $replacement) {
            $row = $this->finding('composer', $package, ['id' => 'abandoned:' . $package, 'title' => 'Abandoned package', 'severity' => 'unknown']);
            $row['TYPE'] = 'EOL';
            $row['VERSION_INSTALLED'] = $versions[$package] ?? '';
            $row['REMEDIATION'] = $replacement ? 'Replace with ' . $replacement : 'Replace this unmaintained package.';
            $rows[] = $row;
        }
        return $rows;
    }

    /** Composer is a host prerequisite and must not be replaced by an audit action. */
    public function install(TaskInterface $task) : void
    {
        if (! $this->composerAvailable($this->folder($task) ?? $this->workbench->getInstallationPath())) {
            throw new RuntimeException(implode(PHP_EOL, $this->getHints()));
        }
    }
}