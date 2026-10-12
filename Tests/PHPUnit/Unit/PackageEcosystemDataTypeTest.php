<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use axenox\PackageManager\Tests\PHPUnit\Support\AuditTestCase;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;
use exface\Core\Factories\SelectorFactory;

/**
 * Keeps registry naming and Composer asset conversions consistent across consumers.
 */
class PackageEcosystemDataTypeTest extends AuditTestCase
{
    /**
     * Display labels identify languages and registries without changing the stored ecosystem values.
     * 
     * @return void
     */
    public function testLabelsIncludeLanguagesAndOfficialNames() : void
    {
        $dataType = new PackageEcosystemDataType(
            SelectorFactory::createDataTypeSelector($this->workbench, PackageEcosystemDataType::class)
        );
        $labels = $dataType->getLabels();
        self::assertSame(PackageEcosystemDataType::getValuesStatic(), array_keys($labels));
        self::assertSame([
            'composer' => 'PHP Composer',
            'npm' => 'JavaScript NPM',
            'bower' => 'JavaScript Bower',
            'nuget' => '.NET NuGet',
            'maven' => 'Java Maven',
            'pypi' => 'Python PyPI',
            'go' => 'Go Modules',
            'crates.io' => 'Rust crates.io',
            'rubygems' => 'Ruby RubyGems',
            'hex' => 'Erlang/Elixir Hex',
            'pub' => 'Dart Pub',
            'swifturl' => 'Swift Package Manager',
            'hackage' => 'Haskell Hackage',
            'conancenter' => 'C/C++ ConanCenter',
            'cran' => 'R CRAN',
            'opam' => 'OCaml opam'
        ], $labels);
    }

    /**
     * Asset conversion must be reversible even when scoped package names contain double hyphens.
     * 
     * @return void
     */
    public function testAssetNamesRoundTrip() : void
    {
        foreach ([
            ['npm-asset/openui5--sap.ui.core', '@openui5/sap.ui.core', 'npm'],
            ['npm-asset/scope--my--package', '@scope/my--package', 'npm'],
            ['npm-asset/jquery', 'jquery', 'npm'],
            ['bower-asset/jquery', 'jquery', 'bower'],
            ['vendor/library', 'vendor/library', 'composer'],
            ['Newtonsoft.Json', 'Newtonsoft.Json', 'nuget']
        ] as [$alias, $native, $ecosystem]) {
            self::assertSame($ecosystem, PackageEcosystemDataType::findEcosystem($alias, in_array($ecosystem, ['npm', 'bower'], true) ? null : $ecosystem));
            self::assertSame($native, PackageEcosystemDataType::convertNameFromAssetPackagist($alias, $ecosystem));
            self::assertSame($alias, PackageEcosystemDataType::convertNameToAssetPackagist($native, $ecosystem));
        }
    }

    /**
     * Legacy OSV ecosystem labels stay accepted while includes use their canonical values.
     * 
     * @return void
     */
    public function testDefaultsAndOsvNames() : void
    {
        self::assertSame('composer', PackageEcosystemDataType::findEcosystem('vendor/library'));
        self::assertSame('composer', PackageEcosystemDataType::findEcosystem('vendor/library', 'Packagist'));
        self::assertSame('nuget', PackageEcosystemDataType::cast(' NuGet '));
        self::assertSame('Packagist', PackageEcosystemDataType::convertToOsvEcosystem('composer'));
        self::assertSame('NuGet', PackageEcosystemDataType::convertToOsvEcosystem('nuget'));
        self::assertSame('PyPI', PackageEcosystemDataType::convertToOsvEcosystem('pypi'));
        self::assertNull(PackageEcosystemDataType::convertToOsvEcosystem('bower'));
        foreach (PackageEcosystemDataType::getValuesStatic() as $ecosystem) {
            self::assertSame(strtolower($ecosystem), $ecosystem);
            self::assertSame($ecosystem, PackageEcosystemDataType::cast(strtoupper($ecosystem)));
        }
    }

    /**
     * Invalid ecosystems must not silently become Composer identities.
     * 
     * @return void
     */
    public function testUnknownEcosystemIsRejected() : void
    {
        $this->expectException(DataTypeCastingError::class);
        PackageEcosystemDataType::findEcosystem('library', 'not-a-registry');
    }
}