<?php namespace axenox\PackageManager;

use Composer\Installer\PackageEvent;
use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\NameResolver;
use exface\Core\Exceptions\exfError;

require_once dirname(__FILE__) . 
	DIRECTORY_SEPARATOR . '..' . 
	DIRECTORY_SEPARATOR . '..' . 
	DIRECTORY_SEPARATOR . 'exface' . 
	DIRECTORY_SEPARATOR . 'Core' . 
	DIRECTORY_SEPARATOR . 'CommonLogic' .
	DIRECTORY_SEPARATOR . 'Workbench.php';

/**
 * The app installer is a simplified wrapper for the package manager actions, which simplifies installing apps from outside of
 * ExFace - in particular AppInstaller::finish_composer_update() can be used as a script in composer to perform the app specific
 * installatiom automatically once composer is done installing or updating all the files. 
 * 
 * @author Andrej Kabachnik
 *
 */
class AppInstaller {
	private $workbench = null;
	
	public static function composer_finish_install(PackageEvent $composer_event){
		return self::install($composer_event->getOperation()->getPackage()->getName());
	}
	
	public static function composer_finish_update(PackageEvent $composer_event){
		return self::install($composer_event->getOperation()->getTargetPackage()->getName());
	}
	
	public static function composer_prepare_uninstall(PackageEvent $composer_event){
		return self::uninstall($composer_event->getOperation()->getPackage()->getName());
	}
	
	public static function install($package_name){
		$exface = Workbench::start_new_instance();
		try {
			$app_name_resolver = NameResolver::create_from_string(PackageManagerApp::get_app_alias_from_package_name($package_name), NameResolver::OBJECT_TYPE_APP, $exface);
			$exface->get_app('Axenox.PackageManager')->get_action('ImportAppModel')->install($app_name_resolver);
			$exface->stop();
		} catch (\Exception $e){
			return $e->getMessage();	
		}
		return true;
	}
	
	public static function uninstall($package_name){
		throw new exfError('AppInstaller::uninstall() is not implemented yet!');
	}
	
}
?>