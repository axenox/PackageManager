<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use axenox\PackageManager\Common\Updater\UpdateDownloader;
use axenox\PackageManager\Common\Updater\ReleaseLogEntry;
use axenox\PackageManager\Common\Updater\ReleaseLog;
use axenox\PackageManager\Common\Updater\InstallationResponse;
use axenox\PackageManager\Common\Updater\SelfUpdateInstaller;

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
        $downloader = new UpdateDownloader($url, $username, $password, $downloadPath);
        $downloader->download();
        $releaseLog = new ReleaseLog($this->getWorkbench());
        $releaseLogEntry = new ReleaseLogEntry($releaseLog);
        $releaseLogEntry->fillLogFileFormatDownload($downloader);
        if($downloader->getStatusCode() != 200) {
            yield "No update available.";
            return;
        }
        yield "Downloaded file: " . $downloader->getFileName() . PHP_EOL;
        yield "Filesize: "  . $downloader->getFileSize() . " bytes" . PHP_EOL;
        yield $this->printLineDelimiter();
        
        // install file
        $installationFilePath = $downloadPath . $downloader->getFileName();
        $selfUpdateInstaller = new SelfUpdateInstaller($installationFilePath, $this->getWorkbench()->filemanager()->getPathToCacheFolder());
        yield from $selfUpdateInstaller->install();
        yield $this->printLineDelimiter();
        $releaseLogEntry->fillLogFileFormatInstallation($selfUpdateInstaller);
        
        // log
        $releaseLogEntry->addEntry();
        
        // update release file
        $installationSuccess = $selfUpdateInstaller->getInstallationSuccess();
        if($installationSuccess) {
            $releaseLogEntry->addNewDeployment($downloader->getTimestamp(), $downloader->getFileName());
        }
        
        // post request
        $postRequest = new InstallationResponse();
        // placeholder-URL
        $localUrl = "localhost:80/exface/exface/api/deployer/ota";
        // placeholder-Login
        $username = admin;
        $password = admin;
        $response = $postRequest->sendRequest($localUrl, $username, $password, $releaseLogEntry->getEntry(), $installationSuccess);
        yield "Post request content: " . PHP_EOL . PHP_EOL . $releaseLogEntry->getEntry();
        
        // server-response
        yield $this->printLineDelimiter();
        yield "Response (Placeholder): " . PHP_EOL . PHP_EOL . $response->getBody();
    }
    
    /**
     * empties output buffer for real-time output
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
