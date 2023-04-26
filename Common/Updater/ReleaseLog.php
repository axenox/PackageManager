<?php
namespace axenox\PackageManager\Common\Updater;

use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Interfaces\WorkbenchInterface;

class ReleaseLog
{    
    private $logsPath = null;
    
    private $workbench = null;
    
    private $releasePath = null;
    
    private $currentLog = null;
    
    /**
     * 
     * @param WorkbenchInterface $workbench
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
        $this->logsPath = $workbench->getInstallationPath() . DIRECTORY_SEPARATOR . '.dep' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
        $this->releasePath = $workbench->getInstallationPath() . DIRECTORY_SEPARATOR . '.dep' . DIRECTORY_SEPARATOR . 'releases';
    }
    
    /**
     * 
     * @param ReleaseLogEntry $releaseLogEntry
     */
    public function saveEntry(ReleaseLogEntry $releaseLogEntry)
    {
        $log = "";
        foreach($releaseLogEntry->getEntry() as $logArrayKey => $logElement) {
            if(! is_numeric($logArrayKey)) {
                $log .= "{$logArrayKey}: {$logElement}" . PHP_EOL;
            } else {
                $log .= PHP_EOL . "File number: {$logArrayKey}" . PHP_EOL;
                foreach($logElement as $logElementKey => $fileValue) {
                    $log .= "{$logElementKey}: {$fileValue}" . PHP_EOL;
                }
            }
        }
        $log .= PHP_EOL . "Updater Output: " . PHP_EOL . $releaseLogEntry->getUpdaterOutput();
        $this->currentLog = $log;
        $logFilePath = $this->logsPath . $releaseLogEntry->getLogFileName();
        file_put_contents($logFilePath, $this->currentLog);
    }

    /**
     * 
     * @return ReleaseLogEntry
     */
    public function createLogEntry() : ReleaseLogEntry
    {
        return new ReleaseLogEntry($this);
    }
    
    /**
     * 
     * @return string
     */
    public function getReleasePath()
    {
        return $this->releasePath;
    }
    
    /**
     * 
     * @return string
     */
    public function getBasePath() {
        return $this->logsPath;
    }
    
    /**
     * 
     * @return array
     */
    public function getLogEntries() : array
    {
        $files = scandir($this->logsPath, SCANDIR_SORT_ASCENDING);
        $releaseLogArray = [];
        foreach($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $releaseLogArray[] = [
                'filename' => $file,
                'timestamp' => DateTimeDataType::cast(filemtime($this->logsPath . DIRECTORY_SEPARATOR . $file)),
                'details_url' => 'log' . DIRECTORY_SEPARATOR . $file
            ];
        }
        return $releaseLogArray;
    }

    /**
     * 
     * @param string $releasesPath
     * @return string
     */
    public function getLatestDeployment() : string
    {
        $fileContent = file_get_contents($this->releasePath);
        $arrayDeployments = preg_split("/\r\n|\n|\r/", $fileContent);
        if(end($arrayDeployments) !== "" && end($arrayDeployments) !== null) {
            $lastDeployment = end($arrayDeployments);
        } else {
            end($arrayDeployments);
            $lastDeployment = prev($arrayDeployments);
        }
        return $lastDeployment;
    }
    
    /**
     * 
     * @return string
     */
    public function getCurrentLog() : string
    {
        return $this->currentLog;
    }
    
    /**
     *
     * @param string $logsPath
     * @return string|NULL
     */
    public function getLatestLog() : ?string
    {
        $files = scandir($this->logsPath, SCANDIR_SORT_DESCENDING);
        $output = "Last uploaded files (" . $files[0] . "):" . PHP_EOL. PHP_EOL;
        $output .= file_get_contents($this->logsPath . $files[0]);
        if(file_get_contents($this->logsPath . $files[0]) !== false) {
            return $output;
        } else {
            return null;
        }
    }
    
    /**
     * 
     * @param string $fileNamePath
     * @return string|NULL
     */
    public function getLogContent(string $fileNamePath) : ?string
    {
        // Scan all files in log-directory & make them lowercase
        $logFiles = array_map('strtolower', scandir($this->logsPath));
        // If filename of pathInFacade exists, return content of log file
        $fileName = explode("/", $fileNamePath)[1];
        if(in_array($fileName, $logFiles)) {
            $filePath = $this->logsPath . $fileName;
            return file_get_contents($filePath);
        } else {
            return null;
        }
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