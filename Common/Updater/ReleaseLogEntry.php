<?php
namespace axenox\PackageManager\Common\Updater;

class ReleaseLogEntry
{    
    private $logsPath = null;
    
    private $log = null;
    
    private $logFileName = null;
    
    private $releasePath = null;
    
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
     * @param array $logArray
     * @return ReleaseLogEntry
     */
    public function addEntry(array $logArray) : ReleaseLogEntry
    {
        $log = $this->log;
        $this->logFileName = $logArray['Logfile name'];
        foreach($logArray as $logArrayKey => $logElement) {
            if(! is_numeric($logArrayKey)) {
                $log .= "{$logArrayKey}: {$logElement}" . PHP_EOL;
            } else {
                $log .= PHP_EOL . "File number: {$logArrayKey}" . PHP_EOL;
                foreach($logElement as $logElementKey => $fileValue) {
                    $log .= "{$logElementKey}: {$fileValue}" . PHP_EOL;
                }
            }  
        }
        $this->log = $log;
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
        return $this->log;
    }
    
    /**
     * saves logFile in $this->logsPath
     */
    public function __destruct()
    {
        $logFilePath = $this->logsPath . $this->logFileName;
        file_put_contents($logFilePath, $this->log);
    }
}