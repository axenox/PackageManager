<?php namespace axenox\PackageManager;

use Composer\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ComposerApi {
	private $path_to_composer_installation = '';
	private $path_to_composer_json = '';
	private $proxy_http = null;
	private $proxy_https = null;
	private $composer_application = null;
	
	public function __construct($path_to_composer_json, $path_to_composer_installation){
		$this->set_path_to_composer_json($path_to_composer_json);
		$this->set_path_to_composer($path_to_composer_installation);
	}
	
	public function get_path_to_composer_json() {
		return $this->path_to_composer_json;
	}
	
	public function set_path_to_composer_json($value) {
		$this->path_to_composer_json = $value;
		return $this;
	}
	
	  
	/**
	 * 
	 * @return string|unknown
	 */
	public function get_path_to_composer_installation() {
		return $this->path_to_composer_installation;
	}
	
	/**
	 * 
	 * @param unknown $value
	 * @return \axenox\PackageManager\ComposerApi
	 */
	public function set_path_to_composer_installation($value) {
		$this->path_to_composer_installation = $value;
		return $this;
	}  
	
	/**
	 * 
	 * @return unknown
	 */
	public function get_proxy_http() {
		return $this->proxy_http;
	}
	
	/**
	 * 
	 * @param unknown $http_proxy
	 * @param unknown $https_proxy
	 * @return \axenox\PackageManager\ComposerApi
	 */
	public function set_proxy($http_proxy = null, $https_proxy = null) {
		$this->proxy_http = $http_proxy;
		$this->proxy_https = $https_proxy;
		return $this;
	}
	
	/**
	 * 
	 * @return string
	 */
	public function get_proxy_https() {
		return $this->proxy_https;
	}
	
	/**
	 * 
	 * @return \axenox\PackageManager\ComposerApi
	 */
	protected function register_proxy(){
		if ($this->get_proxy_http()){
			$_SERVER['HTTP_PROXY'] = $this->get_proxy_http();
		}
		if ($this->get_proxy_https()){
			$_SERVER['HTTPS_PROXY'] = $this->get_proxy_https();
		}
		return $this;
	}
	    
	/**
	 * 
	 * @return \Composer\Console\Application
	 */
	public function get_composer_application(){
		if (!$this->composer_application){
			putenv('COMPOSER_HOME=' . $this->get_path_to_composer());
			$application = new Application();
			$application->setAutoExit(false);
			$this->composer_application = $application;
		}
		return $this->composer_application;
	}
	
	protected function get_default_output_formatter(){
		// Setup composer output formatter
		$stream = fopen('php://temp', 'w+');
		$output = new StreamOutput($stream);
		return $output;
	}
	
	/**
	 * 
	 * @param OutputInterface $output_formatter
	 * @return \Symfony\Component\Console\Output\OutputInterface
	 */
	public function install(OutputInterface $output_formatter = null) {
		if (is_null($output_formatter)){
			$output_formatter = $this->get_default_output_formatter();
		}
		$application = $this->get_composer_application();
		$code = $application->run(new ArrayInput(array('command' => 'install')), $output_formatter);
		
		return $output_formatter;
	}
	
	public function update(OutputInterface $output_formatter = null) {
		if (is_null($output_formatter)){
			$output_formatter = $this->get_default_output_formatter();
		}
		$application = $this->get_composer_application();
		$code = $application->run(new ArrayInput(array('command' => 'install')), $output_formatter);
	
		return $output_formatter;
	}
	
	public function dump_output(OutputInterface $output_formatter){
		$dump = '';
		if ($output_formatter instanceof StreamOutput){
			$stream  = $output_formatter->getStream();
			// rewind stream to read full contents
			rewind($stream);
			$dump = stream_get_contents($stream);
		} else {
			throw new \Exception('Cannot dump output of type "' . get_class($output_formatter) . '"!');
		}
		return $dump;
	}
}
?>