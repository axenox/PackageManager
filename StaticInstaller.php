<?php namespace axenox\PackageManager;

use Composer\Installer\PackageEvent;
use exface\Core\CommonLogic\Workbench;
use exface\Core\CommonLogic\NameResolver;
use Composer\Script\Event;

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
class StaticInstaller {
	const PACKAGE_MANAGER_APP_ALIAS = 'axenox.PackageManager';
	const PACKAGE_MANAGER_INSTALL_ACTION_ALIAS = 'InstallApp';
	const PACKAGE_MANAGER_UNINSTALL_ACTION_ALIAS = 'UninstallApp';
	
	private $workbench = null;
	
	/**
	 * 
	 * @param PackageEvent $composer_event
	 * @return void
	 */
	public static function composer_finish_package_install(PackageEvent $composer_event){
		$app_alias = self::composer_get_app_alias_from_extras($composer_event->getOperation()->getPackage()->getExtra());
		if ($app_alias){
			self::add_app_to_temp_file('install', $app_alias);
		}
	}
	
	/**
	 * 
	 * @param PackageEvent $composer_event
	 * @return void
	 */
	public static function composer_finish_package_update(PackageEvent $composer_event){
		$app_alias = self::composer_get_app_alias_from_extras($composer_event->getOperation()->getTargetPackage()->getExtra());
		if ($app_alias){
			self::add_app_to_temp_file('update', $app_alias);
		}
	}
	
	/**
	 * 
	 * @param Event $composer_event
	 * @return string
	 */
	public static function composer_finish_install(Event $composer_event = null){
		$text = '';
		$processed_aliases = array();
		$temp = self::get_temp_file();
		if (array_key_exists('install', $temp)){
			foreach ($temp['install'] as $app_alias){
				if (!in_array($app_alias, $processed_aliases)){
					$processed_aliases[] = $app_alias;
				} else {
					continue;
				}
				$result = self::install($app_alias);
				$text .= '-> Installing app "' . $app_alias . '": ' . ($result ? $result : 'Nothing to do') . ".\n";
				self::print_to_stdout($text);
			}
			unset($temp['install']);
			self::set_temp_file($temp);
		}
		return $text ? $text : 'No apps to install' . ".\n";
	}
	
	/**
	 * 
	 * @param Event $composer_event
	 * @return string
	 */
	public static function composer_finish_update(Event $composer_event = null){
		$text = '';
		$processed_aliases = array();
		$temp = self::get_temp_file();
		if (array_key_exists('update', $temp)){
			// First of all check, if the core needs to be updated. If so, do that before updating other apps
			if (in_array(self::get_core_app_alias(), $temp['update'])){
				if (!in_array(self::get_core_app_alias(), $processed_aliases)){
					$processed_aliases[] = self::get_core_app_alias();
					$result = self::install(self::get_core_app_alias());
					$text .= '-> Updating app "' . self::get_core_app_alias() . '": ' . ($result ? $result : 'Nothing to do') . ".\n";
					self::print_to_stdout($text);
				} 
			}
			// Now that the core is up to date, we can update the others
			foreach ($temp['update'] as $app_alias){
				if (!in_array($app_alias, $processed_aliases)){
					$processed_aliases[] = $app_alias;
				} else {
					continue;
				}
				$result = self::install($app_alias);
				$text .= '-> Updating app "' . $app_alias . '": ' . ($result ? $result : 'Nothing to do') . ".\n";
				self::print_to_stdout($text);
			}
			unset($temp['update']);
			self::set_temp_file($temp);
		}
		
		// If composer is performing an update operation, it will install new packages, but will not trigger the post-install-cmd
		// As a workaround, we just trigger finish_install() here by hand
		if (array_key_exists('install', $temp)){
			$text .= self::composer_finish_install();
		}
		
		return $text ? $text : 'No apps to update' . ".\n";
	}
	
	public static function composer_prepare_uninstall(PackageEvent $composer_event){
		return self::uninstall($composer_event->getOperation()->getPackage()->getName());
	}
	
	protected static function composer_get_app_alias_from_extras($extras_array){
		if (is_array($extras_array) && array_key_exists('app', $extras_array) && is_array($extras_array['app']) && array_key_exists('app_alias', $extras_array['app'])){
			return $extras_array['app']['app_alias'];
		}
		return false;
	}
	
	public static function install($app_alias){
		$installer = new self();
		return $installer->install_app($app_alias);
	}
	
	public static function uninstall($app_alias){
		// TODO
	}
	
	public function install_app($app_alias){
		$result = '';
		try {
			$exface = $this->get_workbench();
			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			$result = $exface->get_app(self::PACKAGE_MANAGER_APP_ALIAS)->get_action(self::PACKAGE_MANAGER_INSTALL_ACTION_ALIAS)->install($app_name_resolver);
		} catch (\Exception $e){
			$result = $e->getMessage();
		}
		return $result;
	}
	
	public function uninstall_app($app_alias){
		$result = '';
		try {
			$exface = $this->get_workbench();
			$app_name_resolver = NameResolver::create_from_string($app_alias, NameResolver::OBJECT_TYPE_APP, $exface);
			$result = $exface->get_app(self::PACKAGE_MANAGER_APP_ALIAS)->get_action(self::PACKAGE_MANAGER_UNINSTALL_ACTION_ALIAS)->uninstall($app_name_resolver);
		} catch (\Exception $e){
			$result = $e->getMessage();
		}
		return $result;
	}
	
	/**
	 * @return Workbench
	 */
	protected function get_workbench(){
		if (is_null($this->workbench)){
			error_reporting(E_ALL ^  E_NOTICE);
			$this->workbench = Workbench::start_new_instance();
		}
		return $this->workbench;
	}
	
	protected static function get_temp_file_path_absolute(){
		return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'LastInstall.temp.json';
	}
	
	/**
	 * 
	 * @return array
	 */
	protected static function get_temp_file(){
		$json_array = array();
		$filename = self::get_temp_file_path_absolute();
		if (file_exists($filename)){
			$json_array = json_decode(file_get_contents($filename), true);
		}
		return $json_array;
	}
	
	/**
	 * 
	 * @param array $json_array
	 */
	protected static function set_temp_file(array $json_array){
		if (count($json_array) > 0){
			return file_put_contents(self::get_temp_file_path_absolute(), json_encode($json_array, JSON_PRETTY_PRINT));
		} elseif (file_exists(self::get_temp_file_path_absolute())) {
			return unlink(self::get_temp_file_path_absolute());
		}
	}
	
	/**
	 * 
	 * @param string $operation
	 * @param string $app_alias
	 * @return unknown
	 */
	protected static function add_app_to_temp_file($operation, $app_alias){
		$temp_file = self::get_temp_file();
		$temp_file[$operation][] = $app_alias;
		self::set_temp_file($temp_file);
		return $temp_file;
	}
	
	protected static function print_to_stdout($text){
		if (is_resource(STDOUT)){
			fwrite(STDOUT,  $text);
			return true;
		} 
		return false;
	}
	
	public static function get_core_app_alias(){
		return 'exface.Core';
	}
}
?>