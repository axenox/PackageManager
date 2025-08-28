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
        $log = '';
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
        $downloader = new UpdateDownloader($url, $username, $password, $downloadPathAbsolute);
        /*$releaseLog = new ReleaseLog($this->getWorkbench());
        $releaseLogEntry = $releaseLog->createLogEntry();
        */
        
        yield PHP_EOL . "Downloading file...";
        yield PHP_EOL;
        
        try {
            $downloader->download();
            yield 'Downloaded to "' . $downloadPathRelative . '"' . PHP_EOL;
        } catch (\Throwable $e) {
            yield 'FAILED to download: ' . $e->getMessage() . PHP_EOL;
        }
        
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
            $msg = 'Download-only mode: stopping after download. Download location: ' . $downloader->getPathAbsolute();
            $log .= $msg;
            yield $msg;
            // $releaseLog->saveEntry($releaseLogEntry);
            $downloader->uploadLog($downloader->__toString());
            return;
        }
        
        // install file
        $log = $downloader->__toString();
        
        try {
            $php = $this->getApp()->getConfig()->getOption('SELF_UPDATE.LOCAL.PHP_EXECUTABLE');
            $selfUpdateInstaller = new SelfUpdateInstaller($downloader->getPathAbsolute(), $this->getWorkbench()->filemanager()->getPathToCacheFolder(), $php);
            foreach ($selfUpdateInstaller->install() as $line) {
                $log .= $line;
                yield $line;
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
            $eMsg = PHP_EOL . PHP_EOL . 'ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
            $log .= $eMsg;
            yield $eMsg;
            $this->getWorkbench()->getLogger()->logException($e);
        }
        
        // Upload results to the OTA server
        try {
            $downloader->uploadLog($log);
            yield PHP_EOL . 'Uploaded log to OTA server';
        } catch (\Throwable $e) {
            yield PHP_EOL . PHP_EOL . 'ERROR when uploading installation log: '  . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        }
            
        yield PHP_EOL . PHP_EOL . 'Finished self-update successfully!';
        
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