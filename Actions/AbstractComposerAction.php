<?php namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use exface\Core\Actions\ShowDialog;
use exface\Core\Factories\WidgetFactory;

/**
 * This action runs one or more selected test steps
 * 
 * @author Andrej Kabachnik
 *
 */
abstract class AbstractComposerAction extends ShowDialog {
	
	protected function init(){
		$this->set_input_rows_min(0);
		$this->set_input_rows_max(null);
	}
	
	/**
	 * 
	 * @return \axenox\PackageManager\ComposerAPI
	 */
	protected function get_composer(){
		$composer = new ComposerAPI($this->get_workbench()->get_installation_path());
		$composer->set_path_to_composer_home($this->get_workbench()->filemanager()->get_path_to_user_data_folder() . DIRECTORY_SEPARATOR . '.composer');
		return $composer;
	}
	
	/**
	 * @return OutputInterface
	 */
	protected abstract function perform_composer_action(ComposerAPI $composer);
	
	protected function perform(){
		parent::perform();
		$output = $this->perform_composer_action($this->get_composer());
		$output_text = $this->dump_output($output);
		$this->set_result_message($output_text);
		
		$dialog = $this->get_dialog_widget();
		$page = $dialog->get_page();
		/* @var $console_widget \exface\Core\Widgets\InputText */
		$console_widget = WidgetFactory::create($page, 'InputText', $dialog);
		$console_widget->set_height(10);
		$console_widget->set_value($output_text);
		$dialog->add_widget($console_widget);
		
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
			var_dump($output_formatter);
			throw new \Exception('Cannot dump output of type "' . get_class($output_formatter) . '"!');
		}
		return $dump;
	}
}
?>