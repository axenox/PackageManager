<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Psr7\ServerRequest;

/**
 * Contains the result of an upload request to the UpdaterFacade
 * 
 * @author thomas.ressel
 *
 */
class UploadedRelease
{
    private $request = null;
    
    private $timeStamp = null;
    
    private $uploadedFiles = null;
    
    /**
     * 
     * @param ServerRequest $request
     */
    public function __construct(ServerRequest $request)
    {
        $this->request = $request;
        $this->timeStamp = time();
    }
    
    /**
     * Moves uploaded files to folder and sets upload-Status
     * @param string $uploadPath
     * @return NULL
     */
    public function moveUploadedFiles(string $uploadPath)
    {
        $this->uploadedFiles = $this->request->getUploadedFiles();
        foreach($this->request->getUploadedFiles() as $uploadedFile) {
            /* @var $uploadedFile \GuzzleHttp\Psr7\UploadedFile */
            $fileName = $uploadedFile->getClientFilename();
            $uploadedFile->moveTo($uploadPath.$fileName);
            $uploadedFile->UploadSuccess = $uploadedFile->isMoved();
        }
    }
    
    /**
     * Placeholder
     * @return string
     */
    public function getInstallationFileName() : string
    {
        return $this->getUploadedFiles()['file1']->getClientFilename();
    }
    
    /**
     * 
     * @return array
     */
    public function fillLogFileFormat() : array
    {
        $fileNumber = 1;
        $logArray = [];
        $logArray['Timestamp'] = $this->formatTimeStamp();
        foreach ($this->getUploadedFiles() as $file) {
            $logArray[$fileNumber]['Filename'] = $file->getClientFilename();
            $logArray[$fileNumber]['Filesize'] = $file->getSize();
            $logArray[$fileNumber]['Upload status'] = $this->formatStatusMessage($file->UploadSuccess);
            $fileNumber++;
        }
        return $logArray;
    }
    
    /**
     * 
     * @return string
     */
    protected function formatTimeStamp() : string
    {
        return date('Y-m-d_His', $this->timeStamp);
    }
    
    /**
     *
     * @return string
     */
    protected function formatStatusMessage(bool $success) : string
    {
        return $success === true  ? "Success" : "Failure";
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
    protected function getUploadedFiles() : array
    {
        return $this->uploadedFiles;
    }
}