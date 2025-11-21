<?php
namespace axenox\PackageManager\Common\Updater;

use exface\Core\CommonLogic\Security\AuthenticationToken\CliEnvAuthToken;
use exface\Core\DataTypes\ByteSizeDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

class UpdateDownloader
{    
    private $timeStamp = null;
    private $url = null;
    private $username = null;
    private $password = null;
    private $downloadPath = null;
    
    private $response = null;
    private $responseSize = null;
    private $downloadedBytes = null;
    private $debugStream = null;
    
    private ?LoggerInterface $logger = null;
    
    /**
     *
     */
    public function __construct(string $url, string $username, string $password, string $downloadPath, LoggerInterface $logger = null)
    {
        $this->timeStamp = time();
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->downloadPath = $downloadPath;
        $this->logger = $logger;
    }
    
    protected function sendHttpRequest(string $method, string $body = null, array $urlParams = []) : ResponseInterface
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
        
        // Debug CURL
        if ($this->debugStream !== null) {
            $options['debug'] = $this->debugStream;
        }
        
        if ($body !== null) {
            $options['body'] = $body;
        }
        
        $query = ! empty($urlParams) ? '?' . http_build_query($urlParams) : '';
        $response = $client->request(
            $method,
            $this->url . $query,
            $options
        );
        
        return $response;
    }
    
    /**
     * 
     */
    public function download() : \Generator
    {
        $this->setDebug(true);
        $response = $this->sendHttpRequest('GET');
        $this->response = $response;
        $this->responseSize = $this->getFileSizeFromResponse($response);
        $this->setDebug(false);
        if ($response->getStatusCode() === 200) {
            yield PHP_EOL . PHP_EOL . 'Found new self-update package.';
            yield $this->__toString();
            if ($this->responseSize < 100) {
                throw new RuntimeException('Cannot save self-update package: invalid download size ' . ByteSizeDataType::formatWithScale($this->responseSize));
            }
            $content = $response->getBody();
            if (! is_writable($this->downloadPath)) {
                $token = new CliEnvAuthToken();
                throw new RuntimeException('Cannot save self-update package: download path "' . $this->downloadPath . '" is not writable for user "' . $token->getUsername() . '"!');
            }
            $filePath = $this->downloadPath . $this->getFileName();
            $writtenBytes = file_put_contents($filePath, $content);
            yield 'Saved ' . $writtenBytes . ' bytes to ' . $filePath;
            if (! $writtenBytes) {
                $token = new CliEnvAuthToken();
                if ($writtenBytes === false) {
                    throw new RuntimeException('Cannot save self-update package: cannot write file using user "' . $token->getUsername() . '"');
                }
                if ($writtenBytes < 100) {
                    throw new RuntimeException('Cannot save self-update package: detected invalid file size "' . $writtenBytes . '". User "' . $token->getUsername() . '".');
                }
            }
            $fileBytes = filesize($filePath);
            yield 'Resulting file size: ' . $fileBytes . ' bytes';
            if ($fileBytes === false || $fileBytes < 100) {
                throw new RuntimeException('Cannot save self-update package: reading downloaded file failed - read ' . ByteSizeDataType::formatWithScale($fileBytes) . '. User "' . $token->getUsername() . '".');
            }
        }
        return $response;
    }
    
    public function setDebug(bool $debug) : UpdateDownloader
    {
        if ($debug === true) {
            $this->debugStream = Utils::tryFopen('php://temp', 'w+');
        } else {
            $this->debugStream = null;
        }
        return $this;
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
        $code = $this->getStatusCode();
        switch (true) {
            case $code === 200: $msg = 'Success'; break;
            case $code === 304: $msg = 'No update available'; break;
            case $code >= 400 && $code <= 599: $msg = 'Failure'; break;
        }
        return $code . ' ' . $msg;
    }
    
    /**
     * 
     * @return string
     */
    public function getFileName() : ?string
    {
        if (empty($this->response)) {
            return null;
        }
        $header = $this->response->getHeaders()['Content-Disposition'][0];
        return StringDataType::substringAfter($header, 'attachment; filename=');
    }

    /**
     * 
     * @return int|NULL
     */
    public function getFileSize() : ?int
    {
        return $this->responseSize;
    }
    
    /**
     *
     * @return int|NULL
     */
    public function getStatusCode() : ?int
    {
        if ($this->response === null) {
            return null;
        }
        return $this->response->getStatusCode();
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

    /**
     * @param string $log
     * @return ResponseInterface|null
     * @throws \Throwable
     */
    public function uploadLog(string $log, ?int $status = null, bool $final = false) : string
    {
        try {
            $urlParams = [];
            if ($status !== null) {
                $urlParams['status'] = $status;
            }
            if ($final === true) {
                $urlParams['final'] = 'true';
            }
            return $this->sendHttpRequest('POST', $log, $urlParams);
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->logException($e);
            }
            return $log . PHP_EOL . 'ERROR uploading log to deployer: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
        }
        return $log;
    }
    
    /**
     * 
     * @return string
     */
    public function __toString() : string
    {
        $size = ByteSizeDataType::formatWithScale($this->responseSize);
        $summary = <<<TEXT
Downloaded {$size}:
    Filename: {$this->getFileName()}
    Response code: {$this->getFormatedStatusMessage()}
    
TEXT;
        
        if ($this->debugStream !== null) {
            // Rewind and read the debug output
            rewind($this->debugStream);
            $debugOutput = stream_get_contents($this->debugStream);
            $summary .= <<<TEXT

CURL Debug ----------------------------------------------
{$debugOutput}

TEXT;

        }
        
        return $summary;
    }
}