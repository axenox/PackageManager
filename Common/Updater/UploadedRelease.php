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
     * @return string
     */
    public function getFormatedStatusMessage(bool $success) : string
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
    public function getUploadedFiles() : array
    {
        return $this->uploadedFiles;
    }
}