<?php
namespace axenox\PackageManager\Common\Updater;

class UploadFile
{
    private $request = null;
    
    private $pathInFacade = null;
    
    private $timeStamp = null;
    
    public function __construct($request, $pathInFacade)
    {
        $this->request = $request;
        $this->timeStamp = date("Y-m-d") . "_" . date("His");
        $this->pathInFacade = $pathInFacade;
    }
    
    /**
     * Moves uploaded files to folder and sets Upload-Status
     * @param string $uploadPath
     * @return NULL
     */
    public function moveUploadedFiles(string $uploadPath)
    {
        foreach($this->request->getUploadedFiles() as $uploadedFile){
            /* @var $uploadedFile \GuzzleHttp\Psr7\UploadedFile */
            $fileName = $uploadedFile->getClientFilename();
            $uploadedFile->moveTo($uploadPath.$fileName);
            $this->setSuccess($uploadedFile);
        }
    }
    
    /**
     * Creates log-file for each upload (not for each uploaded file!)
     * @param string $logsPath
     * @return NULL
     */
    public function createLogFile(string $logsPath)
    {
        $fileNumber = 1;
        foreach($this->request->getUploadedFiles() as $uploadedFile){
            $log = "Uploaded file " . $fileNumber . ":" . PHP_EOL;
            $log.= "Filename: " . $uploadedFile->getClientFilename() . PHP_EOL;
            $log.= "Filesize: " . $uploadedFile->getSize() . PHP_EOL;
            $log.= "Timestamp: " . $this->timeStamp . PHP_EOL;
            $log.= "Upload-Status: " . $uploadedFile->status . PHP_EOL;
            $logFileName= $this->timeStamp . "_" . $this->pathInFacade . "_" . $uploadedFile->status . ".txt";
            $log.= "Logfile-Name: " . "log" . DIRECTORY_SEPARATOR . $logFileName . PHP_EOL;
            $log .= PHP_EOL;
            $logFilePath = $logsPath . $logFileName;
            file_put_contents($logFilePath, $log, FILE_APPEND);
            $fileNumber++;
        }
    }
    
    /**
     *
     * @return string
     */
    public function getCurlOutput() : ?string
    {
        if($this->request->getUploadedFiles() !== []){
            $output = PHP_EOL. "Uploaded files:" . PHP_EOL . PHP_EOL;
            foreach($this->request->getUploadedFiles() as $uploadedFile){
                /* @var $uploadedFile \GuzzleHttp\Psr7\UploadedFile */
                $output .= "Filename: " . $uploadedFile->getClientFilename() . PHP_EOL;
                $output .= "Filesize: " . $uploadedFile->getSize() . PHP_EOL;
                $output .= "Status: " . $uploadedFile->status . PHP_EOL . PHP_EOL;
            }
        }
        return $output;
    }
    
    /**
     * Sets status if moving of file/upload was successful
     * @param UploadFile $uploadedFile
     * @return string
     */
    protected function setSuccess($uploadedFile)
    {
        if($uploadedFile->isMoved()){
            $uploadedFile->status = "Success";
        } else {
            $uploadedFile->status = "Failure";
        }
        return $uploadedFile;
    }
}