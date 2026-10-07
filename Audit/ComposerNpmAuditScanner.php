<?php
namespace axenox\PackageManager\Audit;

use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Exceptions\RuntimeException;
use GuzzleHttp\Client;

/** Audits locked npm-asset dependencies through npm's advisory API without installing packages or plugins. */
class ComposerNpmAuditScanner extends AbstractAuditScanner
{
    /** Provides the same locked dependency data for folder and archived-build audits. */
    protected function locks(TaskInterface $task) : array
    {
        $folder = $this->folder($task);
        if ($folder !== null) {
            return is_file($folder . '/composer.lock') ? [$this->readJson($folder . '/composer.lock')] : [];
        }
        $locks = [];
        foreach ($this->inputRows($task) as $row) {
            if (isset($row['COMPOSER_LOCK'])) {
                $locks[] = $this->decode($row['COMPOSER_LOCK']);
            }
        }
        return $locks;
    }

    /** Converts Composer's scoped asset notation to npm's registry package names. */
    protected function dependencies(array $lock) : array
    {
        if (! isset($lock['packages']) || ! is_array($lock['packages'])) {
            throw new RuntimeException('COMPOSER_LOCK must contain a packages array.');
        }
        $dependencies = [];
        foreach (array_merge($lock['packages'], $lock['packages-dev'] ?? []) as $package) {
            $name = $package['name'] ?? '';
            if (strpos($name, 'npm-asset/') !== 0) {
                continue;
            }
            $name = substr($name, strlen('npm-asset/'));
            if (strpos($name, '--') !== false) {
                $name = '@' . str_replace('--', '/', $name);
            }
            $version = ltrim((string) ($package['version'] ?? ''), 'v');
            if ($version === '' || strpos($version, 'dev-') === 0) {
                throw new RuntimeException('Cannot audit npm package without a released version: ' . $name);
            }
            $dependencies[$name][] = $version;
        }
        return $dependencies;
    }

    /** {@inheritDoc} @see \axenox\PackageManager\Interfaces\AuditScannerInterface::supports() */
    public function supports(TaskInterface $task) : bool
    {
        foreach ($this->locks($task) as $lock) {
            if ($this->dependencies($lock) !== []) {
                return true;
            }
        }
        return false;
    }

    /** {@inheritDoc} @see \axenox\PackageManager\Interfaces\AuditScannerInterface::audit() */
    public function audit(TaskInterface $task) : array
    {
        $rows = [];
        $client = $this->createHttpClient();
        foreach ($this->locks($task) as $lock) {
            $dependencies = $this->dependencies($lock);
            if ($dependencies === []) {
                continue;
            }
            $response = $client->post('https://registry.npmjs.org/-/npm/v1/security/advisories/bulk', ['json' => $dependencies]);
            $rows = array_merge($rows, $this->normalize($this->decode((string) $response->getBody())));
        }
        return $rows;
    }

    /** Allows the advisory transport to be tested without contacting the public registry. */
    protected function createHttpClient() : Client
    {
        return new Client(['timeout' => 120]);
    }

    /** Retains registry IDs, patched ranges and upgrade recommendations. */
    protected function normalize(array $data) : array
    {
        $rows = [];
        foreach ($data as $package => $advisories) {
            $name = strpos($package, '@') === 0 ? str_replace('/', '--', substr($package, 1)) : $package;
            foreach ($advisories as $advisory) {
                $cves = $advisory['cves'] ?? [];
                if ($cves !== []) {
                    foreach ($cves as $cve) {
                        $advisory['cve'] = $cve;
                        $rows[] = $this->finding('npm', 'npm-asset/' . $name, $advisory);
                    }
                    continue;
                } elseif (isset($advisory['id'])) {
                    $advisory['id'] = 'npm:' . $advisory['id'];
                }
                $rows[] = $this->finding('npm', 'npm-asset/' . $name, $advisory);
            }
        }
        return $rows;
    }

    /** No installation is needed: HTTP audits never modify the target project's Composer setup.
     * {@inheritDoc} @see \axenox\PackageManager\Interfaces\AuditScannerInterface::install()
     */
    public function install(TaskInterface $task) : void
    {
    }
}