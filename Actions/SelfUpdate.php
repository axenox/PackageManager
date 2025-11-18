<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Exceptions\RuntimeException;
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
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\FilePathDataType;

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
        return [
            $task
        ];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(TaskInterface $task = null) : \Generator
    {
        $downloadPathRelative = FilePathDataType::normalize($this->getApp()->getConfig()->getOption('SELF_UPDATE.LOCAL.DOWNLOAD_PATH'), DIRECTORY_SEPARATOR);
        $downloadPathAbsolute = $this->getWorkbench()->getInstallationPath() 
            . DIRECTORY_SEPARATOR . $downloadPathRelative
            . DIRECTORY_SEPARATOR;
        $url = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.URL');
        $username = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.USERNAME');
        $password = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.PASSWORD');
        
        if (! $url || ! $username || ! $password) {
            throw new ActionConfigurationError($this, 'Incomplete self-update configuration: make sure `SELF_UPDATE.SOURCE.xxx` options are set in `axenox.PackageManager.config.json`');
        }
        
        // Download file
        $downloader = new UpdateDownloader($url, $username, $password, $downloadPathAbsolute, $this->getWorkbench()->getLogger());
        /*$releaseLog = new ReleaseLog($this->getWorkbench());
        $releaseLogEntry = $releaseLog->createLogEntry();
        */
        
        yield PHP_EOL . "Checking remote for an update file...";
        yield PHP_EOL;
        
        try {
            $downloader->download();
        } catch (\Throwable $e) {
            $msg = 'FAILED to download self-update package: ' . $e->getMessage() . PHP_EOL;
            try {
                $downloader->uploadLog($downloader->__toString() . PHP_EOL . $msg);
            } catch (\Throwable $eUpload) {
                $this->getWorkbench()->getLogger()->logException($eUpload);
            }
            throw new RuntimeException($msg, null, $e);
        }
        
        switch (true) {
            case $downloader->getStatusCode() == 304:
                yield "No update available: " . $downloader->getStatusCode();
                return;
            case $downloader->getStatusCode() == 200:
                $msg =  'Downloaded to "' . $downloadPathRelative . '"' . PHP_EOL;
                $downloader->uploadLog($downloader->__toString() . PHP_EOL . $msg);
                yield $msg;
                break;
            default:
                $msg = 'FAILED to download: unexpected response code' . $downloader->getStatusCode() . PHP_EOL;
                try {
                    $downloader->uploadLog($downloader->__toString() . PHP_EOL . $msg, true);
                } catch (\Throwable $e) {
                    $this->getWorkbench()->getLogger()->logException($e);
                }
                throw new RuntimeException('Could not download self-update package: ' . $msg);
        }
        
        // save download infos in $releaseLogEntry->logArray
        /*
        $releaseLogEntry->addDownload($downloader);
        yield from $releaseLogEntry->getCurrentLogText();
        yield $this->printLineDelimiter();
        */
        
        if ($task->hasParameter('download-only')) {
            // $releaseLog->saveEntry($releaseLogEntry);
            yield $downloader->uploadLog('Download-only mode: stopping after download. Download location: ' . $downloader->getPathAbsolute());
            return;
        }
        
        try {
            $php = $this->getApp()->getConfig()->getOption('SELF_UPDATE.LOCAL.PHP_EXECUTABLE');
            $selfUpdateInstaller = new SelfUpdateInstaller($downloader->getPathAbsolute(), $this->getWorkbench()->filemanager()->getPathToCacheFolder(), $php);
            foreach ($selfUpdateInstaller->install() as $line) {
                yield $downloader->uploadLog($line);
            }
        
            /*
            // save installation infos in $releaseLogEntry->logArray
            $releaseLogEntry->addInstallation($selfUpdateInstaller);
            
            // update release file if installation was successful
            if($selfUpdateInstaller->getInstallationSuccess()) {
                // TODO wo gehört das hin?
                $releaseLogEntry->addDeploymentSuccess($selfUpdateInstaller->getTimestamp(), $downloader->getFileName());
            }
            
            $releaseLog->saveEntry($releaseLogEntry);
            
            // $downloader->uploadLog($releaseLogEntry->???); 
            */
        } catch (\Throwable $e) {
            yield $downloader->uploadLog(PHP_EOL . PHP_EOL . 'ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine(), true);
            throw $e;
        }
        
        // Finish things up
        $msg = PHP_EOL . PHP_EOL . 'Finished self-update successfully!';
        try {
            $downloader->uploadLog($msg, true);
            yield PHP_EOL . 'Uploaded log to OTA server';
        } catch (\Throwable $e) {
            yield PHP_EOL . PHP_EOL . 'ERROR when uploading installation log: '  . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        }
            
        yield $msg;
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
        return [
            (new ServiceParameter($this))
                ->setName('download-only')
                ->setDescription('Download package, but do not install')
        ];
    } 
}