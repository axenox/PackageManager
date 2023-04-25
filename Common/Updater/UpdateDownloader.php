<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;

class UpdateDownloader
{
    private $downloadedBytes = null;
    
    private $statusCode = null;
    
    private $headers = null;
    
    private $fileSize = null;
    
    private $timeStamp = null;
    
    private $url = null;
    
    private $username = null;
    
    private $password = null;
    
    private $downloadPath = null;
    
    /**
     *
     */
    public function __construct(string $url, string $username, string $password, string $downloadPath)
    {
        $this->timeStamp = time();
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->downloadPath = $downloadPath;
    }
    
    /**
     * 
     */
    public function download()
    {
        $client = new Client();
        /* @var $client \GuzzleHttp\Client */
        $response = $client->request('GET', $this->url, ['auth' => [$this->username, $this->password]],
            ['progress' => function($downloadTotal,$downloadedBytes)
            {
                $this->progress(((int) $downloadTotal),$downloadedBytes);
            }
            ]);
        
        $this->setStatusCode($response->getStatusCode());
        if ($response->getStatusCode() === 200) {
            $content = $response->getBody();
            $this->headers = $response->getHeaders();
            $this->fileSize = $this->getFileSizeFromResponse($response);
            file_put_contents($this->downloadPath . $this->getFileName(), $content);
        }
    }

    /**
     * 
     * @return string
     */
    public function getFormatedStatusMessage() : string
    {
        return $this->getStatusCode() == 200 ? "Success" : "Failure";
    }
    
    /**
     * 
     * @return string
     */
    public function getFileName() : string
    {
        $header = $this->headers['Content-Disposition'][0];
        return StringDataType::substringAfter($header, 'attachment; filename=');
    }

    /**
     *
     * @return int
     */
    public function getTimestamp() : int
    {
        return $this->timeStamp;
    }
    
    /**
     *
     * @return string
     */
    public function getFileSize() : int
    {
        return $this->fileSize;
    }
    
    /**
     *
     * @param int $statuscode
     */
    protected function setStatusCode(int $statuscode)
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
     * @param Response $response
     * @return int
     */
    protected function getFileSizeFromResponse(Response $response) : int
    {
        if ($response->hasHeader('content-length')) {
            $fileSize = (int) $response->hasHeader('content-length')[0];
        } else {
            $fileSize = $response->getBody()->getSize();
        }  
        return $fileSize;
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