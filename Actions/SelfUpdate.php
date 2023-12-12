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
use exface\Core\CommonLogic\Actions\ServiceParameter;

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
        $downloadPath = $this->getWorkbench()->getInstallationPath() 
            . DIRECTORY_SEPARATOR . $this->getApp()->getConfig()->getOption('SELF_UPDATE.LOCAL.DOWNLOAD_PATH') 
            . DIRECTORY_SEPARATOR;
        $url = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.URL');
        $username = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.USERNAME');
        $password = $this->getApp()->getConfig()->getOption('SELF_UPDATE.SOURCE.PASSWORD');
        
        if (! $url || ! $username || ! $password) {
            throw new ActionConfigurationError($this, 'Incomplete self-update configuration: make sure `SELF_UPDATE.SOURCE.xxx` options are set in `axenox.PackageManager.config.json`');
        }
        
        // Download file
        $downloader = new UpdateDownloader($url, $username, $password, $downloadPath);
        /*$releaseLog = new ReleaseLog($this->getWorkbench());
        $releaseLogEntry = $releaseLog->createLogEntry();
        */
        
        yield PHP_EOL . "Downloading file...";
        yield PHP_EOL;
        
        $downloader->download();
        
        if($downloader->getStatusCode() != 200) {
            yield "No update available: " . $downloader->getStatusCode();
            return;
        }
        
        
        // save download infos in $releaseLogEntry->logArray
        /*
        $releaseLogEntry->addDownload($downloader);
        yield from $releaseLogEntry->getCurrentLogText();
        yield $this->printLineDelimiter();
        */
        
        if ($task->hasParameter('download-only')) {
            yield 'Download-only mode: stopping after download. Download location: ' . $downloader->getPathAbsolute();
            // $releaseLog->saveEntry($releaseLogEntry);
            $downloader->uploadLog($downloader->__toString());
            return;
        }
        
        // install file
        $log = $downloader->__toString();
        $selfUpdateInstaller = new SelfUpdateInstaller($downloader->getPathAbsolute(), $this->getWorkbench()->filemanager()->getPathToCacheFolder());
        foreach ($selfUpdateInstaller->install() as $line) {
            $log .= $line;
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
        $downloader->uploadLog($log);
        return;
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
