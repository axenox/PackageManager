<?php
namespace axenox\PackageManager\DataTypes;

use exface\Core\CommonLogic\DataTypes\EnumStaticDataTypeTrait;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\DataTypes\StringDataType;

/**
 * Enumeration of package repository types.
 * 
 * @author ralf.mulansky
 *
 */
class PackageDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;
    
    const BITBUCKET = 'BitBucket';    
    const FOSSIL = 'Fossil';
    const GIT = 'Git';
    const GITHUB = 'GitHub';
    const GITLAB = 'GitLab';
    const BOWER = 'Javascript Bower';
    const NPM = 'Javascript Npm-Asset';    
    const MERCURIAL = 'Mercurial';
    const COMPOSER = 'php composer';
    const PUPLISHED_PACKAGE = 'Puplished Package';
    const VCS = 'vcs';
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        if (empty($this->labels)) {            
            foreach (static::getValuesStatic() as $const => $val) {
                $this->labels[$val] = $val;
            }
        }
        
        return $this->labels;
    }
}