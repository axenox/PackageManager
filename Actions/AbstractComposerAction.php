<?php
namespace axenox\PackageManager\Actions;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use exface\Core\Actions\ShowDialog;
use exface\Core\Factories\WidgetFactory;
use kabachello\ComposerAPI\ComposerAPI;

/**
 * This action runs one or more selected test steps
 *
 * @author Andrej Kabachnik
 *        
 */
abstract class AbstractComposerAction extends ShowDialog
{

    protected function init()
    {
        $this->setInputRowsMin(0);
        $this->setInputRowsMax(null);
        $this->setPrefillWithFilterContext(false);
    }

    /**
     *
     * @return \axenox\PackageManager\ComposerAPI
     */
    protected function getComposer()
    {
        $composer = new ComposerAPI($this->getWorkbench()->getInstallationPath());
        $composer->set_path_to_composer_home($this->getWorkbench()->filemanager()->getPathToUserDataFolder() . DIRECTORY_SEPARATOR . '.composer');
        return $composer;
    }

    /**
     *
     * @return OutputInterface
     */
    protected abstract function performComposerAction(ComposerAPI $composer);

    protected function perform()
    {
        parent::perform();
        $output = $this->performComposerAction($this->getComposer());
        $output_text = $this->dumpOutput($output);
        $this->setResultMessage($output_text);
        
        $dialog = $this->getDialogWidget();
        $page = $dialog->getPage();
        /* @var $console_widget \exface\Core\Widgets\InputText */
        $console_widget = WidgetFactory::create($page, 'InputText', $dialog);
        $console_widget->setHeight(10);
        $console_widget->setValue($output_text);
        $dialog->addWidget($console_widget);
        
        return;
    }

    public function dumpOutput(OutputInterface $output_formatter)
    {
        $dump = '';
        if ($output_formatter instanceof StreamOutput) {
            $stream = $output_formatter->getStream();
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