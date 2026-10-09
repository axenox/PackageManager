<?php
namespace axenox\PackageManager\Audit;

use axenox\PackageManager\DataTypes\VulnerabilityLevelDataType;
use axenox\PackageManager\Interfaces\FindingInterface;
use exface\Core\Exceptions\InvalidArgumentException;

/**
 * Presents a consumer-selected group as one package-specific finding.
 * 
 * Consumers choose the grouping policy and generate any output IDs. Getters combine evidence
 * without changing the originals, which remain available through getMergedFindings().
 * Override individual getters to implement alternative aggregation strategies.
 */
class MergedFinding implements FindingInterface
{
    /**
     * @var FindingInterface[]
     */
    private array $findings;

    /**
     * Creates an aggregate without choosing which advisories belong together.
     * 
     * Nested aggregates are flattened and equal evidence is omitted. All originals
    * must belong to the same package and finding type. Output identities are
    * the responsibility of consumers, not of the aggregate.
     * 
     * @param FindingInterface[] $findings A nonempty group of findings.
     * @throws InvalidArgumentException If the group is empty or incompatible.
     */
    public function __construct(array $findings)
    {
        if ($findings === []) {
            throw new InvalidArgumentException('Merged findings require a nonempty group.');
        }
        $originals = [];
        foreach ($findings as $finding) {
            if (! $finding instanceof FindingInterface) {
                throw new InvalidArgumentException('Merged findings must implement FindingInterface.');
            }
            $members = $finding instanceof self ? $finding->getMergedFindings() : [$finding];
            foreach ($members as $member) {
                if ($originals !== [] && ($member->getPackage() !== $originals[0]->getPackage()
                    || $member->getType() !== $originals[0]->getType())) {
                    throw new InvalidArgumentException('Merged findings must have the same package and finding type.');
                }
                $duplicate = false;
                foreach ($originals as $existing) {
                    if ($existing->is($member)) {
                        $duplicate = true;
                        break;
                    }
                }
                if (! $duplicate) {
                    $originals[] = $member;
                }
            }
        }
        $this->findings = $originals;
    }

    /**
     * Returns the original scanner findings without nested aggregates.
     * 
     * The original instances are retained in encounter order. Equal evidence
     * appears only once; callers receive an independent array of those instances.
     * 
     * @return FindingInterface[]
     */
    public function getMergedFindings() : array
    {
        return $this->findings;
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getSourceId()
     * @return string
     */
    public function getSourceId() : string
    {
        return $this->mergeValues('getSourceId');
    }

    /**
     * Returns the highest severity among the original findings.
     * 
     * {@inheritDoc}
     * 
     * @see FindingInterface::getLevel()
     * @return int
     */
    public function getLevel() : int
    {
        $level = $this->findings[0]->getLevel();
        foreach ($this->findings as $finding) {
            if (VulnerabilityLevelDataType::isHigher($finding->getLevel(), $level)) {
                $level = $finding->getLevel();
            }
        }
        return $level;
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getName()
     * @return string
     */
    public function getName() : string
    {
        return $this->findings[0]->getName();
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getType()
     * @return string
     */
    public function getType() : string
    {
        return $this->findings[0]->getType();
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getPackage()
     * @return string
     */
    public function getPackage() : string
    {
        return $this->findings[0]->getPackage();
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getDetailsUrl()
     * @return string
     */
    public function getDetailsUrl() : string
    {
        return $this->mergeValues('getDetailsUrl');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getSource()
     * @return string
     */
    public function getSource() : string
    {
        return $this->mergeValues('getSource');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getSourceLevel()
     * @return string
     */
    public function getSourceLevel() : string
    {
        return $this->mergeValues('getSourceLevel');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getCve()
     * @return string
     */
    public function getCve() : string
    {
        return $this->mergeValues('getCve');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getPublicId()
     * @return string
     */
    public function getPublicId() : string
    {
        return $this->findings[0]->getPublicId();
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getDescription()
     * @return string
     */
    public function getDescription() : string
    {
        return $this->mergeValues('getDescription');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getRemediation()
     * @return string
     */
    public function getRemediation() : string
    {
        return $this->mergeValues('getRemediation');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getVersionsAffected()
     * @return string
     */
    public function getVersionsAffected() : string
    {
        return $this->mergeValues('getVersionsAffected');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getVersionInstalled()
     * @return string
     */
    public function getVersionInstalled() : string
    {
        return $this->mergeValues('getVersionInstalled');
    }

    /**
     * {@inheritDoc}
     * 
     * @see FindingInterface::getVersionFixed()
     * @return string
     */
    public function getVersionFixed() : string
    {
        return $this->mergeValues('getVersionFixed');
    }

    /**
     * Combines distinct nonempty evidence values in encounter order.
     * 
     * Values retain their scanner notation and are separated by a semicolon and space.
     * 
     * @param string $getter The evidence getter to invoke on each original finding.
     * @return string
     */
    private function mergeValues(string $getter) : string
    {
        $values = [];
        foreach ($this->findings as $finding) {
            $value = $finding->$getter();
            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }
        return implode('; ', $values);
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
        foreach (['getSourceId', 'getName', 'getType', 'getPackage', 'getSource', 'getSourceLevel',
            'getLevel', 'getDetailsUrl', 'getCve', 'getPublicId', 'getDescription', 'getRemediation',
            'getVersionsAffected', 'getVersionInstalled', 'getVersionFixed'] as $getter) {
            if ($this->$getter() !== $finding->$getter()) {
                return false;
            }
        }
        return true;
    }
}