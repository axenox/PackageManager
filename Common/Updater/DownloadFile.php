<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class DownloadFile
{
    private $downloadedBytes = null;
    
    private $statusCode = null;
    
    private $headers = null;
    
    private $contentSize = null;
    
    private $timeStamp = null;
    
    /**
     *
     */
    public function __construct()
    {
        $this->timeStamp = date("Y-m-d") . "_" . date("His");
    }
    
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
        $response = $client->request('GET', $url, ['auth' => [$username, $password]],
            ['progress' => function($downloadTotal,$downloadedBytes)
            {
                $this->progress(((int) $downloadTotal),$downloadedBytes);
            }
            ]);
        
        if ($response->getStatusCode() === 200) {
            $this->setStatus($response->getStatusCode());
            $content = $response->getBody();
            $this->headers = $response->getHeaders();
            $this->contentSize = $this->getContentSizeFromResponse($response);
            $fileName = $this->getFileName();
            file_put_contents($downloadPath . $fileName, $content);
        } else {
            $this->setStatus($response->getStatusCode());
        }
    }
    
    /**
     *
     * @return string
     */
    public function getFileName() : string
    {
        $header = $this->headers['Content-Disposition'][0];
        return end(explode("filename=", $header));
    }
    
    /**
     *
     * @return array
     */
    public function getHeaders() : array
    {
        return $this->headers;
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
     * @return string
     */
    public function getContentSize() : string
    {
        return $this->contentSize;
    }
    
    /**
     *
     * @param int $statuscode
     */
    protected function setStatus(int $statuscode)
    {
        if($statuscode === 200) {
            $this->status = "Success";
        } else {
            $this->status = "Failure";
        }
    }
    
    /**
     *
     * @return string
     */
    public function getStatus() : string
    {
        return $this->status;
    }
    
    /**
     *
     * @param Response $response
     * @return string
     */
    protected function getContentSizeFromResponse(Response $response) : string
    {
        if ($response->hasHeader('content-length')) {
            $contentLength = $response->getHeader('content-length')[0];
            return $contentLength;
        }  else {
            return "Unknown";
        }
    }
    
    /**
     *
     * @param int $downloadTotal
     * @param int $downloadedBytes
     */
    protected function progress(int $downloadTotal, int $downloadedBytes)
    {
        if ($downloadedBytes !== $this->downloadedBytes) {
            yield "Downloadprogress: " . $downloadedBytes . " bytes" . PHP_EOL;
            $this->downloadedBytes = $downloadedBytes;
        }
    }
}