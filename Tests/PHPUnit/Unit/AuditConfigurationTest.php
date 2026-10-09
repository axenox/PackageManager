<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Actions\Audit;
use axenox\PackageManager\Common\Audit\Scanner\AbstractAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\ComposerNpmAuditScanner;
use axenox\PackageManager\Common\Audit\Scanner\TrivySBOMScanner;
use axenox\PackageManager\Interfaces\AuditScannerInterface;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Exceptions\UxonMapError;

/**
 * Covers named scanner configuration without database access, scans or installation.
 */
class AuditConfigurationTest extends AuditTestCase
{
    private $action;

    /**
     * {@inheritDoc}
     * 
     * @see AuditTestCase::setUp()
     */
    protected function setUp() : void
    {
        parent::setUp();
        $this->action = new Audit($this->workbench->getApp('axenox.PackageManager'));
    }

    /**
     * Every built-in scanner is a configurable UXON prototype.
     * 
     * @return void
     */
    public function testBuiltInScannersImportConfiguration() : void
    {
        $configuration = [];
        foreach ([ComposerAuditScanner::class, ComposerNpmAuditScanner::class, TrivySBOMScanner::class] as $class) {
            $configuration[(new \ReflectionClass($class))->getShortName()] = ['class' => '\\' . $class];
        }
        $this->action->importUxonObject(new UxonObject(['scanners' => $configuration]));
        $scanners = $this->invokeProtected($this->action, 'getScanners');
        self::assertSame(array_keys($configuration), array_keys($scanners));
        foreach ($scanners as $scanner) {
            self::assertInstanceOf(AuditScannerInterface::class, $scanner);
            self::assertTrue($scanner->exportUxonObject()->isEmpty());
        }
    }

    /**
     * Installation configuration supplies defaults when the action has no override.
     * 
     * @return void
     */
    public function testGlobalConfigurationSuppliesDefaults() : void
    {
        $configuration = ['npm' => ['class' => ComposerNpmAuditScanner::class]];
        $this->action->getApp()->getConfig()->setOption('AUDIT.SCANNERS', new UxonObject($configuration));
        $scanners = $this->invokeProtected($this->action, 'getScanners');
        self::assertSame(['npm'], array_keys($scanners));
        self::assertInstanceOf(ComposerNpmAuditScanner::class, $scanners['npm']);
    }

    /**
     * An action map replaces global defaults and an empty override disables scanners.
     * 
     * @return void
     */
    public function testActionOverridesReplaceGlobalDefaults() : void
    {
        $this->action->getApp()->getConfig()->setOption('AUDIT.SCANNERS', new UxonObject([
            'composer' => ['class' => ComposerAuditScanner::class]
        ]));
        $this->action->setScanners(['npm' => ['class' => ComposerNpmAuditScanner::class]]);
        self::assertSame(['npm'], array_keys($this->invokeProtected($this->action, 'getScanners')));
        $this->action->setScanners(new UxonObject());
        self::assertSame([], $this->invokeProtected($this->action, 'getScanners'));
    }

    /**
     * Options configure distinct instances without importing or removing the source class selector.
     * 
     * @return void
     */
    public function testScannerOptionsReachIndependentInstances() : void
    {
        $prototype = new class($this->workbench) extends ComposerNpmAuditScanner {
            private $label;

            /**
             * Stores a fixture option through the normal UXON setter mapping.
             * 
             * @uxon-property label
             * @uxon-type string
             * @param string $label
             * @return void
             */
            public function setLabel(string $label) : void
            {
                $this->label = $label;
            }

            /**
             * Returns the imported fixture option.
             * 
             * @return string
             */
            public function getLabel() : string
            {
                return $this->label;
            }
        };
        $configuration = new UxonObject([
            'first' => ['class' => get_class($prototype), 'label' => 'First'],
            'second' => ['class' => get_class($prototype), 'label' => 'Second']
        ]);
        $before = $configuration->toArray();
        $this->action->getApp()->getConfig()->setOption('AUDIT.SCANNERS', $configuration);
        $scanners = $this->invokeProtected($this->action, 'getScanners');
        self::assertNotSame($scanners['first'], $scanners['second']);
        self::assertSame('First', $scanners['first']->getLabel());
        self::assertSame('Second', $scanners['second']->getLabel());
        self::assertSame(['label' => 'First'], $scanners['first']->exportUxonObject()->toArray());
        self::assertSame($before, $configuration->toArray());
    }

    /**
     * Unknown scanner options remain strict UXON mapping errors.
     * 
     * @return void
     */
    public function testUnknownScannerOptionIsRejected() : void
    {
        $this->action->setScanners(['npm' => ['class' => ComposerNpmAuditScanner::class, 'unknown_option' => true]]);
        $this->expectException(UxonMapError::class);
        $this->invokeProtected($this->action, 'getScanners');
    }

    /**
     * Unrelated or abstract classes and missing class selectors fail before scanning.
     * 
     * @dataProvider invalidScannerClasses
     * @param array<string,mixed> $configuration
     * @return void
     */
    public function testInvalidScannerClassIsRejected(array $configuration) : void
    {
        $this->action->setScanners(['invalid' => $configuration]);
        $this->expectException(ActionConfigurationError::class);
        $this->invokeProtected($this->action, 'getScanners');
    }

    /**
     * Supplies invalid class selectors without creating any scanner instances.
     * 
     * @return array<string,array{array<string,mixed>}>
     */
    public static function invalidScannerClasses() : array
    {
        return [
            'missing' => [[]],
            'unknown' => [['class' => 'UnknownAuditScanner']],
            'unrelated' => [['class' => \stdClass::class]],
            'abstract' => [['class' => AbstractAuditScanner::class]]
        ];
    }

    /**
     * Legacy class-name lists must be migrated to named configurations.
     * 
     * @return void
     */
    public function testLegacyClassListIsRejected() : void
    {
        $this->action->setScanners([ComposerNpmAuditScanner::class]);
        $this->expectException(ActionConfigurationError::class);
        $this->invokeProtected($this->action, 'getScanners');
    }
}