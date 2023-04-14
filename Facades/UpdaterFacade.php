<?php
namespace axenox\PackageManager\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\DataTypes\StringDataType;
use GuzzleHttp\Psr7\Response;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use exface\Core\Facades\AbstractHttpFacade\IteratorStream;
use exface\Core\Formulas\DateTime;
use axenox\PackageManager\Common\Updater\UploadFile;
use axenox\PackageManager\Common\Updater\DownloadFile;
use axenox\PackageManager\Common\Updater\LogFiles;
use axenox\PackageManager\Common\Updater\PostLog;
use GuzzleHttp\Client;
use axenox\PackageManager\Actions\SelfUpdate;
use axenox\PackageManager\Common\SelfUpdateInstaller;

/**
 * HTTP facade to allow remote updates (deployment) on this server
 * 
 * @author Thomas Ressel
 *
 */
class UpdaterFacade extends AbstractHttpFacade
{
   
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponse()
     */
    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $pathInFacade = mb_strtolower(StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/'));
        
        switch (true) {
            
            case $pathInFacade === 'download':
                
                $downloader = new DownloadFile();
                $downloadPath = __DIR__ . '/../../../../Download/';
                //$url = 'http://sdrexf2.salt-solutions.de/buildsrv/data/deployer/test_updater/builds/1.0+20230328095613_UpdaterTest_bei_Thomas.phx';
                $url = $this->getConfig()->getOption('UPDATE_URL');
                $username = $this->getConfig()->getOption('USERNAME');
                $password = $this->getConfig()->getOption('PASSWORD');
                $return = $downloader->download($url, $username, $password, $downloadPath);
                $headers = ['Content-Type' => 'text/plain-stream'];
                return new Response(200, $headers, $return);
            
            case $pathInFacade === 'install':
                $filePath = __DIR__ . '/../../../../Download/0x11edaf48defa39fcaf48005056be9857.phx';
                $command = 'php -d memory_limit=2G';
                $installer = new SelfUpdateInstaller();
                $headers = ['Content-Type' => 'text/plain-stream'];
                return new Response(200, $headers, $installer->install($command, $filePath));
                
            case $pathInFacade === 'upload-file':
                $uploadFiles = new UploadFile($request, $pathInFacade);
                // Move uploaded files to uploadPath
                $uploadPath = __DIR__ . '/../../../../Upload/';
                $uploadFiles->moveUploadedFiles($uploadPath);
                // Create LogFile for uploaded files
                $logsPath = __DIR__ . '/../../../../.dep/log/';
                $uploadFiles->createLogFile($logsPath);
                // Get output of last uploaded file for Response
                $output = $uploadFiles->getCurlOutput();
                
                // Simulate installation with sleep-timer
                $generator = function ($bytes) use($output) {
                yield $output;
                    for ($i = 0; $i < $bytes; $i++) {
                        sleep(1);
                        yield '.'.$i.'.';
                        ob_flush();
                        flush();
                    }
                };
                
                $stream = new IteratorStream($generator(5));
                $headers = ['Content-Type' => 'text/plain-stream'];
                return new Response(200, $headers, $stream);
                
            case $pathInFacade === 'status':
                $deployedFiles = new LogFiles();
                $releasesPath = __DIR__ . '/../../../../.dep/releases';
                $output = "Last Deployment: " . $deployedFiles->getLatestDeployment($releasesPath)  . PHP_EOL. PHP_EOL;
                $logsPath = __DIR__ . '/../../../../.dep/log/';
                $output .= $deployedFiles->getLatestLog($logsPath);
                $headers = ['Content-Type' => 'text/plain-stream'];
                return new Response(200, $headers, $output);
                
            // Shows log-entries for all uploaded files
            case $pathInFacade === 'log':
                // Gets log-entries for all uploaded files as Json
                $deployedFiles = new LogFiles();
                $headers = ['Content-Type' => 'application/json'];
                $jsonPath = __DIR__ . '/../../../../.dep/log';
                return new Response(200, $headers, $deployedFiles->createJson($jsonPath));
                
            // Search for pathInFacade in log-directory
            default:
                $deployedFiles = new LogFiles();
                $logPath = __DIR__ . '/../../../../.dep/log/';
                if($deployedFiles->getLogContent($logPath, $pathInFacade) !== null){
                    $headers = ['Content-Type' => 'text/plain-stream'];
                    return new Response(200, $headers, $deployedFiles->getLogContent($logPath, $pathInFacade));
                }
        }
        return new Response(404);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getMiddleware()
     */
    protected function getMiddleware() : array
    {
        return array_merge(parent::getMiddleware(), [
            new AuthenticationMiddleware($this, [
                [
                    AuthenticationMiddleware::class, 'extractBasicHttpAuthToken'
                ]
            ])
        ]);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/updater';
    }
    
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
}