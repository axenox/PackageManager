<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\Audit\Scanner\ComposerOutdatedScanner;
use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\ActionFactory;

/**
 * Protects constraint evidence and scan completeness without invoking Composer.
 */
class ComposerOutdatedScannerTest extends AuditTestCase
{
    /**
     * Release-specific construction is available only through the outdated scanner trait.
     * 
     * @return void
     */
    public function testOutdatedHelpersBelongToTheTraitNotGenericScanners() : void
    {
        self::assertFalse(method_exists(\axenox\PackageManager\Common\Audit\Scanner\AbstractAuditScanner::class, 'createOutdatedFinding'));
        self::assertFalse(method_exists(\axenox\PackageManager\Common\Audit\Scanner\LicenseScanner::class, 'createOutdatedFinding'));
        foreach ([ComposerOutdatedScanner::class, \axenox\PackageManager\Common\Audit\Scanner\AbstractRegistryOutdatedScanner::class] as $class) {
            self::assertArrayHasKey(\axenox\PackageManager\Common\Audit\Scanner\OutdateScannerTrait::class,
                (new \ReflectionClass($class))->getTraits());
            self::assertTrue(method_exists($class, 'createOutdatedFinding'));
        }
    }

    /**
     * The action enriches grouped constraint-blocked findings without changing scanner evidence.
     * 
     * @return void
     */
    public function testBlockedReleaseGetsBundleOwnershipAfterGrouping() : void
    {
        $vendor = $this->folder . '/vendor';
        self::assertTrue(mkdir($vendor, 0700));
        try {
            $this->writeFixture($vendor, 'licenses.json', '{"packages":[{"name":"example/library","version":"1.0.0","bundled_in":"composer:example/bundle:3.0.0"}]}');
            $finding = $this->scanner->audit($this->task)[0];
            $action = ActionFactory::createFromString($this->workbench, Audit::class);
            $groups = $this->invokeProtected($action, 'groupFindings', [[$finding]]);
            $this->invokeProtected($action, 'addBundleInformation', [$groups, $this->task]);
            self::assertSame('', $finding->getBundledInUrn());
            self::assertSame($finding, $groups[0]->getMergedFindings()[0]);
            self::assertSame('composer:example/bundle:3.0.0', $groups[0]->getBundledInUrn());
            self::assertSame('upgradable', $finding->getType());
        } finally {
            unlink($vendor . '/licenses.json');
            rmdir($vendor);
        }
    }

    private $scanner;
    private string $folder;
    private GenericTask $task;

    /**
     * Keeps each scenario independent using a real Workbench and a narrow command double.
     * 
     * {@inheritDoc}
     * 
     * @see AuditTestCase::setUp()
     */
    protected function setUp() : void
    {
        parent::setUp();
        $this->folder = $this->temporaryFolder();
        $this->writeFixture($this->folder, 'composer.json', '{"name":"example/project","require":{"example/library":"^1"}}');
        $this->writeFixture($this->folder, 'composer.lock', '{"packages":[{"name":"example/library","version":"1.0.0"}]}');
        $this->task = new GenericTask($this->workbench);
        $this->task->setParameter('folder', $this->folder);
        $this->scanner = new class($this->workbench) extends ComposerOutdatedScanner {
            public array $commands = [];
            public array $packages = [[
                'name' => 'example/library', 'version' => '1.0.0', 'latest' => '2.0.0', 'latest-status' => 'update-possible'
            ]];
            public string $evidence = "example/project - requires example/library (^1)\nexample/consumer 1.2.0 requires example/library (<2)";
            public int $blockerExitCode = 1;
            public ?string $outdatedOutput = null;
            public bool $available = true;

            /**
             * Makes prerequisite coverage testable without inspecting host executables.
             * 
             * {@inheritDoc}
             * 
             * @see ComposerOutdatedScanner::isComposerAvailable()
             */
            protected function isComposerAvailable(string $folder) : bool
            {
                return $this->available;
            }

            /**
             * Doubles only the subprocess boundary; all finding decisions remain real.
             * 
             * {@inheritDoc}
             * 
             * @see ComposerOutdatedScanner::runComposer()
             */
            protected function runComposer(array $arguments, string $folder, array $acceptedExitCodes = [0], ?string $composerFolder = null) : array
            {
                $this->commands[] = [$arguments, $folder, $acceptedExitCodes];
                if (in_array('outdated', $arguments, true)) {
                    return [
                        'stdout' => $this->outdatedOutput ?? json_encode(['locked' => $this->packages], JSON_THROW_ON_ERROR),
                        'stderr' => 'Repository diagnostic', 'exit_code' => 0
                    ];
                }
                return ['stdout' => $this->evidence, 'stderr' => 'Blocker diagnostic', 'exit_code' => $this->blockerExitCode];
            }
        };
    }

