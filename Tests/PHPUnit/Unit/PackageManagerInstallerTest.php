<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\PackageManagerInstaller;
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
     * Every replaced legacy callback produces a migration notice - an up-to-date composer.json produces none.
     * 
     * @return void
     */
    public function testMigrationNoticesListReplacedCallbacks() : void
    {
        $legacy = [
            'scripts' => [
                'post-install-cmd' => 'axenox\\PackageManager\\StaticInstaller::composerFinishInstall',
                'post-update-cmd' => ['axenox\\PackageManager\\StaticInstaller::composerFinishUpdate']
            ]
        ];
        $notices = PackageManagerInstaller::getComposerScriptMigrationNotices($legacy);
        
        self::assertCount(2, $notices);
        self::assertStringContainsString('"scripts.post-install-cmd"', $notices[0]);
        self::assertStringContainsString(self::CMD_INSTALL, $notices[0]);
        self::assertStringContainsString('"scripts.post-update-cmd"', $notices[1]);
        self::assertStringContainsString(self::CMD_UPDATE, $notices[1]);
        self::assertSame([], PackageManagerInstaller::getComposerScriptMigrationNotices(PackageManagerInstallerTestDouble::configure($legacy)));
        self::assertSame([], PackageManagerInstaller::getComposerScriptMigrationNotices([]));
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
 * Exposes the protected static configuration method of the installer.
 */
class PackageManagerInstallerTestDouble extends PackageManagerInstaller
{
    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    public static function configure(array $json) : array
    {
        return static::configureComposerScripts($json);
    }
}