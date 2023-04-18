<?php
namespace axenox\PackageManager\Common\Updater;

use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\DateTimeDataType;


class LogFiles
{
    public function __construct()
    {
    }

    /**
     * Creates Json for Log
     * @param string $jsonPath
     * @return NULL
     */
    public function createJson(string $logsPath)
    {
        $files = scandir($logsPath, SCANDIR_SORT_ASCENDING);
        $array = [];
        foreach($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $array[] = [
                'filename' => $file,
                'timestamp' => DateTimeDataType::cast(filemtime($logsPath . DIRECTORY_SEPARATOR . $file)),
                'details_url' => 'log' . DIRECTORY_SEPARATOR . $file
            ];
        }
        return JsonDataType::encodeJson($array, true);
    }

    /**
     * Creates log-file for each upload (not for each uploaded file!)
     * @param string $logsPath
     * @return string
     */
    public function createLogFileUpload($uploadedFiles, string $logsPath) : string
    {
        $fileNumber = 1;
        foreach($uploadedFiles as $file){
            $log = "Uploaded file " . $fileNumber . ":" . PHP_EOL;
            $log.= "Filename: " . $file->getClientFilename() . PHP_EOL;
            $log.= "Filesize: " . $file->getSize() . PHP_EOL;
            $log.= "Timestamp: " . $this->timeStamp . PHP_EOL;
            $log.= "Upload-Status: " . $file->status . PHP_EOL;
            $logFileName= "{$this->timeStamp}_upload_{$file->status}.txt";
            $log.= "Logfile-Name: " . "log" . DIRECTORY_SEPARATOR . $logFileName . PHP_EOL;
            $log .= PHP_EOL;
            $logFilePath = $logsPath . $logFileName;
            file_put_contents($logFilePath, $log, FILE_APPEND);
            $fileNumber++;
            return $log;
        }
    }
    
    /**
     * 
     * @param DownloadFile $downloadFile
     * @param string $installationStatus
     * @param string $logsPath
     * @return string
     */
    public function createLogFileSelfUpdate(DownloadFile $downloadFile, string $installationStatus, string $logsPath) : string
    {
        $log= "Filename: " . $downloadFile->getFileName() . PHP_EOL;
        $log.= "Filesize: " . $downloadFile->getContentSize() . PHP_EOL;
        $log.= "Timestamp: " . $downloadFile->getTimestamp() . PHP_EOL;
        $log.= "Download-Status: " . $downloadFile->getStatus() . PHP_EOL;
        $log.= "Installation-Status: " . $installationStatus . PHP_EOL;
        $logFileName= "{$downloadFile->getTimestamp()}_download_{$installationStatus}.txt";
        $log.= "Logfile-Name: " . "log" . DIRECTORY_SEPARATOR . $logFileName . PHP_EOL;
        $log .= PHP_EOL;
        $logFilePath = $logsPath . $logFileName;
        file_put_contents($logFilePath, $log, FILE_APPEND);
        return $log;
    }

    /**
     * 
     * @param string $releasesPath
     * @return string
     */
    public function getLatestDeployment(string $releasesPath) : string
    {
        $fileContent = file_get_contents($releasesPath);
        $arrayDeployments = preg_split("/\r\n|\n|\r/", $fileContent);
        if(end($arrayDeployments) !== "" && end($arrayDeployments) !== null){
            $lastDeployment = end($arrayDeployments);
        } else {
            end($arrayDeployments);
            $lastDeployment = prev($arrayDeployments);
        }
        return $lastDeployment;
    }
    
    /**
     * 
     * @param string $logPath
     * @param string $topics
     * @return string|NULL
     */
    public function getLogContent(string $logPath, string $topics) : ?string
    {
        // Scan all files in log-directory & make them lowercase
        $logFiles = array_map('strtolower', scandir($logPath));
        // If filename of pathInFacade exists, return content of log-file
        $fileName = explode("/", $topics)[1];
        if(in_array($fileName, $logFiles)){
            $filePath = $logPath . $fileName;
            return file_get_contents($filePath);
        } else {
            return null;
        }
    }

    /**
     * 
     * @param string $logsPath
     * @return string|NULL
     */
    public function getLatestLog(string $logsPath) : ?string
    {
        $files = scandir($logsPath, SCANDIR_SORT_DESCENDING);
        $output = "Last uploaded files (" . $files[0] . "):" . PHP_EOL. PHP_EOL;
        $output .= file_get_contents($logsPath . $files[0]);
        if(file_get_contents($logsPath . $files[0]) !== false){
            return $output;
        } else {
            return null;
        }
    }

    /**
     * Get all logs
     * @param string $jsonPath
     * @return string|NULL
     */
    public function getLogs(string $jsonPath) : ?string
    {
        $jsonFileName = $jsonPath . $this->pathInFacade . ".json";
        $jsonContent = file_get_contents($jsonFileName);
        if(file_get_contents($jsonFileName) !== false){
            return $jsonContent;
        } else {
            return null;
        }
    }
}