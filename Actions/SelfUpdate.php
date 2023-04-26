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
use exface\Core\Exceptions\Actions\ActionConfigurationError;

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
        $downloadPath = $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $this->getApp()->getConfig()->getOption('SELF_UPDATE.LOCAL.DOWNLOAD_PATH');
        $url = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.URL');
        $username = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.USERNAME');
        $password = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.PASSWORD');
        
        if (! $url || ! $username || ! $password) {
            throw new ActionConfigurationError($this, 'Incomplete self-update configuration: make sure `SELF_UPDATE.SOURCE.xxx` options are set in `axenox.PackageManager.config.json`');
        }
        
        // Download file
        $downloader = new UpdateDownloader($url, $username, $password, $downloadPath);
        $releaseLog = new ReleaseLog($this->getWorkbench());
        $releaseLogEntry = $releaseLog->createLogEntry();
        
        foreach ($this->processDownload($downloader, $releaseLogEntry) as $output) {
            $releaseLogEntry->addUpdaterOutput($output);
            yield $output;
        }
        
        $releaseLog->saveEntry($releaseLogEntry);
        
        yield from $this->installationResponse($releaseLog);
    }

    /**
     * 
     * @param UpdateDownloader $downloader
     * @param ReleaseLogEntry $releaseLogEntry
     * @return \Generator
     */
    protected function processDownload(UpdateDownloader $downloader, ReleaseLogEntry $releaseLogEntry) : \Generator
    {
        yield PHP_EOL . "Downloading file...";
        yield PHP_EOL;
        $downloader->download();
        
        if($downloader->getStatusCode() != 200) {
            yield "No update available.";
            return;
        }
        
        // save download infos in $releaseLogEntry->logArray
        $releaseLogEntry->addDownload($downloader);
        yield from $releaseLogEntry->getCurrentLogText();
        yield $this->printLineDelimiter();
        
        // install file
        $selfUpdateInstaller = new SelfUpdateInstaller($downloader->getPathAbsolute(), $this->getWorkbench()->filemanager()->getPathToCacheFolder());
        yield from $selfUpdateInstaller->install();
        // save installation infos in $releaseLogEntry->logArray
        $releaseLogEntry->addInstallation($selfUpdateInstaller);
        
        // update release file if installation was successful
        if($selfUpdateInstaller->getInstallationSuccess()) {
            // TODO wo gehört das hin?
            $releaseLogEntry->addDeploymentSuccess($selfUpdateInstaller->getTimestamp(), $downloader->getFileName());
        }
    }
    
    /**
     * TODO
     * @param ReleaseLogEntry $releaseLogEntry
     * @return \Generator
     */
    protected function installationResponse(ReleaseLog $releaseLog) : \Generator
    {
        // post request
        $installationResponse = new InstallationResponse();
        // placeholder-URL
        $localUrl = "localhost:80/exface/exface/api/deployer/ota";
        // placeholder-Login
        $username = admin;
        $password = admin;
        $response = $installationResponse->sendRequest($localUrl, $username, $password, $releaseLog->getCurrentLog(), "Success");
        yield $this->printLineDelimiter();
        yield "Post request content: " . PHP_EOL . PHP_EOL . $releaseLog->getCurrentLog();
        yield $this->printLineDelimiter();
        // server-response
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
     * Prints NEWLINE ------------------------ NEWLINE
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
