<?php
namespace axenox\PackageManager\Actions;

use exface\Core\Factories\AppFactory;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;

/**
 * This action uninstalls one or more apps
 *
 * @author Andrej Kabachnik
 *        
 */
class UninstallApp extends InstallApp
{

    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::UNINSTALL);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $aliases = []) : \Generator
    {
        $installed_counter = 0;
        
        foreach ($aliases as $app_alias) {
            yield  PHP_EOL . "Uninstalling " . $app_alias . "..." . PHP_EOL;
            $app_selector = new AppSelector($this->getWorkbench(), $app_alias);
            try {
                $installed_counter ++;
                yield from $this->uninstallApp($app_selector);
                yield "..." . $app_alias . " successfully uninstalled." . PHP_EOL;
            } catch (\Exception $e) {
                $installed_counter --;
                $this->getWorkbench()->getLogger()->logException($e);
                yield "ERROR: " . ($e instanceof ExceptionInterface ? ' see log ID ' . $e->getId() : $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()) . PHP_EOL;
                yield "...$app_alias could not be uninstalled!" . PHP_EOL;
            }
        }
        
        if (count($aliases) == 0) {
            yield 'No uninstallable apps had been selected!';
        } elseif ($installed_counter == 0) {
            yield  'No apps have been uninstalled';
        }
        
        $this->getWorkbench()->getCache()->clear();
    }

    /**
     * @deprecated use installApp() instead!
     * 
     * This method ensures backwards compatibility with older versions of the StaticInstaller, which
     * cannot work with generators. If updating from an old version of the package manager, the
     * StaticInstaller old StaticInstaller attempts to call newer versions of this action. This
     * is why the install() method still returns a string and ensures the generator is iterade over,
     * while newer versions of StaticInstaller call uninstallApp() as does the perform() method of the
     * action.
     * 
     * @param AppSelectorInterface $app_selector
     * @return string
     */
    public function uninstall(AppSelectorInterface $app_selector) : string
    {
        $result = '';
        foreach ($this->uninstallApp($app_selector) as $msg) {
            $result .= $msg;
        }
        return $result;
    }
    
    /**
     *
     * @param AppSelectorInterface $app_selector
     * @return string
     */
    public function uninstallApp(AppSelectorInterface $app_selector) : \Traversable
    {
        $app = AppFactory::create($app_selector);
        $installer = $app->getInstaller();
        $installer_result = $installer->uninstall();
        if ($installer_result instanceof \Traversable) {
            yield from $installer_result;
        } else {
            yield $installer_result . (substr($installer_result, - 1) != '.' ? '.' : '');
        }
    }
}
?>