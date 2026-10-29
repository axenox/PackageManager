<?php
namespace axenox\PackageManager\Tests\PHPUnit\Support;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\TrivySBOMScanner;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use GuzzleHttp\Client;
use PHPUnit\Framework\Assert;

/** Supplies Composer fixtures without contacting advisory servers or changing dependencies. */
class FixtureComposerScanner extends ComposerAuditScanner
{
    public $response;
    public $scannedFolders = [];
    public $fail = false;
    public $stdout = null;
    public $stderr = '';

    /**
     * {@inheritDoc}
     * 
     * @see ComposerAuditScanner::isComposerAvailable()
     */
    protected function isComposerAvailable(string $folder) : bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     * 
     * @see ComposerAuditScanner::runComposer()
     */
    protected function runComposer(array $arguments, string $folder, array $acceptedExitCodes = [0], ?string $composerFolder = null) : array
    {
        $this->scannedFolders[] = $folder;
        Assert::assertFileExists($folder . '/composer.json');
        Assert::assertFileExists($folder . '/composer.lock');
        Assert::assertContains('--no-plugins', $arguments);
        Assert::assertContains('--no-scripts', $arguments);
        Assert::assertSame([0, 1, 2, 3], $acceptedExitCodes);
        if ($this->fail) {
            throw new RuntimeException('Simulated scanner failure');
        }
        return ['stdout' => $this->stdout ?? json_encode($this->response, JSON_THROW_ON_ERROR), 'stderr' => $this->stderr, 'exit_code' => 1];
    }
}

/** Retains real npm input handling and normalization while controlling HTTP responses. */
class FixtureNpmScanner extends ComposerNpmAuditScanner
{
    public $client;
    public $supportChecks = 0;
    public $auditCalls = 0;

    /** {@inheritDoc} @see ComposerNpmAuditScanner::supports() */
    public function supports(TaskInterface $task) : bool
    {
        $this->supportChecks++;
        return parent::supports($task);
    }

    /** {@inheritDoc} @see ComposerNpmAuditScanner::audit() */
    public function audit(TaskInterface $task) : array
    {
        $this->auditCalls++;
        return parent::audit($task);
    }

    /** {@inheritDoc} @see ComposerNpmAuditScanner::createHttpClient() */
    protected function createHttpClient() : Client
    {
        return $this->client;
    }

}

/**
 * Captures Trivy's file boundary without running a subprocess.
 */
class FixtureTrivyScanner extends TrivySBOMScanner
{
    public $artifacts = [];
    public $paths = [];

    /**
     * {@inheritDoc}
     * 
     * @see TrivySBOMScanner::executable()
     */
    protected function executable() : ?string
    {
        return 'fixture-trivy';
    }

    /**
     * {@inheritDoc}
     * 
     * @see TrivySBOMScanner::scanFile()
     */
    protected function scanFile(string $executable, string $path) : array
    {
        $this->paths[] = $path;
        $this->artifacts[] = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return [];
    }
}

/** Keeps synchronous action checks independent of persistent result metaobjects. */
class FixtureAudit extends Audit
{
    public $scanner;
    public $sheet;
    public $findings = [];
    public $resultObject;

    /**
     * Supplies a real in-memory result object without loading the persisted model.
     * 
     * {@inheritDoc}
     * 
     * @see Audit::getResultObjectExpected()
     */
    protected function getResultObjectExpected() : ?\exface\Core\Interfaces\Model\MetaObjectInterface
    {
        return $this->resultObject;
    }

    /** {@inheritDoc} @see Audit::getScanners() */
    protected function getScanners() : array
    {
        return [$this->scanner];
    }

    /** {@inheritDoc} @see Audit::createResultSheet() */
    protected function createResultSheet(array $findings) : DataSheetInterface
    {
        $this->findings = $findings;
        $this->sheet = parent::createResultSheet($findings);
        return $this->sheet;
    }
}