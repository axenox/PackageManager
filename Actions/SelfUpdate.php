<?php
namespace axenox\PackageManager\Actions;

use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\ConfigurationInterface;
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
        
        $uploadLogParam = $task->getParameter('upload-log');
        
        // Download file
        $downloader = new UpdateDownloader($url, $username, $password, $downloadPathAbsolute, $this->getWorkbench()->getLogger());
        
        // TODO write a logger, that is easy to use via `yield $logger->log('message')` and will automatically decide,
        // when to upload the log to the OTA server (= when an update is downloaded and NOT when no update is available,
        // because then the server has no deployment log to write to). The logger should also maintain separate logs
        // for each update (not for each check) locally and offer methods to list available logs, open them, etc. These
        // methods should be accessible from CLI (e.g. `php dep self-update --list-logs` or `php dep self-update --show-log=datetime`).
        // We also might want to make them accessible through an HTTP facade, so that the build server can fetch logs
        // or status explicitly. That facade could also allow explicit rollbacks.
        /*$releaseLog = new ReleaseLog($this->getWorkbench());
        $releaseLogEntry = $releaseLog->createLogEntry();
        */
        
        yield PHP_EOL . "Checking remote for an update file...";
        yield PHP_EOL;
        
        if (BooleanDataType::cast($task->getParameter('download')) === false) {
            yield "Skipping download as per command parameter `download=false`.";
        } else {
            yield from $this->performDownload($downloader, $config);
        }
        
        // save download infos in $releaseLogEntry->logArray
        /*
        $releaseLogEntry->addDownload($downloader);
        yield from $releaseLogEntry->getCurrentLogText();
        yield $this->printLineDelimiter();
        */
        
        if (BooleanDataType::cast($task->getParameter('install')) === false) {
            // $releaseLog->saveEntry($releaseLogEntry);
            yield $downloader->uploadLog('Installation explicitly disabled by command option. Download location: ' . FilePathDataType::normalize($downloader->getPathAbsolute(), DIRECTORY_SEPARATOR), 67);
        } else {
            yield from $this->performInstallation($downloader, $config);
        }

        // Finish things up
        if (is_string($uploadLogParam)) {
            if (FilePathDataType::isAbsolute($uploadLogParam)) {
                $logPath = $uploadLogParam;
            } else {
                $logPath = FilePathDataType::join([
                    $this->getWorkbench()->getInstallationPath(), 
                    $uploadLogParam 
                ]);
            }
            if (! file_exists($logPath)) {
                $msg = PHP_EOL . PHP_EOL . 'ERROR: File specified in parameter `upload-log` not found at "' . $logPath . '"';
            } else {
                $msg = file_get_contents($uploadLogParam);
            }
            $status = null;
        } else {
            $msg = PHP_EOL . PHP_EOL . 'Finished self-update successfully!';
            $status = 99;
        }
        
        try {
            $downloader->uploadLog($msg, $status, true);
            yield PHP_EOL . 'Uploaded log to OTA server';
        } catch (\Throwable $e) {
            yield PHP_EOL . PHP_EOL . 'ERROR when uploading installation log: '  . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        }
        yield $msg;
    }
    
    protected function performDownload(UpdateDownloader $downloader, ConfigurationInterface $config) : \Generator
    {
        try {
            $logNotUploadedYet = '';
            foreach ($downloader->download($config->getOption('SELF_UPDATE.DOWNLOAD.USE_CLI_CURL')) as $line) {
                if ($downloader->isDeploying()) {
                    yield $downloader->uploadLog($logNotUploadedYet . $line);
                    $logNotUploadedYet = '';
                } else {
                    yield $line;
                    $logNotUploadedYet .= $line;
                }
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
                yield $downloader->uploadLog('Downloaded to "' . $downloader->getPathAbsolute() . '"', 65) . PHP_EOL;
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
    }
    
    protected function performInstallation(UpdateDownloader $downloader, ConfigurationInterface $config) : \Generator
    {
        try {
            $php = $config->getOption('SELF_UPDATE.LOCAL.PHP_EXECUTABLE');
            $selfUpdateInstaller = new SelfUpdateInstaller($downloader->getPathAbsolute(), $this->getWorkbench()->filemanager()->getPathToCacheFolder(), $php);
            yield $downloader->uploadLog('Running .phx file now', 70);
            foreach ($selfUpdateInstaller->install() as $line) {
                $status = null;
                switch (true) {
                    case preg_match('/Extracting .*/i', $line):
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
                    case preg_match('/Installation failed/i', $line):
                        throw new ActionRuntimeError($this, $line);
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
                ->setName('download')
                ->setDescription('Check to see if an update is available and download it - true or false (default: true)')
            , (new ServiceParameter($this))
                ->setName('install')
                ->setDescription('Install the downloaded update - true or false (default: true). If true, but download was false, will install the latest downloaded .phx file')
            , (new ServiceParameter($this))
                ->setName('upload-log')
                ->setDescription('Upload the output of the command to the OTA server - true, false or path to text file (default: true)')
        ];
    } 
}