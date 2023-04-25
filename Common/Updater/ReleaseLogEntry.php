<?php
namespace axenox\PackageManager\Common\Updater;

use axenox\PackageManager\Common\Updater\SelfUpdateInstaller;

class ReleaseLogEntry
{    
    private $logsPath = null;
    
    private $logEntry = null;
    
    private $logFileName = null;
    
    private $releasePath = null;
    
    private $logArray = null;
    
    /**
     * 
     * @param ReleaseLog $log
     */
    public function __construct(ReleaseLog $log)
    {
        $this->logsPath = $log->getBasePath();
        $this->releasePath = $log->getReleasePath();
    }

    /**
     * 
     * @param UpdateDownloader $updateDownloader
     * @param number $fileNumber
     * @return ReleaseLogEntry
     */
    public function fillLogFileFormatDownload(UpdateDownloader $updateDownloader, $fileNumber = 1) : ReleaseLogEntry
    {
        $logArray = [];
        $logArray['Timestamp'] = $this->formatTimeStamp($updateDownloader->getTimestamp());
        $logArray[$fileNumber]['Filename'] = $updateDownloader->getFileName();
        $logArray[$fileNumber]['Filesize'] = $updateDownloader->getFileSize();
        $logArray[$fileNumber]['Download status'] = $updateDownloader->getFormatedStatusMessage();
        $this->logArray = $logArray;
        return $this;
    }
    
    /**
     * 
     * @param UploadedRelease $uploadedRelease
     * @return ReleaseLogEntry
     */
    public function fillLogFileFormatUpload(UploadedRelease $uploadedRelease) : ReleaseLogEntry
    {
        $fileNumber = 1;
        $logArray = [];
        $logArray['Timestamp'] = $this->formatTimeStamp($uploadedRelease->getTimestamp());
        foreach ($uploadedRelease->getUploadedFiles() as $file) {
            $logArray[$fileNumber]['Filename'] = $file->getClientFilename();
            $logArray[$fileNumber]['Filesize'] = $file->getSize();
            $logArray[$fileNumber]['Upload status'] = $uploadedRelease->getFormatedStatusMessage($file->UploadSuccess);
            $fileNumber++;
        }
        $this->logArray = $logArray;
        return $this;
    }

    /**
     * 
     * @param SelfUpdateInstaller $selfUpdateInstaller
     * @return ReleaseLogEntry
     */
    public function fillLogFileFormatInstallation(SelfUpdateInstaller $selfUpdateInstaller) : ReleaseLogEntry
    {
        $installArray = [];
        $logArray = $this->logArray;
        $requestType = array_key_exists('Download status', $logArray[1]) === true ? "download" : "upload";
        $this->logFileName = $installArray['Logfile name'] = $logArray['Timestamp'] . "_" . $requestType . "_" . $selfUpdateInstaller->getFormatedStatusMessage() . ".txt";
        $installArray['Logfile route'] = "log" . DIRECTORY_SEPARATOR . $installArray['Logfile name'];
        $installArray['Installation status'] = $selfUpdateInstaller->getFormatedStatusMessage();
        $this->logArray = $installArray + $logArray;
        return $this;
    }
    
    /**
     * 
     * @param string $timeStamp
     * @return string
     */
    protected function formatTimeStamp(string $timeStamp) : string
    {
        return date('Y-m-d_His', $timeStamp);
    }

    /**
     * 
     * @return ReleaseLogEntry
     */
    public function addEntry() : ReleaseLogEntry
    {
        $log = "";
        foreach($this->logArray as $logArrayKey => $logElement) {
            if(! is_numeric($logArrayKey)) {
                $log .= "{$logArrayKey}: {$logElement}" . PHP_EOL;
            } else {
                $log .= PHP_EOL . "File number: {$logArrayKey}" . PHP_EOL;
                foreach($logElement as $logElementKey => $fileValue) {
                    $log .= "{$logElementKey}: {$fileValue}" . PHP_EOL;
                }
            }  
        }
        $this->logEntry = $log;
        return $this;
    }
    
    /**
     * 
     * @param string $timeStamp
     * @param string $fileName
     */
    public function addNewDeployment(string $timeStamp, string $fileName)
    {
        $date = date('YmdHis', $timeStamp);
        $fileName = trim($fileName, ".phx");
        $newDeployment = PHP_EOL . "{$date},{$fileName}";
        file_put_contents($this->releasePath, $newDeployment, FILE_APPEND);
    }
    
    /**
     *
     * @return string
     */
    public function getEntry() : string
    {
        return $this->logEntry;
    }

    /**
     * saves logFile in $this->logsPath
     */
    public function __destruct()
    {
        $logFilePath = $this->logsPath . $this->logFileName;
        file_put_contents($logFilePath, $this->logEntry);
    }
}