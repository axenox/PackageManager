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
    
    private ?ResponseInterface $response = null;
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
    public function download(bool $useCLI = false) : \Generator
    {
        if (! is_writable($this->downloadPath)) {
            $token = new CliEnvAuthToken();
            throw new RuntimeException('Cannot save self-update package: download path "' . $this->downloadPath . '" is not writable for user "' . $token->getUsername() . '"!');
        }
        $this->setDebug(true);
        if ($useCLI === false) {
            $response = $this->sendHttpRequest('GET');
            $this->response = $response;
            $this->responseSize = $this->getFileSizeFromResponse($response);
            $this->setDebug(false);
            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                yield PHP_EOL . PHP_EOL . 'Found new self-update package.';
                yield $this->__toString();
                if ($this->responseSize < 100) {
                    throw new RuntimeException('Cannot save self-update package: invalid download size ' . ByteSizeDataType::formatWithScale($this->responseSize));
                }
                $content = $response->getBody();
                $filename = $this->getFileName();
                $filePath = $this->downloadPath . $filename;
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
            }
        } else {
            $cliGenerator = $this->downloadViaCLI();
            yield from $cliGenerator;
            $response = $cliGenerator->getReturn();
            $this->response = $response;
            $statusCode = $response->getStatusCode();
        }
        if ($statusCode === 200) {
            $filePath = $this->downloadPath . $this->getFileName();
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
        $header = $this->response->getHeaderLine('Content-Disposition');
        if (! $header) {
            throw new RuntimeException('Cannot find filename in download response: ' . json_encode($this->response->getheaders()));
        }
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
            $this->sendHttpRequest('POST', $log, $urlParams);
        } catch (\Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->logException($e);
            }
            $log .= PHP_EOL . 'ERROR uploading log to deployer: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine();
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

    /**
     * Downloads the update package via CLI curl command and not via PHP
     * 
     *
     * Behavior:
     *   - Sends GET to <initial_url> with "Authorization: Bearer <token>"
     *   - Follows redirects (-L), saves using remote name (-OJ) into /.dep (--output-dir)
     *   - Dumps response headers to a temp file (-D), writes status code and final URL via -w
     *   - Parses Content-Disposition to extract filename (supports filename*=)
     */
    protected function downloadViaCLI() : \Generator
    {
        yield 'Downloading via CLI' . "\n";
        $initialUrl = $this->url;
        $authHeader = 'Basic ' . base64_encode($this->username . ':' . $this->password);
        $destDir = $this->downloadPath;

        // Create temporary files for headers and output capture
        $headersPath = tempnam($destDir, "curl_headers_");
        if ($headersPath === false) {
            yield "Failed to create temporary headers file.\n";
        }

        // Build the curl command
        // -sS: silent but show errors
        // -L: follow redirects
        // -OJ: use remote name; honor Content-Disposition
        // --output-dir "/.dep": write into target directory
        // -H "Authorization: Basic TOKEN": Basic auth
        // -D headersPath: dump headers (all hops; we’ll parse the last block)
        // -w: print status code and effective URL to stdout (no body to stdout because -O used)
        $cmd = sprintf(
            'curl -sS -L -OJ --output-dir %s -H %s -D %s %s -w "%%{http_code}\n%%{url_effective}\n"',
            escapeshellarg($destDir),
            escapeshellarg("Authorization: {$authHeader}"),
            escapeshellarg($headersPath),
            escapeshellarg($initialUrl)
        );

        // Execute curl
        $outputLines = [];
        $curlReturnCode = 0;
        exec($cmd, $outputLines, $curlReturnCode);

        foreach ($outputLines as $line) {
            if ($this->debugStream !== null) {
                fwrite($this->debugStream, $line);
            }
        }

        // Parse the final status code and effective URL from curl -w output
        $statusCode = null;
        $effectiveUrl = null;
        if (count($outputLines) >= 1) {
            // last two lastHeaderLines: status code and effective URL
            $statusCode = trim($outputLines[count($outputLines) - 2] ?? "");
            $effectiveUrl = trim($outputLines[count($outputLines) - 1] ?? "");
        }

        if (!preg_match('/^\d{3}$/', (string)$statusCode)) {
            // If -w output got mixed, try fallback: use last numeric-looking line
            foreach (array_reverse($outputLines) as $line) {
                if (preg_match('/^\d{3}$/', trim($line))) {
                    $statusCode = trim($line);
                    break;
                }
            }
        }

        // Read headers file and extract the last response block (after redirects)
        $headersRaw = @file_get_contents($headersPath);
        @unlink($headersPath);

        if ($headersRaw === false) {
            yield "Cannot read headers file {$headersPath}\n";
            $headersRaw = "";
        }
/*
        // Split by header blocks; curl writes each response block terminated by \r\n\r\n
        $blocks = preg_split("/\r\n\r\n/", $headersRaw);
        $lastHeaders = "";
        if (is_array($blocks) && count($blocks) > 0) {
            // Select the last non-empty block
            for ($i = count($blocks) - 1; $i >= 0; $i--) {
                if (trim($blocks[$i]) !== "") {
                    $lastHeaders = $blocks[$i];
                    break;
                }
            }
        }
*/
        $lastHeaders = $headersRaw;
        $lastHeaderLines = [];
        foreach (StringDataType::splitLines($lastHeaders) as $line) {
            if (stripos($line, "HTTP/2 ") === 0) {
                if (! $statusCode) {
                    $statusCode = StringDataType::substringAfter($line, "HTTP/2 ", null);
                }
                continue;
            }
            if (trim($line) === "") {
                continue;
            }
            list($header, $value) = explode(':', $line, 2);
            $lastHeaderLines[mb_strtolower($header)] = trim($value);
        }
        
        if ($statusCode >= 400) {
            throw new RuntimeException('Failed to download via CLI: HTTP status code "' . $statusCode . '", CURL return code "' . $curlReturnCode . '"');
        }
        
        // Extract Content-Disposition (case-insensitive)
        if ($statusCode === 200) {
            $filename = null;
            $cdLine = $lastHeaderLines['content-disposition'] ?? null;

            if ($cdLine !== null) {
                // Try RFC 5987 filename* first (UTF-8''urlencoded)
                // Example: Content-Disposition: attachment; filename*=UTF-8''report%20Q4.pdf
                if (preg_match('/filename\*\s*=\s*[^\'"]*\'\'([^;]+)/i', $cdLine, $m)) {
                    $filename = urldecode(trim($m[1], " \t\""));
                }

                // Fallback to traditional filename=
                // Examples:
                //   filename="report Q4.pdf"
                //   filename=report_Q4.pdf
                if ($filename === null && preg_match('/filename\s*=\s*"([^"]+)"/i', $cdLine, $m)) {
                    $filename = $m[1];
                }
                if ($filename === null && preg_match('/filename\s*=\s*([^;\s]+)/i', $cdLine, $m)) {
                    $filename = trim($m[1], " \t\"");
                }
            }

            if (! $filename) {
                throw new RuntimeException("Cannot find downloaded file name in headers " . json_encode($lastHeaderLines) . '. CURL return code "' . $statusCode . '"');
            }

            // Final fallback: if no Content-Disposition filename, derive from the newest download
            if ($filename === null) {
                $fileGenerator = $this->findLastDownload(60);
                yield from $fileGenerator;
                $filename = $fileGenerator->getReturn();
            } else {
                yield 'Downloaded file "' . $filename . '"';
            }

            if (! $lastHeaderLines['content-disposition']) {
                $lastHeaderLines['content-disposition'] = 'attachment; filename="' . $filename . '"';
            }
        }

        $fakeResponse = new Response($statusCode, $lastHeaderLines ?? []);
        return $fakeResponse;
    }


    /**
     * Find the latest .phx file in the given folder and return its filename
     * (with extension) iff it was modified less than a minute ago; otherwise NULL.
     *
     * Note: PHP does not portably expose "creation time"; we use creation time (ctime).
     *
     * @param int $maxAgeSeconds
     * @param string $extension
     * @return ?string Filename (with extension) or NULL
     */
    protected function findLastDownload(int $maxAgeSeconds = 60, string $extension = 'phx') : \Generator
    {
        yield 'Looking for recently downloaded files...' . PHP_EOL;
        $dir = $this->downloadPath;
        // Basic directory checks
        if (!is_dir($dir)) {
            throw new \RuntimeException("Directory does not exist: {$dir}");
        }
        if (!is_readable($dir)) {
            throw new \RuntimeException("Directory not readable: {$dir}");
        }

        $latestFile = null;
        $latestMTime = null;

        // Use FilesystemIterator to avoid . and .. entries and to get file metadata efficiently
        $it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);

        foreach ($it as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            // Only consider .phx files (case-insensitive match on extension)
            $ext = strtolower($fileInfo->getExtension());
            if ($ext !== $extension) {
                continue;
            }

            // ctime may fail for some FS objects; guard and skip on failure
            $ctime = @filectime($fileInfo->getPathname());
            if ($ctime === false) {
                yield 'Cannot read the creation time of file ' . $dir . PHP_EOL;
                continue;
            }

            if ($latestMTime === null || $ctime > $latestMTime) {
                $latestMTime = $ctime;
                $latestFile = $fileInfo->getFilename(); // return only the name + extension
            }
        }

        if ($latestFile === null) {
            // No .phx files found
            yield 'No .phx files found in ' . $dir . PHP_EOL;
            return null;
        }

        // Check the "less than a minute ago" condition (60 seconds)
        $ageSeconds = time() - $latestMTime;
        if ($ageSeconds < $maxAgeSeconds) {
            yield 'Found recent .phx file: ' . $latestFile . PHP_EOL;
            return $latestFile;
        } else {
            yield 'Latest .phx file "' . $latestFile . '" is too old.' . PHP_EOL;
            return null;
        }
    }

}