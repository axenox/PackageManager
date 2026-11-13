<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\StaticInstaller;
use PHPUnit\Framework\TestCase;

/**
 * Covers how the package manager registers its Composer scripts in the root `composer.json` of an installation.
 */
class PackageManagerInstallerTest extends TestCase
{
    const TIMEOUT = 'Composer\\Config::disableProcessTimeout';
    
    const CMD_INSTALL = '@php vendor/axenox/packagemanager/bin/composer-script.php composerFinishInstall';
    
    const CMD_UPDATE = '@php vendor/axenox/packagemanager/bin/composer-script.php composerFinishUpdate';
    
    const CB_PACKAGE_INSTALL = 'axenox\\PackageManager\\StaticInstaller::composerFinishPackageInstall';
    
    const CB_PACKAGE_UPDATE = 'axenox\\PackageManager\\StaticInstaller::composerFinishPackageUpdate';
    
    /**
     * A composer.json without scripts gets all of them.
     * 
     * @return void
     */
    public function testScriptsAreAddedToEmptyComposerJson() : void
    {
        $json = PackageManagerInstallerTestDouble::configure(['name' => 'acme/project']);
        
        self::assertSame('acme/project', $json['name']);
        self::assertSame([
            'post-package-install' => [self::CB_PACKAGE_INSTALL],
            'post-package-update' => [self::CB_PACKAGE_UPDATE],
            'post-install-cmd' => [self::TIMEOUT, self::CMD_INSTALL],
            'post-update-cmd' => [self::TIMEOUT, self::CMD_UPDATE]
        ], $json['scripts']);
    }
    
    /**
     * Legacy callbacks are replaced by the `@php` scripts at the same position - other scripts are kept in order.
     * 
     * @return void
     */
    public function testLegacyCallbacksAreReplacedInPlace() : void
    {
        $json = PackageManagerInstallerTestDouble::configure([
            'scripts' => [
                'post-package-install' => [self::CB_PACKAGE_INSTALL],
                'post-package-update' => ['Acme\\Other::run', self::CB_PACKAGE_UPDATE],
                'post-install-cmd' => 'axenox\\PackageManager\\StaticInstaller::composerFinishInstall',
                'post-update-cmd' => [
                    '@php before.php',
                    'axenox\\PackageManager\\StaticInstaller::composerFinishUpdate',
                    'after-command'
                ],
                'test' => 'phpunit'
            ]
        ]);
        
        self::assertSame([
            'post-package-install' => [self::CB_PACKAGE_INSTALL],
            'post-package-update' => ['Acme\\Other::run', self::CB_PACKAGE_UPDATE],
            'post-install-cmd' => [self::TIMEOUT, self::CMD_INSTALL],
            'post-update-cmd' => ['@php before.php', self::TIMEOUT, self::CMD_UPDATE, 'after-command'],
            'test' => 'phpunit'
        ], $json['scripts']);
    }
    
    /**
     * Configuring an already configured composer.json changes nothing - also if the timeout helper is already
     * registered by the installation itself.
     * 
     * @return void
     */
    public function testConfigurationIsIdempotent() : void
    {
        $input = [
            'scripts' => [
                'post-update-cmd' => [self::TIMEOUT, '@php before.php', 'axenox\\PackageManager\\StaticInstaller::composerFinishUpdate']
            ]
        ];
        $once = PackageManagerInstallerTestDouble::configure($input);
        
        self::assertSame([self::TIMEOUT, '@php before.php', self::CMD_UPDATE], $once['scripts']['post-update-cmd']);
        self::assertSame($once, PackageManagerInstallerTestDouble::configure($once));
    }
    
    /**
     * Configuration reports all additions and replacements, then stays silent on repeated runs.
     * 
     * @return void
     */
    public function testConfigurationReportsEveryChangeAndStaysSilentWhenUnchanged() : void
    {
        $legacy = [
            'scripts' => [
                'post-install-cmd' => 'axenox\\PackageManager\\StaticInstaller::composerFinishInstall',
                'post-update-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishUpdate']
            ]
        ];
        $notices = [];
        $output = function (string $message) use (&$notices) : void {
            $notices[] = $message;
        };
        $configured = StaticInstaller::configureComposerScripts($legacy, $output);
        
        self::assertCount(6, $notices);
        self::assertStringContainsString(self::CB_PACKAGE_INSTALL, $notices[0]);
        self::assertStringContainsString(self::CB_PACKAGE_UPDATE, $notices[1]);
        self::assertStringContainsString('replacing', $notices[2]);
        self::assertStringContainsString(self::CMD_INSTALL, $notices[2]);
        self::assertStringContainsString(self::TIMEOUT, $notices[3]);
        self::assertStringContainsString('replacing', $notices[4]);
        self::assertStringContainsString(self::CMD_UPDATE, $notices[4]);
        self::assertStringContainsString(self::TIMEOUT, $notices[5]);
        $notices = [];
        self::assertSame($configured, StaticInstaller::configureComposerScripts($configured, $output));
        self::assertSame([], $notices);
    }

    /**
     * Repairs without legacy callbacks report both a missing timeout helper and duplicate commands.
     *
     * @return void
     */
    public function testConfigurationReportsNonLegacyRepairs() : void
    {
        $json = PackageManagerInstallerTestDouble::configure([]);
        $json['scripts']['post-update-cmd'] = [self::CMD_UPDATE, self::CMD_UPDATE, '@php after.php'];
        $notices = [];
        $configured = StaticInstaller::configureComposerScripts($json, function (string $message) use (&$notices) : void {
            $notices[] = $message;
        });

        self::assertSame([self::TIMEOUT, self::CMD_UPDATE, '@php after.php'], $configured['scripts']['post-update-cmd']);
        self::assertCount(2, $notices);
        self::assertStringContainsString('removing duplicate', $notices[0]);
        self::assertStringContainsString(self::TIMEOUT, $notices[1]);
    }

    /**
     * A non-string script is rejected instead of being silently retained in the configuration.
     *
     * @return void
     */
    public function testInvalidScriptEntryIsRejected() : void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('scripts.post-package-install');
        PackageManagerInstallerTestDouble::configure(['scripts' => ['post-package-install' => [123]]]);
    }
    
    /**
     * A custom `vendor-dir` is used in the script path.
     * 
     * @return void
     */
    public function testCustomVendorDirIsUsed() : void
    {
        $json = PackageManagerInstallerTestDouble::configure(['config' => ['vendor-dir' => 'lib/vendor']]);
        
        self::assertSame(
            '@php lib/vendor/axenox/packagemanager/bin/composer-script.php composerFinishUpdate', 
            $json['scripts']['post-update-cmd'][1]
        );
    }
}

/**
 * Configures Composer scripts with CLI output suppressed for assertions.
 */
class PackageManagerInstallerTestDouble
{
    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function configure(array $json) : array
    {
        return StaticInstaller::configureComposerScripts($json, function (string $message) : void {
        });
    }
}