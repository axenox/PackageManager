<?php namespace axenox\PackageManager;
require 'libs/autoload.php';
use Composer\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Composer\Util\Filesystem;

class PackageInstaller {
	
	const VENDOR_DIR = '';
	const INSTALL_TO_PATH = '/../../';
	
	public static function install(array $packages, array $repositories = null, array $options = null) {
		// Don't proceed if packages haven't changed.
		if ($packages == self::dump()) { return false; }
		
		// Set the options
		if ($options){
			if ($options['HTTP_PROXY']){
				$_SERVER['HTTP_PROXY'] = $options['HTTP_PROXY'];
			}
			if ($options['HTTPS_PROXY']){
				$_SERVER['HTTPS_PROXY'] = $options['HTTPS_PROXY'];
			}
		}
		putenv('COMPOSER_HOME=' . __DIR__ . '/libs/bin/composer');
		
		// Create composer.json
		self::createComposerJson($packages, $repositories);
		chdir(self::get_install_dir());
		
		// Setup composer output formatter
		$stream = fopen('php://temp', 'w+');
		$output = new StreamOutput($stream);
		
		// Programmatically run `composer install`
		$application = new Application();
		$application->setAutoExit(false);
		$code = $application->run(new ArrayInput(array('command' => 'install')), $output);
		
		// Delete unnessecarry files and folders
		self::cleanup();
		
		// rewind stream to read full contents
		rewind($stream);
		return stream_get_contents($stream);
	}
	public static function dump() {
		$composer_file = self::get_install_dir() . '/composer.json';
		if (file_exists($composer_file)) {
			$composer_json = json_decode(file_get_contents($composer_file), true);
			return $composer_json['require'];
		} else {
			return array();
		}
	}
	public static function autoload() {
		$autoload_file = self::get_install_dir() . '/' . self::VENDOR_DIR . '/autoload.php';
		if (file_exists($autoload_file)) {
			require $autoload_file;
		}
	}
	protected static function createComposerJson(array $packages, array $repositories = null) {
		if (count($repositories) > 0){
			$repositories[] = array("packagist" => false);
		}
		$composer_json = str_replace("\/", '/', json_encode(array(
				'config' => array('vendor-dir' => self::VENDOR_DIR),
				'require' => $packages,
				"repositories" => $repositories,
		)));
		return file_put_contents(self::get_install_dir() . 'composer.json', $composer_json);
	}

	protected static function get_install_dir(){
		return  __DIR__ . self::INSTALL_TO_PATH;
	}

	protected static function cleanup(){
		$fs = new Filesystem();
		// remove composer.lock
		if (file_exists(self::get_install_dir() . 'composer.lock')) {
			unlink(self::get_install_dir() . 'composer.lock');
		}
		// remove autoload.php
		if (file_exists(self::get_install_dir() . 'autoload.php')) {
			unlink(self::get_install_dir() . 'autoload.php');
		}
		// remove composer.json
		if (file_exists(self::get_install_dir() . 'composer.json')) {
			unlink(self::get_install_dir() . 'composer.json');
		}
		// remove composer folder
		if (file_exists(self::get_install_dir() . 'composer')){
			$fs->removeDirectory(self::get_install_dir() . 'composer');
		}
	}
}
?>