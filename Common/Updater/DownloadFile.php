<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;
use axenox\PackageManager\Common\SelfUpdateInstaller;

class DownloadFile
{
    private $downloadedBytes = null;
    
    private $statusCode = null;
    
    private $headers = null;
    
    private $contentSize = null;
    
    /**
     * 
     * @param string $url
     * @param string $username
     * @param string $password
     * @param string $downloadPath
     * @return SelfUpdate
     */
    public function download(string $url, string $username, string $password, string $downloadPath) : DownloadFile
    {
        $client = new Client();
        /* @var $client \GuzzleHttp\Client */
        $response = $client->request('GET', $url, ['auth' => [$username, $password]],
            ['progress' => function($downloadTotal,$downloadedBytes)
            {
                $this->progress($downloadTotal,$downloadedBytes);
            }
            ]);
        if ($response->getStatusCode() === 200) {
            $this->setStatusCode($response->getStatusCode());
            $content = $response->getBody();
            $this->headers = $response->getHeaders();
            $this->contentSize = $this->getContentSizeFromResponse($response);
            $fileName = $this->getFileName();
            file_put_contents($downloadPath . $fileName, $content);
        } else {
            $this->setStatusCode($response->getStatusCode());
        }
        return $this;
    }
    
    public function getFileName()
    {
        $header = $this->headers['Content-Disposition'][0];
        return end(explode("filename=", $header));
    }
    
    public function getHeaders()
    {
        return $this->headers;
    }
    
    public function getContentSize()
    {
        return $this->contentSize;
    }

    /**
     * 
     * @param unknown $statuscode
     */
    protected function setStatusCode($statuscode)
    {
        $this->statusCode = $statuscode;
    }

    /**
     * 
     * @return string
     */
    public function getStatusCode() : string
    {
        return $this->statusCode;
    }
    
    /**
     * 
     * @param unknown $response
     * @return string
     */
    protected function getContentSizeFromResponse($response) : string
    {
        if ($response->hasHeader('content-length')){
            $contentLength = $response->getHeader('content-length')[0];
            return $contentLength;
        } return "Unknown";
    }
    
    /**
     * 
     * @param unknown $downloadTotal
     * @param int $downloadedBytes
     */
    protected function progress($downloadTotal,int $downloadedBytes)
    {
        if ($downloadedBytes !== $this->downloadedBytes){
            echo "Downloadprogress: " . $downloadedBytes . " bytes" . PHP_EOL;
            $this->downloadedBytes = $downloadedBytes;
            ob_flush();
            flush();
        }
    }
}