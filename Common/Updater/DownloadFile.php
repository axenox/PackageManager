<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use axenox\PackageManager\Common\SelfUpdateInstaller;
use Psr\Http\Message\ResponseInterface;

class DownloadFile
{
    private $downloadedBytes = null;
    
    private $statusCode = null;
    
    private $headers = null;
    
    private $contentSize = null;
    
    private $download = null;
    
    private $timeStamp = null;
    
    /**
     * 
     * @param string $url
     * @param string $username
     * @param string $password
     * @param string $downloadPath
     * @return DownloadFile
     */
    public function download(string $url, string $username, string $password, string $downloadPath)
    {
        $client = new Client();
        /* @var $client \GuzzleHttp\Client */
        $this->timestamp = time();
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
        $this->download = $this;
    }

    protected function printLoadTimer() : ?string
    {
        $diffTimestamp = time();
        if ($diffTimestamp > ($this->timestamp + 1)){
            $loadingOutput = ".";
            $this->timestamp = $diffTimestamp;
        } else {
            $loadingOutput = "";
        }
        $this->emptyBuffer();
        return $loadingOutput;
    }
    
    public function getDownload()
    {
        return $this->download;
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
            yield "Downloadprogress: " . $downloadedBytes . " bytes" . PHP_EOL;
            $this->downloadedBytes = $downloadedBytes;
            $this->emptyBuffer();
        }
    }
    
    /**
     *
     */
    protected function emptyBuffer()
    {
        ob_flush();
        flush();
    }
}