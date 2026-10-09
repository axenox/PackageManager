<?php
namespace axenox\PackageManager\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Classifies audit advisories as vulnerabilities, end-of-life or unscannable packages.
 * 
 * Stored values remain independent of translated display labels. Use this enum
 * for advisory type attributes and scanner findings; severity is modeled separately
 * by VulnerabilityLevelDataType.
 */
class AdvisoryTypeDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;

    const VULNERABILITY = 'vulnerability';
    const EOL = 'EOL';
    const UNSCANNABLE = 'unscannable';

    /**
     * {@inheritDoc}
     * 
     * @see EnumDataTypeInterface::getLabels()
     * @return string[]
     */
    public function getLabels()
    {
        return static::getLabelsStatic($this->getWorkbench());
    }

    /**
     * Returns translated type names indexed by their stored values.
     * 
     * @param WorkbenchInterface $workbench
     * @return array<string, string>
     */
    public static function getLabelsStatic(WorkbenchInterface $workbench) : array
    {
        $labels = [];
        $translator = $workbench->getApp('axenox.PackageManager')->getTranslator();
        foreach (static::getValuesOfConstants() as $name => $value) {
            $labels[$value] = $translator->translate('DATATYPE.ADVISORY_TYPE.' . $name);
        }
        return $labels;
    }

    /**
     * Returns the translated name of a type, or null for an unknown value.
     * 
     * @param WorkbenchInterface $workbench
     * @param string $value
     * @return string|null
     */
    public static function getLabelOfValueStatic(WorkbenchInterface $workbench, string $value) : ?string
    {
        return static::getLabelsStatic($workbench)[$value] ?? null;
    }
}