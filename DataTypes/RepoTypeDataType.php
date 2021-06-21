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
class RepoTypeDataType extends StringDataType implements EnumDataTypeInterface
{
    use EnumStaticDataTypeTrait;
    
    const PUPLISHED_PACKAGE = 'published_package';
    const COMPOSER = 'php_composer';
    const GITHUB = 'github';
    const GITLAB = 'gitlab';
    const BITBUCKET = 'bitbucket';    
    const BOWER = 'js_bower';
    const FOSSIL = 'fossil';
    const GIT = 'git';
    const NPM = 'js_npm';    
    const MERCURIAL = 'mercurial';
    const VCS = 'vcs';
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        if (empty($this->labels)) {
            $translator = $this->getApp()->getTranslator();
            
            foreach (static::getValuesStatic() as $val) {
                $this->labels[$val] = $translator->translate('DATATYPE.PACKAGE.' . strtoupper($val));
            }
        }
        
        return $this->labels;
    }
}