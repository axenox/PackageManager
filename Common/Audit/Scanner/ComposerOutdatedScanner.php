<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Reports newer Composer releases blocked by dependency or platform constraints.
 * 
 * Requires a project folder containing composer.json and composer.lock so root
 * constraints and repository configuration remain available. Archived locks alone
 * are skipped with a coverage hint. No packages, plugins or scripts are installed.
 * The timeout_seconds option defaults to 1800 per Composer command because fresh
 * Git repository metadata can take longer than the advisory scanner's five minutes.
 * 
 * Select this prototype in AUDIT.SCANNERS or the action's scanners property:
 * `{"class": "\\axenox\\PackageManager\\Common\\Audit\\Scanner\\ComposerOutdatedScanner"}`.
 */
class ComposerOutdatedScanner extends ComposerAuditScanner
{
    private int $timeoutSeconds = 1800;

    /**
     * Allow enough time for Composer to refresh all configured package repositories.
     * 
     * This positive limit applies to each Composer command, not the entire audit.
     * Cold Git caches may require importing every historical tag before Composer
     * can return outdated packages. Increasing this limit does not speed up that work.
     * 
     * @uxon-property timeout_seconds
     * @uxon-type integer
     * @uxon-default 1800
     * 
     * @param int $seconds
     * @return $this
     */
    public function setTimeoutSeconds(int $seconds) : ComposerOutdatedScanner
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException('Composer outdated timeout_seconds must be greater than zero.');
        }
        $this->timeoutSeconds = $seconds;
        return $this;
    }

    /**
     * Gives repository discovery its configured budget without changing advisory scans.
     * 
     * {@inheritDoc}
     * 
     * @see ComposerAuditScanner::getComposerTimeout()
     * @return float
     */
    protected function getComposerTimeout() : float
    {
        return $this->timeoutSeconds;
    }

    /**
     * Keeps incomplete archived project metadata from producing misleading blockers.
     * 
     * {@inheritDoc}
     * 
     * @see ComposerAuditScanner::audit()
     * @param TaskInterface $task
     * @return FindingInterface[]
     */
    public function audit(TaskInterface $task) : array
    {
        $folder = $this->getTargetFolder($task);
        if ($folder === null || ! is_file($folder . '/composer.json')) {
            $this->hints[] = 'Composer outdated checks require a folder containing composer.json and composer.lock; root constraints and repositories are unavailable from a lock alone.';
            return [];
        }
        return $this->scanFolder($folder);
    }

    /**
     * Uses Composer's dependency graph rather than guessing blockers from version gaps.
     * 
     * Platform requirements are ignored only when discovering newer releases;
     * prohibits then reports the actual dependency and platform blockers. Its exit
     * code 1 means blockers exist, while 0 means the update is not constrained.
     * Native text is retained because prohibits has no JSON output mode.
     * 
     * {@inheritDoc}
     * 
     * @see ComposerAuditScanner::scanFolder()
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
        if (! is_array($lock['packages'] ?? null) || ! is_array($lock['packages-dev'] ?? [])) {
            throw new RuntimeException('Composer lock must contain valid package arrays.');
        }
        if (array_merge($lock['packages'], $lock['packages-dev'] ?? []) === []) {
            return [];
        }
        $result = $this->runComposer([
            '--no-plugins', '--no-scripts', 'outdated', '--locked', '--format=json', '--ignore-platform-reqs'
        ], $folder, [0], $composerFolder);
        try {
            $data = $this->decode($result['stdout']);
        } catch (RuntimeException $error) {
            throw new RuntimeException('Composer outdated returned invalid JSON: ' . trim($result['stderr']), null, $error);
        }
        if (! is_array($data['locked'] ?? null)) {
            throw new RuntimeException('Composer outdated response has no locked package array.');
        }
        $findings = [];
        foreach ($data['locked'] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)
                || ! is_string($package['version'] ?? null) || ! is_string($package['latest'] ?? null)) {
                throw new RuntimeException('Composer outdated returned invalid package metadata.');
            }
            if (($package['latest-status'] ?? '') === 'up-to-date'
                || ! version_compare($package['latest'], $package['version'], '>')) {
                continue;
            }
            $blockers = $this->runComposer([
                '--no-plugins', '--no-scripts', 'prohibits', '--locked', '--', $package['name'], $package['latest']
            ], $folder, [0, 1], $composerFolder);
            if ($blockers['exit_code'] === 0) {
                continue;
            }
            $evidence = trim($blockers['stdout']);
            if ($evidence === '') {
                throw new RuntimeException('Composer prohibits failed without blocker evidence: ' . trim($blockers['stderr']));
            }
            $findings[] = new Finding(
                'outdated:' . $package['name'],
                'Newer release blocked by Composer constraints',
                AdvisoryTypeDataType::OUTDATED,
                $package['name'],
                'composer',
                'low',
                '',
                '',
                'Locked version: ' . $package['version'] . '. Latest release: ' . $package['latest']
                    . '.' . PHP_EOL . 'Composer prohibits:' . PHP_EOL . $evidence,
                'Review the reported package and platform constraints before updating to ' . $package['latest']
                    . '; dependent packages may need upgrading first.',
                '',
                $package['version'],
                $package['latest']
            );
        }
        return $findings;
    }
}