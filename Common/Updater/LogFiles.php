<?php
namespace axenox\PackageManager\Common\Updater;

use exface\Core\DataTypes\JsonDataType;
use exface\Core\CommonLogic\Filemanager;
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