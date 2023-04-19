<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use axenox\PackageManager\Common\Updater\DownloadFile;
use axenox\PackageManager\Common\Updater\LogFiles;
use axenox\PackageManager\Common\Updater\PostRequest;
use axenox\PackageManager\Common\SelfUpdateInstaller;

/**
 * 
 *
 * @author Thomas Ressel
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
        
        // Download file
        yield PHP_EOL . "Downloading file...";
        yield PHP_EOL . PHP_EOL;
        $downloadFile = new DownloadFile();
        $downloadFile->download($url, $username, $password, $downloadPath);
        if($downloadFile->getStatus() !== "Success") {
            yield "No update available.";
            return;
        }
        yield "Downloaded file: " . $downloadFile->getFileName() . PHP_EOL;
        yield "Filesize: "  . ($downloadFile->getContentSize() !== "Unknown" ? $downloadFile->getContentSize() . " bytes": $downloadFile->getContentSize()) . PHP_EOL;
        yield $this->printLineDelimiter();
        
        // install file
        $selfUpdateInstaller = new SelfUpdateInstaller();
        $installationFilePath = $downloadPath . $downloadFile->getFileName();
        $command = 'php -d memory_limit=2G';
        yield from $selfUpdateInstaller->install($command, $installationFilePath);
        $installationStatus = $selfUpdateInstaller->getInstallationStatus();
        $logFiles = new LogFiles();
        $logsPath = __DIR__ . '/../../../../.dep/log/';
        $log = $logFiles->createLogFileSelfUpdate($downloadFile, $installationStatus, $logsPath);
        if($installationStatus === "Success") {
            $releasesPath = __DIR__ . '/../../../../.dep/releases';
            $logFiles->addNewDeployment($releasesPath, $downloadFile);
        }
        
        // post request
        $postRequest = new PostRequest();
        //Placeholder-URL
        $localUrl = "localhost:80/exface/exface/api/deployer/ota";
        // Placeholder-Login
        $username = admin;
        $password = admin;
        $response = $postRequest->sendRequest($localUrl, $username, $password, $log, $installationStatus);
        yield $this->printLineDelimiter();
        yield "Post request content: " . PHP_EOL . PHP_EOL . $log;
        yield $this->printLineDelimiter();
        yield "Response (Placeholder): " . PHP_EOL . PHP_EOL . $response->getBody();
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
     * @return string
     */
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
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
