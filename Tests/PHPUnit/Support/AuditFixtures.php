<?php
namespace axenox\PackageManager\Tests\PHPUnit\Support;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Audit\ComposerAuditScanner;
use axenox\PackageManager\Audit\ComposerNpmAuditScanner;
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

/** Retains real npm normalization while controlling HTTP responses and archived input rows. */
class FixtureNpmScanner extends ComposerNpmAuditScanner
{
    public $client;
    public $rows = [];
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

    /** {@inheritDoc} @see ComposerNpmAuditScanner::inputRows() */
    protected function inputRows(TaskInterface $task) : array
    {
        return $this->rows;
    }

    /** {@inheritDoc} @see ComposerNpmAuditScanner::createHttpClient() */
    protected function createHttpClient() : Client
    {
        return $this->client;
    }

}

/** Keeps synchronous action checks independent of persistent result metaobjects. */
class FixtureAudit extends Audit
{
    public $scanner;
    public $sheet;
    public $findings = [];

    /** {@inheritDoc} @see Audit::scanners() */
    protected function scanners() : array
    {
        return [$this->scanner];
    }

    /** {@inheritDoc} @see Audit::createResultSheet() */
    protected function createResultSheet(array $findings) : DataSheetInterface
    {
        $this->findings = $findings;
        return $this->sheet;
    }
}