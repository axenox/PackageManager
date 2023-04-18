<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use axenox\PackageManager\Common\Updater\UploadFile;
use axenox\PackageManager\Common\Updater\DownloadFile;
use axenox\PackageManager\Common\Updater\LogFiles;
use axenox\PackageManager\Common\Updater\PostLog;
use axenox\PackageManager\Common\SelfUpdateInstaller;
use GuzzleHttp\Client;
use axenox\PackageManager\Actions\SelfUpdate;

/**
 * This action uninstalls one or more apps
 *
 * @author Andrej Kabachnik
 *
 */
class SelfUpdate extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::init()
     */
    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::LIST_);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred() : \Generator
    {
        $downloadPath = __DIR__ . '/../../../../Download/';
        $url = $this->getWorkbench()->getConfig()->getOption('UPDATE_URL');
        $username = $this->getWorkbench()->getConfig()->getOption('USERNAME');
        $password = $this->getWorkbench()->getConfig()->getOption('PASSWORD');
        
        yield "Downloading file..." . PHP_EOL;
        $this->emptyBuffer();
        $downloadFile = new DownloadFile();
        yield $downloadFile->download($url, $username, $password, $downloadPath);
        $download = $downloadFile->getDownload();
        if($downloadFile->getStatusCode() != 200) {
            yield "No update available.";
            $this->emptyBuffer();
        }
        yield "Downloaded file: " . $download->getFileName() . PHP_EOL;
        yield "Filesize: "  . $download->getContentSize() . " bytes" . PHP_EOL;
        yield $this->printLineDelimiter();
        $this->emptyBuffer();
        $installationFilePath = $downloadPath . $download->getFileName();
        $command = 'php -d memory_limit=2G';
        $installer = new SelfUpdateInstaller();
        yield $installer->install($command, $installationFilePath);
        $this->emptyBuffer();
        $log = $installer->getInstallationOutput();
        $status = $installer->getInstallationResult();
        $postLog = new PostLog();
        //Placeholder-URL
        $localUrl = "localhost:80/exface/exface/api/deployer/ota";
        yield $this->printLineDelimiter();
        $response = $postLog->postLog($localUrl, $username, $password, $log, $status);
        yield "Response: " . PHP_EOL . PHP_EOL;
        yield $response->getBody();
        $this->emptyBuffer();
    }
    
    /**
     * 
     * @return string
     */
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
    
    /**
     *
     */
    protected function emptyBuffer()
    {
        ob_flush();
        flush();
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments() : array
    {
        return [];
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() : array
    {
        return [];
    } 
}
