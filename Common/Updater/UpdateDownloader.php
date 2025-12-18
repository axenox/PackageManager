<?php
namespace axenox\PackageManager\Common\Updater;

use exface\Core\CommonLogic\Security\AuthenticationToken\CliEnvAuthToken;
use exface\Core\DataTypes\ByteSizeDataType;
use exface\Core\Exceptions\Facades\HttpBadResponseError;
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
     * Download a self-update package if available
     * 
     * If the regular download does not work (e.g. "hangs" forever), you can try to use a CLI command instead. This
     * requires PHP to be able to exec() a cURL command, but avoids PHP specific limitations on some servers.
     * 
     * @param bool $useCLI
     * @return \Generator
     */
    public function download(bool $useCLI = false) : \Generator
    {
        if (! is_writable($this->downloadPath)) {
            $token = new CliEnvAuthToken();
            throw new RuntimeException('Cannot save self-update package: download path "' . $this->downloadPath . '" is not writable for user "' . $token->getUsername() . '"!');
        }
        $this->setDebug(true);
        if ($useCLI === false) {
            // Regular download via Guzzle
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
            // Download via CLI cURL command
            $cliGenerator = $this->downloadViaCLI();
            yield from $cliGenerator;
            $response = $cliGenerator->getReturn();
            $this->response = $response;
            $statusCode = $response->getStatusCode();
        }
        
        // If we have downloaded an update file - see if it has a reasonable size
        if ($statusCode === 200) {
            $filePath = $this->downloadPath . $this->getFileName();
            $fileBytes = filesize($filePath);
            if ($this->responseSize === null) {
                $this->responseSize = $fileBytes;
            }
            yield 'Resulting file size: ' . $fileBytes . ' bytes';
            if ($fileBytes === false || $fileBytes < 100) {
                throw new RuntimeException('Cannot save self-update package: reading downloaded file failed - read ' . ByteSizeDataType::formatWithScale($fileBytes) . '. User "' . $token->getUsername() . '".');
            }
        }
        return $response;
    }

    /**
     * TRUE will start a new debug stream and FALSE will destroy it.
     * 
     * @param bool $debug
     * @return $this
     */
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
            $fileSize = (int) $response->getHeader('content-length')[0];
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
            'curl -sS -L -OJ --output-dir %s -H %s -D %s %s -w "%%{http_code}\n%%{url_effective}\n" 2>&1',
            escapeshellarg($destDir),
            escapeshellarg("Authorization: {$authHeader}"),
            escapeshellarg($headersPath),
            escapeshellarg($initialUrl)
        );

        // Execute curl
        $outputLines = [];
        $execReturnCode = 0;
        exec($cmd, $outputLines, $execReturnCode);
        $curlReturnCode = $execReturnCode >> 8;
        yield 'cURL returned ' . $this->getCurlMessage($curlReturnCode);

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
        if (! $lastHeaders) {
            $lastHeaders = $headersRaw;
        }
        
        // Parse headers into a header => value array
        $lastHeaderLines = [];
        foreach (StringDataType::splitLines($lastHeaders) as $line) {
            // The first line looks like this: `HTTP/2 200` - treat it differently: use this response code if it could
            // not be determined above.
            if (stripos($line, "HTTP/ ") === 0) {
                if (! $statusCode) {
                    $statusCode = StringDataType::substringAfter($line, " ", null);
                }
                continue;
            }
            // Skip empty lines
            if (trim($line) === "") {
                continue;
            }
            list($header, $value) = explode(':', $line, 2);
            $lastHeaderLines[mb_strtolower($header)] = trim($value);
        }
        
        // Throw exception if response is an error
        if ($statusCode >= 400) {
            $fakeResponse = new Response($statusCode, $lastHeaderLines);
            throw new HttpBadResponseError($fakeResponse, 'Failed to download via CLI: HTTP status code "' . $statusCode . '", cURL return code "' . $this->getCurlMessage($curlReturnCode) . '"');
        }
        
        // If we have a 200, look for the file name
        if ($statusCode === 200) {
            $filename = null;
            // Extract Content-Disposition (case-insensitive)
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
                yield 'Cannot find downloaded file name in headers (cURL return code "' . $statusCode . '"). Headers: ' . json_encode($lastHeaderLines, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            }

            // If no Content-Disposition filename, derive from the newest download
            if ($filename === null) {
                $fileGenerator = $this->findLastDownload(60);
                yield from $fileGenerator;
                $filename = $fileGenerator->getReturn();
            } 
            
            if (! $filename) {
                $fakeResponse = new Response($statusCode, $lastHeaderLines);
                throw new HttpBadResponseError($fakeResponse, 'Cannot find filename!');
            } else {
                yield 'Downloaded file "' . $filename . '"';
            }

            if (! $lastHeaderLines['content-disposition']) {
                $lastHeaderLines['content-disposition'] = 'attachment; filename="' . $filename . '"';
            }
        }

        // Create a fake HTTP response message from the collected data and return it
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
        yield 'Looking for recently downloaded files...';
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
                yield 'Cannot read the creation time of file ' . $dir;
                continue;
            }

            if ($latestMTime === null || $ctime > $latestMTime) {
                $latestMTime = $ctime;
                $latestFile = $fileInfo->getFilename(); // return only the name + extension
            }
        }

        if ($latestFile === null) {
            // No .phx files found
            yield 'No .phx files found in ' . $dir;
            return null;
        }

        // Check the "less than a minute ago" condition (60 seconds)
        $ageSeconds = time() - $latestMTime;
        if ($ageSeconds < $maxAgeSeconds) {
            yield 'Found recent .phx file: ' . $latestFile;
            return $latestFile;
        } else {
            yield 'Latest .phx file "' . $latestFile . '" is too old.';
            return null;
        }
    }
    
    protected function getCurlMessage(int $code) : string
    {
        $curl_error_codes = array (
            0 => 'CURLE_OK',
            1 => 'CURLE_UNSUPPORTED_PROTOCOL',
            2 => 'CURLE_FAILED_INIT',
            3 => 'CURLE_URL_MALFORMAT',
            4 => 'CURLE_NOT_BUILT_IN',
            5 => 'CURLE_COULDNT_RESOLVE_PROXY',
            6 => 'CURLE_COULDNT_RESOLVE_HOST',
            7 => 'CURLE_COULDNT_CONNECT',
            8 => 'CURLE_FTP_WEIRD_SERVER_REPLY',
            9 => 'CURLE_REMOTE_ACCESS_DENIED',
            10 => 'CURLE_FTP_ACCEPT_FAILED',
            11 => 'CURLE_FTP_WEIRD_PASS_REPLY',
            12 => 'CURLE_FTP_ACCEPT_TIMEOUT',
            13 => 'CURLE_FTP_WEIRD_PASV_REPLY',
            14 => 'CURLE_FTP_WEIRD_227_FORMAT',
            15 => 'CURLE_FTP_CANT_GET_HOST',
            17 => 'CURLE_FTP_COULDNT_SET_TYPE',
            18 => 'CURLE_PARTIAL_FILE',
            19 => 'CURLE_FTP_COULDNT_RETR_FILE',
            21 => 'CURLE_QUOTE_ERROR',
            22 => 'CURLE_HTTP_RETURNED_ERROR',
            23 => 'CURLE_WRITE_ERROR',
            25 => 'CURLE_UPLOAD_FAILED',
            26 => 'CURLE_READ_ERROR',
            27 => 'CURLE_OUT_OF_MEMORY',
            28 => 'CURLE_OPERATION_TIMEDOUT',
            30 => 'CURLE_FTP_PORT_FAILED',
            31 => 'CURLE_FTP_COULDNT_USE_REST',
            33 => 'CURLE_RANGE_ERROR',
            34 => 'CURLE_HTTP_POST_ERROR',
            35 => 'CURLE_SSL_CONNECT_ERROR',
            36 => 'CURLE_BAD_DOWNLOAD_RESUME',
            37 => 'CURLE_FILE_COULDNT_READ_FILE',
            38 => 'CURLE_LDAP_CANNOT_BIND',
            39 => 'CURLE_LDAP_SEARCH_FAILED',
            41 => 'CURLE_FUNCTION_NOT_FOUND',
            42 => 'CURLE_ABORTED_BY_CALLBACK',
            43 => 'CURLE_BAD_FUNCTION_ARGUMENT',
            45 => 'CURLE_INTERFACE_FAILED',
            47 => 'CURLE_TOO_MANY_REDIRECTS',
            48 => 'CURLE_UNKNOWN_OPTION',
            49 => 'CURLE_TELNET_OPTION_SYNTAX',
            51 => 'CURLE_PEER_FAILED_VERIFICATION',
            52 => 'CURLE_GOT_NOTHING',
            53 => 'CURLE_SSL_ENGINE_NOTFOUND',
            54 => 'CURLE_SSL_ENGINE_SETFAILED',
            55 => 'CURLE_SEND_ERROR',
            56 => 'CURLE_RECV_ERROR',
            58 => 'CURLE_SSL_CERTPROBLEM',
            59 => 'CURLE_SSL_CIPHER',
            60 => 'CURLE_SSL_CACERT',
            61 => 'CURLE_BAD_CONTENT_ENCODING',
            62 => 'CURLE_LDAP_INVALID_URL',
            63 => 'CURLE_FILESIZE_EXCEEDED',
            64 => 'CURLE_USE_SSL_FAILED',
            65 => 'CURLE_SEND_FAIL_REWIND',
            66 => 'CURLE_SSL_ENGINE_INITFAILED',
            67 => 'CURLE_LOGIN_DENIED',
            68 => 'CURLE_TFTP_NOTFOUND',
            69 => 'CURLE_TFTP_PERM',
            70 => 'CURLE_REMOTE_DISK_FULL',
            71 => 'CURLE_TFTP_ILLEGAL',
            72 => 'CURLE_TFTP_UNKNOWNID',
            73 => 'CURLE_REMOTE_FILE_EXISTS',
            74 => 'CURLE_TFTP_NOSUCHUSER',
            75 => 'CURLE_CONV_FAILED',
            76 => 'CURLE_CONV_REQD',
            77 => 'CURLE_SSL_CACERT_BADFILE',
            78 => 'CURLE_REMOTE_FILE_NOT_FOUND',
            79 => 'CURLE_SSH',
            80 => 'CURLE_SSL_SHUTDOWN_FAILED',
            81 => 'CURLE_AGAIN',
            82 => 'CURLE_SSL_CRL_BADFILE',
            83 => 'CURLE_SSL_ISSUER_ERROR',
            84 => 'CURLE_FTP_PRET_FAILED',
            85 => 'CURLE_RTSP_CSEQ_ERROR',
            86 => 'CURLE_RTSP_SESSION_ERROR',
            87 => 'CURLE_FTP_BAD_FILE_LIST',
            88 => 'CURLE_CHUNK_FAILED',
            89 => 'CURLE_NO_CONNECTION_AVAILABLE'
        );
        $const = $curl_error_codes[$code];
        if ($const === null) {
            $msg = 'Unknown error';
        } else {
            $msg = substr($const, 6);
        }
        return '[' . $code . '] ' . $msg;
    }

}