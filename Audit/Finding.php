<?php
namespace axenox\PackageManager\Audit;

use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Represents one scanner's advisory for one package.
 * 
 * Findings are immutable and retain their scanner-specific evidence. Consumers
 * decide how to group, compare for reporting and format findings for output.
 */
class Finding implements FindingInterface
{
    private string $sourceId;
    private string $name;
    private string $type;
    private string $package;
    private string $source;
    private string $sourceLevel;
    private int $level;
    private string $detailsUrl;
    private string $cve;
    private string $publicId;
    private string $description;
    private string $remediation;
    private string $versionsAffected;
    private string $versionInstalled;
    private string $versionFixed;

    /**
     * Creates one scanner detection.
     * 
     * Optional evidence defaults to an empty string. Severity is normalized,
     * valid CVEs are uppercased, and public identifiers are resolved locally.
     * An explicit public identifier takes precedence over automatic resolution.
     * 
     * @param string $sourceId
     * @param string $name
     * @param string $type
     * @param string $package
     * @param string $source
     * @param string $sourceLevel
     * @param string $detailsUrl
     * @param string $cve
     * @param string $description
     * @param string $remediation
     * @param string $versionsAffected
     * @param string $versionInstalled
     * @param string $versionFixed
     * @param string $publicId
     * @throws InvalidArgumentException If the finding type is unsupported.
     */
    public function __construct(
        string $sourceId,
        string $name,
        string $type,
        string $package,
        string $source,
        string $sourceLevel,
        string $detailsUrl = '',
        string $cve = '',
        string $description = '',
        string $remediation = '',
        string $versionsAffected = '',
        string $versionInstalled = '',
        string $versionFixed = '',
        string $publicId = ''
    ) {
        if (! in_array($type, [self::TYPE_VULNERABILITY, self::TYPE_EOL], true)) {
            throw new InvalidArgumentException('Unsupported audit finding type: ' . $type);
        }
        $this->sourceId = $sourceId;
        $this->name = $name;
        $this->type = $type;
        $this->package = $package;
        $this->source = $source;
        $this->sourceLevel = $sourceLevel;
        $this->level = VulnerabilityLevelDataType::normalize($sourceLevel);
        $this->detailsUrl = $detailsUrl;
        $cve = $cve !== '' ? $cve : $sourceId;
        $this->cve = preg_match('/^CVE-\d{4}-\d{4,}$/i', $cve) ? strtoupper($cve) : '';
        $this->description = $description;
        $this->remediation = $remediation;
        $this->versionsAffected = $versionsAffected;
        $this->versionInstalled = $versionInstalled;
        $this->versionFixed = $versionFixed;
        $this->publicId = $publicId !== '' ? $publicId : $this->resolvePublicId();
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getSourceId()
     * @return string
     */
    public function getSourceId() : string { return $this->sourceId; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getLevel()
    * @return int
     */
    public function getLevel() : int { return $this->level; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getName()
     * @return string
     */
    public function getName() : string { return $this->name; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getType()
     * @return string
     */
    public function getType() : string { return $this->type; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getPackage()
     * @return string
     */
    public function getPackage() : string { return $this->package; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getDetailsUrl()
     * @return string
     */
    public function getDetailsUrl() : string { return $this->detailsUrl; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getSource()
     * @return string
     */
    public function getSource() : string { return $this->source; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getSourceLevel()
     * @return string
     */
    public function getSourceLevel() : string { return $this->sourceLevel; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getCve()
     * @return string
     */
    public function getCve() : string { return $this->cve; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getPublicId()
     * @return string
     */
    public function getPublicId() : string { return $this->publicId; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getDescription()
     * @return string
     */
    public function getDescription() : string { return $this->description; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getRemediation()
     * @return string
     */
    public function getRemediation() : string { return $this->remediation; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getVersionsAffected()
     * @return string
     */
    public function getVersionsAffected() : string { return $this->versionsAffected; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getVersionInstalled()
     * @return string
     */
    public function getVersionInstalled() : string { return $this->versionInstalled; }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getVersionFixed()
     * @return string
     */
    public function getVersionFixed() : string { return $this->versionFixed; }

    /**
     * Resolves the public identifier without fetching advisory pages.
     * 
     * CVEs take precedence over linked GHSA identifiers and native IDs.
     * EOL findings have no automatically resolved public identifier.
     * 
     * @return string
     */
    private function resolvePublicId() : string
    {
        if ($this->type === self::TYPE_EOL) {
            return '';
        }
        if ($this->cve !== '') {
            return $this->cve;
        }
        $identifiers = [];
        foreach (explode('; ', $this->detailsUrl) as $url) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && preg_match('~(?:^|/)(GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4})(?:/|$)~i', $path, $matches)) {
                $identifiers[] = 'GHSA-' . strtolower(substr($matches[1], 5));
            }
        }
        return $identifiers !== [] ? implode('; ', array_unique($identifiers)) : $this->sourceId;
    }

    /**
     * {@inheritDoc}
     * 
    * @see FindingInterface::is()
     * @param FindingInterface $finding
     * @return bool
     */
    public function is(FindingInterface $finding) : bool
    {
        return $this->sourceId === $finding->getSourceId()
            && $this->name === $finding->getName()
            && $this->type === $finding->getType()
            && $this->package === $finding->getPackage()
            && $this->source === $finding->getSource()
            && $this->sourceLevel === $finding->getSourceLevel()
            && $this->level === $finding->getLevel()
            && $this->detailsUrl === $finding->getDetailsUrl()
            && $this->cve === $finding->getCve()
            && $this->publicId === $finding->getPublicId()
            && $this->description === $finding->getDescription()
            && $this->remediation === $finding->getRemediation()
            && $this->versionsAffected === $finding->getVersionsAffected()
            && $this->versionInstalled === $finding->getVersionInstalled()
            && $this->versionFixed === $finding->getVersionFixed();
    }

}