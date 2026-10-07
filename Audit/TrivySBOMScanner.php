<?php
namespace axenox\PackageManager\Audit;

use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\DataTypes\ServerSoftwareDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Exceptions\CliRuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use GuzzleHttp\Client;
use axenox\PackageManager\Interfaces\FindingInterface;

/**
 * Audits saved CycloneDX or SPDX SBOMs without unpacking build archives.
 * 
 * Trivy produces vulnerability and operating-system lifecycle findings from
 * folder artifacts or supplied SBOM data. Missing executables produce hints;
 * installation only runs when explicitly requested.
 */
class TrivySBOMScanner extends AbstractAuditScanner
{
    /**
     * Returns the local installation path for the optional Trivy executable.
     * 
     * The binary is stored outside vendor and Composer-managed files.
     * 
     * @return string
     */
    protected function binaryPath() : string
    {
        return $this->workbench->filemanager()->getPathToDataFolder() . '/audit/trivy/'
            . (ServerSoftwareDataType::isOsWindows() ? 'trivy.exe' : 'trivy');
    }

    /**
     * Finds the selected build's SBOM artifact.
     * 
     * Only a current-installation scan uses the global data-folder fallback.
     * Explicit artifact input does not fall back to a folder scan.
     * 
     * @param TaskInterface $task
     * @return string|null
     */
    protected function sbomPath(TaskInterface $task) : ?string
    {
        if ($task->hasParameter('sbom')) {
            return null;
        }
        $folder = $this->getTargetFolder($task);
        if ($folder === null) {
            return null;
        }
        $paths = [$folder . '/sbom.cdx.json', $folder . '/data/sbom.cdx.json'];
        if (realpath($this->workbench->getInstallationPath()) === $folder) {
            $paths[] = $this->workbench->filemanager()->getPathToDataFolder() . '/sbom.cdx.json';
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::supports()
     * @param TaskInterface $task
     * @return bool
     */
    public function supports(TaskInterface $task) : bool
    {
        if ($task->hasParameter('sbom')) {
            return true;
        }
        return $this->sbomPath($task) !== null;
    }

    /**
     * Resolves and checks an available Trivy executable.
     * 
     * Missing binaries produce an installation hint and return null. A broken
     * installed binary remains an error rather than being treated as unavailable.
     * 
     * @return string|null
     */
    protected function executable() : ?string
    {
        if (is_file($this->binaryPath())) {
            CliCommandRunner::runCliCommandIntoArray($this->binaryPath(), ['--version']);
            return $this->binaryPath();
        }
        try {
            CliCommandRunner::runCliCommandIntoArray('trivy', ['--version']);
            return 'trivy';
        } catch (CliRuntimeException $error) {
            if (! in_array($error->getExitCode(), [127, 9009], true)) {
                throw $error;
            }
            $this->missing('Trivy is not installed.');
            return null;
        }
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
        $executable = $this->executable();
        if ($executable === null) {
            return [];
        }
        $path = $this->sbomPath($task);
        if ($path !== null) {
            return $this->scanFile($executable, $path);
        }
        $findings = [];
        if ($task->hasParameter('sbom')) {
            $sbom = $this->decode($task->getParameter('sbom'));
            if (($sbom['bomFormat'] ?? '') !== 'CycloneDX' && ! isset($sbom['spdxVersion'])) {
                throw new RuntimeException('SBOM must be a CycloneDX or SPDX JSON document.');
            }
            $temporary = tempnam(sys_get_temp_dir(), 'exf-sbom-');
            if ($temporary === false) {
                throw new RuntimeException('Cannot create temporary SBOM file.');
            }
            try {
                if (file_put_contents($temporary, json_encode($sbom, JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Cannot write temporary SBOM file.');
                }
                $findings = array_merge($findings, $this->scanFile($executable, $temporary));
            } finally {
                if (! unlink($temporary)) {
                    throw new RuntimeException('Cannot remove temporary SBOM file.');
                }
            }
        }
        return $findings;
    }

    /**
     * Scans an SBOM file and converts Trivy's output into typed findings.
     * 
     * @param string $executable
     * @param string $path
     * @return FindingInterface[]
     */
    protected function scanFile(string $executable, string $path) : array
    {
        $result = CliCommandRunner::runCliCommandIntoArray($executable, ['sbom', '--quiet', '--format', 'json', '--exit-code', '0', $path], null, [0], 900);
        return $this->normalize($this->decode($result['stdout']));
    }

    /**
     * Converts Trivy vulnerability and lifecycle results into typed findings.
     * 
     * Fix versions are retained. npm package names use Composer's asset notation,
     * while operating-system package names remain in their native format.
     * 
     * @param array<string, mixed> $data
     * @return FindingInterface[]
     */
    protected function normalize(array $data) : array
    {
        if (! isset($data['SchemaVersion'])) {
            throw new RuntimeException('Trivy response has no SchemaVersion field.');
        }
        $findings = [];
        foreach ($data['Results'] ?? [] as $result) {
            foreach ($result['Vulnerabilities'] ?? [] as $vulnerability) {
                $findings[] = $this->createFinding($vulnerability, $result['Type'] ?? '');
            }
        }
        $os = $data['Metadata']['OS'] ?? [];
        if (! empty($os['EOSL'])) {
            $package = ($os['Family'] ?? 'OS') . ':' . ($os['Name'] ?? 'unknown');
            $findings[] = new Finding(
                'EOL:' . $package,
                'Operating system is end of life',
                FindingInterface::TYPE_EOL,
                $package,
                'trivy',
                'high',
                '',
                '',
                '',
                'Upgrade to a supported operating system release.'
            );
        }
        return $findings;
    }

    /**
     * Creates a finding directly from a native Trivy vulnerability.
     * 
     * npm identities use Composer asset notation. All other package names and
     * version values retain Trivy's notation without an intermediate advisory map.
     * 
     * @param array{PkgName: string, VulnerabilityID: string, Title?: string, Severity?: string, PrimaryURL?: string, References?: string[], Description?: string, FixedVersion?: string, InstalledVersion?: string, PkgIdentifier?: array{PURL?: string}} $vulnerability
     * @param string $resultType
     * @return FindingInterface
     */
    protected function createFinding(array $vulnerability, string $resultType = '') : FindingInterface
    {
        $package = $vulnerability['PkgName'];
        if (in_array($resultType, ['npm', 'node-pkg', 'yarn', 'pnpm'], true)
            || strpos($vulnerability['PkgIdentifier']['PURL'] ?? '', 'pkg:npm/') === 0) {
            $package = 'npm-asset/' . (strpos($package, '@') === 0 ? str_replace('/', '--', substr($package, 1)) : $package);
        }
        $fixedVersion = (string) ($vulnerability['FixedVersion'] ?? '');
        return new Finding(
            (string) $vulnerability['VulnerabilityID'],
            (string) ($vulnerability['Title'] ?? $vulnerability['VulnerabilityID']),
            FindingInterface::TYPE_VULNERABILITY,
            $package,
            'trivy',
            (string) ($vulnerability['Severity'] ?? 'unknown'),
            (string) ($vulnerability['PrimaryURL'] ?? $vulnerability['References'][0] ?? ''),
            '',
            (string) ($vulnerability['Description'] ?? ''),
            $fixedVersion !== '' ? 'Upgrade to ' . $fixedVersion : '',
            '',
            (string) ($vulnerability['InstalledVersion'] ?? ''),
            $fixedVersion
        );
    }

    /**
     * Installs a checksum-verified official Trivy binary.
     * 
     * Installation is local to the workbench data folder and requires neither
     * elevation nor piping scripts into a shell. Supported hosts are Windows x64
     * and Linux x64 or ARM64.
     * 
     * {@inheritDoc}
     * 
     * @see \axenox\PackageManager\Interfaces\AuditScannerInterface::install()
     * @param TaskInterface $task
     * @return void
     */
    public function install(TaskInterface $task) : void
    {
        $windows = ServerSoftwareDataType::isOsWindows();
        $architecture = strtolower($windows ? (getenv('PROCESSOR_ARCHITEW6432') ?: getenv('PROCESSOR_ARCHITECTURE')) : php_uname('m'));
        if (! in_array($architecture, ['amd64', 'x86_64', 'arm64', 'aarch64'], true)) {
            throw new RuntimeException('Automatic Trivy installation supports x64 and ARM64 hosts only.');
        }
        $arm = in_array($architecture, ['arm64', 'aarch64'], true);
        if ($windows && $arm) {
            throw new RuntimeException('Automatic Trivy installation on Windows requires an x64 host.');
        }
        if (! $windows && PHP_OS_FAMILY !== 'Linux') {
            throw new RuntimeException('Automatic Trivy installation supports Windows and Linux only.');
        }
        $client = new Client(['timeout' => 180, 'headers' => ['User-Agent' => 'axenox-PackageManager-Audit']]);
        $release = $this->decode((string) $client->get('https://api.github.com/repos/aquasecurity/trivy/releases/latest')->getBody());
        $version = ltrim($release['tag_name'] ?? '', 'v');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new RuntimeException('Unexpected Trivy release version.');
        }
        $asset = 'trivy_' . $version . ($windows ? '_windows-64bit.zip' : ($arm ? '_Linux-ARM64.tar.gz' : '_Linux-64bit.tar.gz'));
        $base = 'https://github.com/aquasecurity/trivy/releases/download/v' . $version . '/';
        $checksums = (string) $client->get($base . 'trivy_' . $version . '_checksums.txt')->getBody();
        if (! preg_match('/^([a-f0-9]{64})\s+\*?' . preg_quote($asset, '/') . '\s*$/mi', $checksums, $matches)) {
            throw new RuntimeException('Official checksum is missing for ' . $asset);
        }
        $folder = dirname($this->binaryPath());
        if (! is_dir($folder) && ! mkdir($folder, 0700, true)) {
            throw new RuntimeException('Cannot create Trivy installation folder.');
        }
        $temporary = $folder . '/install-' . bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('Cannot create Trivy download folder.');
        }
        $archive = $temporary . '/' . $asset;
        $binary = $temporary . '/' . ($windows ? 'trivy.exe' : 'trivy');
        try {
            $client->get($base . $asset, ['sink' => $archive]);
            if (! hash_equals(strtolower($matches[1]), hash_file('sha256', $archive))) {
                throw new RuntimeException('Trivy archive checksum verification failed.');
            }
            if ($windows) {
                if (! class_exists(\ZipArchive::class)) {
                    throw new RuntimeException('PHP ZipArchive is required to install Trivy on Windows.');
                }
                $zip = new \ZipArchive();
                if ($zip->open($archive) !== true) {
                    throw new RuntimeException('Cannot open Trivy archive.');
                }
                try {
                    $contents = $zip->getFromName('trivy.exe');
                    if ($contents === false || file_put_contents($binary, $contents) === false) {
                        throw new RuntimeException('Cannot extract Trivy executable.');
                    }
                } finally {
                    $zip->close();
                }
            } else {
                CliCommandRunner::runCliCommandIntoArray('tar', ['-xzf', $archive, '-C', $temporary, 'trivy']);
                if (! chmod($binary, 0700)) {
                    throw new RuntimeException('Cannot make Trivy executable.');
                }
            }
            CliCommandRunner::runCliCommandIntoArray($binary, ['--version']);
            if (! rename($binary, $this->binaryPath())) {
                throw new RuntimeException('Cannot install Trivy executable.');
            }
        } finally {
            foreach (glob($temporary . '/*') as $file) {
                if (! unlink($file)) {
                    throw new RuntimeException('Cannot remove Trivy installation artifact: ' . $file);
                }
            }
            if (! rmdir($temporary)) {
                throw new RuntimeException('Cannot remove Trivy download directory.');
            }
        }
    }
}