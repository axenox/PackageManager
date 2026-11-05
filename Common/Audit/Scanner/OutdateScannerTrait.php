<?php
namespace axenox\PackageManager\Common\Audit\Scanner;

use axenox\PackageManager\Common\Audit\Finding;
use axenox\PackageManager\DataTypes\AdvisoryTypeDataType;
use axenox\PackageManager\DataTypes\PackageEcosystemDataType;
use Composer\Semver\VersionParser;
use exface\Core\Exceptions\RuntimeException;

/**
 * Shares release-specific finding construction between outdated scanners.
 * 
 * Routine updates and major upgrades use separate types and stable titles.
 * Generic audit scanners do not inherit this release policy.
 */
trait OutdateScannerTrait
{
    /**
     * Creates a release finding with a stable title and a separate identity for each target major.
     * 
     * Minor and patch updates share a title. Major upgrades retain only the target
     * major in their title; full versions and scanner evidence remain in the description.
     * Version fields retain their original spelling and severity remains optional.
     * 
     * @param string $name Native package name.
     * @param string $installed
     * @param string $latest
     * @param string $source
     * @param string $ecosystem
     * @param string $description
     * @param string $remediation
     * @param string $detailsUrl
     * @return Finding
     */
    protected function createOutdatedFinding(
        string $name,
        string $installed,
        string $latest,
        string $source,
        string $ecosystem,
        string $description,
        string $remediation,
        string $detailsUrl = ''
    ) : Finding
    {
        $name = PackageEcosystemDataType::convertNameFromAssetPackagist($name, $ecosystem);
        $parser = new VersionParser();
        try {
            $installedMajor = (int) explode('.', $parser->normalize($installed))[0];
            $latestMajor = (int) explode('.', $parser->normalize($latest))[0];
        } catch (\UnexpectedValueException $error) {
            throw new RuntimeException('Cannot classify newer release for ' . $name . ': ' . $error->getMessage(), null, $error);
        }
        $isMajor = $latestMajor > $installedMajor;
        $type = $isMajor ? AdvisoryTypeDataType::UPGRADABLE : AdvisoryTypeDataType::OUTDATED;
        $title = $isMajor
            ? 'New version ' . $latestMajor . ' available for package ' . $name
            : 'Update available for package ' . $name;
        $versionSummary = ($isMajor ? 'New major version ' : 'New version ') . $latest
            . ' available - currently on ' . $installed . '.';
        return new Finding(
            $type . ':' . $name . ($isMajor ? ':' . $latestMajor : ''),
            $title,
            $type,
            PackageEcosystemDataType::convertNameToAssetPackagist($name, $ecosystem),
            $source,
            'optional',
            detailsUrl: $detailsUrl,
            description: $versionSummary . PHP_EOL . $description,
            remediation: $remediation,
            versionInstalled: $installed,
            versionFixed: $latest,
            ecosystem: $ecosystem
        );
    }
}
