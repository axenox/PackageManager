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
        $config = $this->getApp()->getConfig();
        $downloadPathRelative = FilePathDataType::normalize($config->getOption('SELF_UPDATE.LOCAL.DOWNLOAD_PATH'), DIRECTORY_SEPARATOR);
        $downloadPathAbsolute = $this->getWorkbench()->getInstallationPath() 
            . DIRECTORY_SEPARATOR . $downloadPathRelative
            . DIRECTORY_SEPARATOR;
        $url = $config->getOption('SELF_UPDATE.SOURCE.URL');
        $username = $config->getOption('SELF_UPDATE.SOURCE.USERNAME');
        $password = $config->getOption('SELF_UPDATE.SOURCE.PASSWORD');
        
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
            foreach ($downloader->download($config->getOption('SELF_UPDATE.DOWNLOAD.USE_CLI_CURL')) as $line) {
                yield $downloader->uploadLog($line);
            }
        } catch (\Throwable $e) {
            $msg = 'FAILED to download self-update package: ' . $e->getMessage();
            try {
                $msg .= PHP_EOL . $downloader->__toString();
            } catch (\Throwable $eDetails) {
                $this->getWorkbench()->getLogger()->logException(
                    new RuntimeException('Cannot dump details for self-update download error. ' . $eDetails->getMessage(), null, $eDetails)
                );
                $msg .= PHP_EOL . 'Unknown error: ' . $eDetails->getMessage();
            }
            $downloader->uploadLog($msg, 90, true);
            throw new RuntimeException($msg . ' ' . $e->getMessage(), null, $e);
        }
        
        switch (true) {
            case $downloader->getStatusCode() == 304:
                yield "No update available: " . $downloader->getStatusCode();
                return;
            case $downloader->getStatusCode() == 200:
                yield $downloader->uploadLog('Downloaded to "' . $downloadPathRelative . '"', 65) . PHP_EOL;
                break;
            default:
                $msg = 'FAILED to download: unexpected response code' . $downloader->getStatusCode();
                try {
                    $downloader->uploadLog($downloader->__toString() . PHP_EOL . $msg, 90, true) . PHP_EOL;
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
            yield $downloader->uploadLog('Download-only mode: stopping after download. Download location: ' . FilePathDataType::normalize($downloader->getPathAbsolute(), DIRECTORY_SEPARATOR), 67);
            return;
        }
        
        try {
            $php = $config->getOption('SELF_UPDATE.LOCAL.PHP_EXECUTABLE');
            $selfUpdateInstaller = new SelfUpdateInstaller($downloader->getPathAbsolute(), $this->getWorkbench()->filemanager()->getPathToCacheFolder(), $php);
            yield $downloader->uploadLog('Running .phx file now', 70);
            foreach ($selfUpdateInstaller->install() as $line) {
                $status = null;
                switch (true) {
                    case preg_match('/Extracting archive/i', $line):
                        $status = 72; // Extracting
                        break;
                    case preg_match('/Archive extracted!/i', $line):
                        $status = 73; // Extracted
                        break;
                    case preg_match('/Symlink to current created!/i', $line):
                        $status = 75; // Symlink switched
                        break;
                    case preg_match('/Installing apps.../i', $line):
                        $status = 76; // Installing
                        break;
                    case preg_match('/Deleting release/i', $line):
                        $status = 78; // Cleaning up
                        break;
                }
                yield $downloader->uploadLog($line, $status);
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
            */
        } catch (\Throwable $e) {
            yield $downloader->uploadLog('FAILED self-update!' . PHP_EOL . PHP_EOL . 'ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine(), 90, true);
            throw $e;
        }
        
        // Finish things up
        $msg = PHP_EOL . PHP_EOL . 'Finished self-update successfully!';
        try {
            $downloader->uploadLog($msg, 99, true);
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