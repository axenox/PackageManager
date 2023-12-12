<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use Psr\Http\Message\ResponseInterface;

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
    
    protected function sendHttpRequest(string $method, string $body = null) : ResponseInterface
    {
        /* @var $client \GuzzleHttp\Client */
        $client = new Client();
        
        $options = [
            'auth' => [
                $this->username,
                $this->password
            ],
            'verify' => false/*,
            'progress' => function($dl_total_size, $dl_size_so_far, $ul_total_size, $ul_size_so_far) {
            $this->progress(((int) $dl_total_size),$dl_size_so_far);
            }*/
        ];
        
        if ($body !== null) {
            $options['body'] = $body;
        }
        
        $response = $client->request(
            $method,
            $this->url,
            $options
        );
        
        return $response;
    }
    
    /**
     * 
     */
    public function download() : ResponseInterface
    {
        $response = $this->sendHttpRequest('GET');
        $status = $response->getStatusCode();
        $this->setStatusCode($status);
        if ($status === 200) {
            $content = $response->getBody();
            $this->headers = $response->getHeaders();
            $this->fileSize = $this->getFileSizeFromResponse($response);
            file_put_contents($this->downloadPath . $this->getFileName(), $content);
        }
        return $response;
    }

    /**
     * 
     * @return string
     */
    public function getPathAbsolute() : string
    {
        return $this->downloadPath . $this->getFileName();
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
    public function getFileName() : ?string
    {
        if (empty($this->headers)) {
            return null;
        }
        $header = $this->headers['Content-Disposition'][0];
        return StringDataType::substringAfter($header, 'attachment; filename=');
    }

    /**
     * 
     * @return int|NULL
     */
    public function getFileSize() : ?int
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
     * @return string|NULL
     */
    public function getStatusCode() : ?string
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
            yield "Download progress: " . $downloadedBytes . " bytes" . PHP_EOL;
            $this->downloadedBytes = $downloadedBytes;
        }
    }
    
    public function uploadLog(string $log) : ResponseInterface
    {
        return $this->sendHttpRequest('POST', $log);
    }
    
    /**
     * 
     * @return string
     */
    public function __toString() : string
    {
        return <<<TEXT
Download: {$this->getFormatedStatusMessage()}
    Filename: {$this->getFileName()}
    Size: {$this->getFileSize()}
TEXT;
    }
}