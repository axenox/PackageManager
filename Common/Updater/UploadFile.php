<?php
namespace axenox\PackageManager\Common\Updater;

class UploadFile
{
    private $request = null;
    
    private $timeStamp = null;
    
    private $uploadedFiles = null;
    
    public function __construct($request)
    {
        $this->request = $request;
        $this->timeStamp = date("Y-m-d") . "_" . date("His");
    }
    
    /**
     * Moves uploaded files to folder and sets upload-Status
     * @param string $uploadPath
     * @return NULL
     */
    public function moveUploadedFiles(string $uploadPath)
    {
        foreach($this->request->getUploadedFiles() as $uploadedFile) {
            /* @var $uploadedFile \GuzzleHttp\Psr7\UploadedFile */
            $fileName = $uploadedFile->getClientFilename();
            $uploadedFile->moveTo($uploadPath.$fileName);
            $this->setSuccess($uploadedFile);
        }
        $this->uploadedFiles = $this->request->getUploadedFiles();
    }
    
    /**
     * 
     * @return string
     */
    public function getTimestamp() : string
    {
        return $this->timeStamp;
    }
    
    /**
     * 
     * @return array
     */
    public function getUploadedFiles() : array
    {
        return $this->uploadedFiles;
    }

    /**
     *
     * @return string
     */
    public function getCurlOutput() : ?string
    {
        if($this->request->getUploadedFiles() !== []){
            $output = PHP_EOL. "Uploaded files:" . PHP_EOL . PHP_EOL;
            foreach($this->request->getUploadedFiles() as $uploadedFile) {
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
     */
    protected function setSuccess($uploadedFile)
    {
        if($uploadedFile->isMoved()) {
            $uploadedFile->status = "Success";
        } else {
            $uploadedFile->status = "Failure";
        }
    }
}