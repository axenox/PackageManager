<?php namespace axenox\PackageManager\Actions;

use axenox\PackageManager\ComposerApi;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use exface\Core\Actions\ShowDialog;
use exface\Core\Widgets\AbstractWidget;
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
	
	protected function get_composer(){
		$composer = new ComposerApi($this->get_workbench()->get_installation_path());
		$composer->set_path_to_composer_home($this->get_workbench()->filemanager()->get_path_to_user_data_folder() . DIRECTORY_SEPARATOR . '.composer');
		return $composer;
	}
	
	/**
	 * @return OutputInterface
	 */
	protected abstract function perform_composer_action(ComposerApi $composer);
	
	protected function perform(){
		parent::perform();
		$output = $this->perform_composer_action();
		$this->set_result_message($this->dump_output($output));
		return;
	}
	
	protected function create_dialog_widget(AbstractWidget $contained_widget = null){
		$dialog = parent::create_dialog_widget($contained_widget);
		$page = $dialog->get_page();
		/* @var $console_widget \exface\Core\Widgets\InputText */
		$console_widget = WidgetFactory::create($page, 'InputText', $dialog);
		$console_widget->set_value($this->get_result_message());
		$dialog->add_widget($console_widget);
		return $dialog;
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