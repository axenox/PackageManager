<?php namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\AbstractAction;
use axenox\PackageManager\ComposerApi;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
class ComposerUpdate extends AbstractAction {
	
	protected function init(){
		$this->set_icon_name('repair');
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}	
	
	protected function perform(){
		$composer = new ComposerApi($this->get_workbench()->get_installation_path());
		$output = $composer->update();
		$this->set_result_message($this->dump_output($output));
		return;
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