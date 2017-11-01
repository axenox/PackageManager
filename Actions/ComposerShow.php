<?php
namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;
use exface\Core\CommonLogic\Constants\Icons;

/**
 * This action runs one or more selected test steps
 *
 * @author Andrej Kabachnik
 *        
 */
class ComposerShow extends AbstractComposerAction
{

    private $show_latest_versions = false;

    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::INFO);
    }

    protected function performComposerAction(ComposerAPI $composer)
    {
        $options = array();
        if ($this->getShowLatestVersions()) {
            $options[] = '--latest';
        }
        return $composer->show($options);
    }

    public function getShowLatestVersions()
    {
        return $this->show_latest_versions;
    }

    public function setShowLatestVersions($value)
    {
        $this->show_latest_versions = $value;
        return $this;
    }
}
?>