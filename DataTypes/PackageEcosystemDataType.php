<?php
namespace axenox\PackageManager\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\DataTypes\DataTypeCastingError;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Identifies package registries independently of Composer's asset aliases.
 * 
 * Includes and JSON BOMs use lowercase ecosystem values. OSV registry labels
 * are resolved separately. Bower has no OSV registry identity; its locked Git
 * commits can still be queried by the OSV scanner.
 */
class PackageEcosystemDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;

    const COMPOSER = 'composer';
    const NPM = 'npm';
    const BOWER = 'bower';
    const NUGET = 'nuget';
    const MAVEN = 'maven';
    const PYPI = 'pypi';
    const GO = 'go';
    const CRATES_IO = 'crates.io';
    const RUBYGEMS = 'rubygems';
    const HEX = 'hex';
    const PUB = 'pub';
    const SWIFT_URL = 'swifturl';
    const HACKAGE = 'hackage';
    const CONAN_CENTER = 'conancenter';
    const CRAN = 'cran';
    const OPAM = 'opam';
    const UNKNOWN = 'unknown';

    /**
     * Returns ecosystem display names with their language or platform where applicable.
     * 
     * {@inheritDoc}
     * 
     * @see EnumDataTypeInterface::getLabels()
     * @return array<string, string>
     */
    public function getLabels()
    {
        return [
            static::COMPOSER => 'PHP Composer',
            static::NPM => 'JavaScript NPM',
            static::BOWER => 'JavaScript Bower',
            static::NUGET => '.NET NuGet',
            static::MAVEN => 'Java Maven',
            static::PYPI => 'Python PyPI',
            static::GO => 'Go Modules',
            static::CRATES_IO => 'Rust crates.io',
            static::RUBYGEMS => 'Ruby RubyGems',
            static::HEX => 'Erlang/Elixir Hex',
            static::PUB => 'Dart Pub',
            static::SWIFT_URL => 'Swift Package Manager',
            static::HACKAGE => 'Haskell Hackage',
            static::CONAN_CENTER => 'C/C++ ConanCenter',
            static::CRAN => 'R CRAN',
            static::OPAM => 'OCaml opam',
            static::UNKNOWN => 'Unknown'
        ];
    }

    /**
     * Resolves legacy asset aliases while defaulting untyped packages to Composer.
     * 
     * Explicit native ecosystems take precedence over Composer prefix inference.
     * 
     * @param string $name
     * @param string|null $ecosystem
     * @return string
     */
    public static function findEcosystem(string $name, ?string $ecosystem = null) : string
    {
        $ecosystem = trim($ecosystem ?? static::COMPOSER);
        $constant = static::findConstant(strcasecmp($ecosystem, 'Packagist') === 0 ? static::COMPOSER : $ecosystem);
        if ($constant === false) {
            throw new DataTypeCastingError('Unsupported package ecosystem "' . $ecosystem . '".');
        }
        $ecosystem = static::getValuesOfConstants()[$constant];
        if ($ecosystem === static::COMPOSER) {
            if (strpos($name, 'npm-asset/') === 0) {
                return static::NPM;
            }
            if (strpos($name, 'bower-asset/') === 0) {
                return static::BOWER;
            }
        }
        return $ecosystem;
    }

    /**
     * Converts Composer asset aliases to native registry names without changing other names.
     * 
     * Scoped npm packages use @scope/package rather than scope--package.
     * 
     * @param string $name
     * @param string|null $ecosystem
     * @return string
     */
    public static function convertNameFromAssetPackagist(string $name, ?string $ecosystem = null) : string
    {
        $ecosystem = static::findEcosystem($name, $ecosystem);
        if ($ecosystem === static::NPM && strpos($name, 'npm-asset/') === 0) {
            $name = substr($name, 10);
            $parts = explode('--', $name, 2);
            return count($parts) === 2 ? '@' . $parts[0] . '/' . $parts[1] : $name;
        }
        if ($ecosystem === static::BOWER && strpos($name, 'bower-asset/') === 0) {
            return substr($name, 12);
        }
        return $name;
    }

    /**
     * Converts native asset names to the stable Composer notation used by audit findings.
     * 
     * Registries not bridged by asset-packagist keep their native package names.
     * 
     * @param string $name
     * @param string $ecosystem
     * @return string
     */
    public static function convertNameToAssetPackagist(string $name, string $ecosystem) : string
    {
        $ecosystem = static::findEcosystem($name, $ecosystem);
        $name = static::convertNameFromAssetPackagist($name, $ecosystem);
        if ($ecosystem === static::NPM) {
            return 'npm-asset/' . (strpos($name, '@') === 0 ? str_replace('/', '--', substr($name, 1)) : $name);
        }
        return $ecosystem === static::BOWER ? 'bower-asset/' . $name : $name;
    }

    /**
     * Keeps BOM merging registry-aware while preserving existing Composer collection keys.
     * 
     * Native npm/Bower names and asset aliases have the same key. Other registries
     * use an ecosystem prefix to avoid overwriting same-named Composer packages.
     * 
     * @param string $name
     * @param string|null $ecosystem
     * @return string
     */
    public static function buildPackageKey(string $name, ?string $ecosystem = null) : string
    {
        $ecosystem = static::findEcosystem($name, $ecosystem);
        $name = static::convertNameToAssetPackagist($name, $ecosystem);
        return in_array($ecosystem, [static::COMPOSER, static::NPM, static::BOWER], true)
            ? $name : $ecosystem . ':' . $name;
    }

    /**
     * Resolves known PURL registry types without assigning unknown or OS packages to Composer.
     * 
     * PURL type names differ from ecosystem names for Go, Rust and Ruby. Generic
     * Conan packages do not establish ConanCenter as their registry.
     * 
     * @param string $type
     * @return string|null
     */
    public static function convertFromPurlType(string $type) : ?string
    {
        return [
            'composer' => static::COMPOSER,
            'npm' => static::NPM,
            'bower' => static::BOWER,
            'nuget' => static::NUGET,
            'maven' => static::MAVEN,
            'pypi' => static::PYPI,
            'golang' => static::GO,
            'cargo' => static::CRATES_IO,
            'gem' => static::RUBYGEMS,
            'hex' => static::HEX,
            'pub' => static::PUB,
            'swift' => static::SWIFT_URL,
            'hackage' => static::HACKAGE,
            'cran' => static::CRAN,
            'opam' => static::OPAM
        ][strtolower($type)] ?? null;
    }

    /**
     * Maps inventory ecosystem values to OSV's registry names.
     * 
    * Bower returns null because OSV has no Bower registry identity. This does not
    * disable auditing: the OSV scanner queries usable locked Git commits instead.
     * 
     * @param string $ecosystem
     * @return string|null
     */
    public static function convertToOsvEcosystem(string $ecosystem) : ?string
    {
        return [
            static::COMPOSER => 'Packagist',
            static::NPM => 'npm',
            static::BOWER => null,
            static::NUGET => 'NuGet',
            static::MAVEN => 'Maven',
            static::PYPI => 'PyPI',
            static::GO => 'Go',
            static::CRATES_IO => 'crates.io',
            static::RUBYGEMS => 'RubyGems',
            static::HEX => 'Hex',
            static::PUB => 'Pub',
            static::SWIFT_URL => 'SwiftURL',
            static::HACKAGE => 'Hackage',
            static::CONAN_CENTER => 'ConanCenter',
            static::CRAN => 'CRAN',
            static::OPAM => 'opam',
            static::UNKNOWN => null
        ][static::findEcosystem('', $ecosystem)];
    }
}