    /**
    * Both root and transitive constraints must survive in the optional finding.
     * 
     * @return void
     */
    public function testBlockedUpdateRetainsVersionsAndConstraintOwners() : void
    {
        self::assertTrue($this->scanner->supports($this->task));
        $findings = $this->scanner->audit($this->task);
        self::assertCount(1, $findings);
        $finding = $findings[0];
        self::assertSame('upgradable', $finding->getType());
        self::assertSame('New version 2 available for package example/library', $finding->getName());
        self::assertSame(50, $finding->getLevel());
        self::assertSame('composer', $finding->getSource());
        self::assertSame('example/library', $finding->getPackage());
        self::assertSame('upgradable:example/library:2', $finding->getSourceId());
        self::assertSame('1.0.0', $finding->getVersionInstalled());
        self::assertSame('2.0.0', $finding->getVersionFixed());
        self::assertStringContainsString($this->scanner->evidence, $finding->getDescription());
        self::assertSame([
            '--no-plugins', '--no-scripts', 'outdated', '--locked', '--format=json', '--ignore-platform-reqs'
        ], $this->scanner->commands[0][0]);
        self::assertSame([
            '--no-plugins', '--no-scripts', 'prohibits', '--locked', '--', 'example/library', '2.0.0'
        ], $this->scanner->commands[1][0]);
        self::assertSame([0, 1], $this->scanner->commands[1][2]);
        self::assertSame([], $this->scanner->getHints());
    }

    /**
     * Minor and patch blockers share one advisory title while full versions remain in the evidence.
     * 
     * @return void
     */
    public function testMinorAndPatchUpdatesUseStableOutdatedTitle() : void
    {
        foreach (['1.0.1', 'v1.2.0'] as $latest) {
            $this->scanner->packages[0]['latest'] = $latest;
            $finding = $this->scanner->audit($this->task)[0];
            self::assertSame('outdated', $finding->getType());
            self::assertSame('Update available for package example/library', $finding->getName());
            self::assertSame('outdated:example/library', $finding->getSourceId());
            self::assertSame('1.0.0', $finding->getVersionInstalled());
            self::assertSame($latest, $finding->getVersionFixed());
            self::assertStringContainsString($latest . ' available - currently on 1.0.0', $finding->getDescription());
            self::assertStringContainsString('Newer release blocked by Composer constraints', $finding->getDescription());
            self::assertStringContainsString($this->scanner->evidence, $finding->getDescription());
        }
    }

    /**
     * Newer releases without blockers are ordinary pending updates, not these findings.
     * 
     * @return void
     */
    public function testUnblockedUpdateDoesNotProduceFinding() : void
    {
        $this->scanner->blockerExitCode = 0;
        self::assertSame([], $this->scanner->audit($this->task));
    }

    /**
     * Equal and older candidate versions must never become outdated findings.
     * 
     * @return void
     */
    public function testCurrentAndOlderVersionsDoNotQueryBlockers() : void
    {
        $this->scanner->packages = [
            ['name' => 'example/library', 'version' => '2.0.0', 'latest' => '2.0.0', 'latest-status' => 'up-to-date'],
            ['name' => 'example/other', 'version' => '2.0.0', 'latest' => '1.0.0', 'latest-status' => 'update-possible']
        ];
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(1, $this->scanner->commands);
    }

    /**
     * Archived lock input must not accidentally inspect the installation's constraints.
     * 
     * @return void
     */
    public function testArchivedLockProducesCoverageHintWithoutCommands() : void
    {
        $this->task->setParameter('composer_lock', '{"packages":[]}');
        self::assertTrue($this->scanner->supports($this->task));
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(1, $this->scanner->getHints());
        self::assertSame([], $this->scanner->commands);
    }

    /**
     * Lock-only folders have the same missing root evidence as archived artifacts.
     * 
     * @return void
     */
    public function testFolderWithoutRootManifestProducesCoverageHint() : void
    {
        unlink($this->folder . '/composer.json');
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertCount(1, $this->scanner->getHints());
        self::assertSame([], $this->scanner->commands);
    }

    /**
     * Empty locks must not depend on Composer emitting a JSON response.
     * 
     * @return void
     */
    public function testEmptyLockDoesNotExecuteCommands() : void
    {
        $this->writeFixture($this->folder, 'composer.lock', '{"packages":[],"packages-dev":[]}');
        self::assertSame([], $this->scanner->audit($this->task));
        self::assertSame([], $this->scanner->commands);
    }

    /**
     * Invalid command output must fail with the original diagnostic, not report a clean audit.
     * 
     * @return void
     */
    public function testInvalidJsonRetainsCommandDiagnostic() : void
    {
        $this->scanner->outdatedOutput = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Repository diagnostic');
        $this->scanner->audit($this->task);
    }

    /**
     * A nonzero command without constraint evidence must not create a false finding.
     * 
     * @return void
     */
    public function testMissingBlockerEvidenceFailsExplicitly() : void
    {
        $this->scanner->evidence = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Blocker diagnostic');
        $this->scanner->audit($this->task);
    }

    /**
     * Unexpected response schemas must not silently turn into zero findings.
     * 
     * @return void
     */
    public function testMissingPackageCollectionFailsExplicitly() : void
    {
        $this->scanner->outdatedOutput = '{"installed":[]}';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no locked package array');
        $this->scanner->audit($this->task);
    }
}