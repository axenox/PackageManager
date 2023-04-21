<?php
namespace axenox\PackageManager\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\DataTypes\StringDataType;
use GuzzleHttp\Psr7\Response;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;
use axenox\PackageManager\Common\Updater\UploadedRelease;
use axenox\PackageManager\Common\Updater\ReleaseLog;
use axenox\PackageManager\Common\Updater\SelfUpdateInstaller;
use axenox\PackageManager\Common\Updater\ReleaseLogEntry;

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
            
            case $pathInFacade === 'upload-file':
                
                // Move uploaded files to uploadPath
                $uploader = new UploadedRelease($request);
                $uploadPath = __DIR__ . '/../../../../Upload/';
                $uploader->moveUploadedFiles($uploadPath);
                $logArray = $uploader->fillLogFileFormat();
                
                // install
                $installationFilePath = $uploadPath . $uploader->getInstallationFileName();
                $selfUpdateInstaller = new SelfUpdateInstaller($installationFilePath, $this->getWorkbench()->filemanager()->getPathToCacheFolder());
                $logArray = $selfUpdateInstaller->fillLogFileFormat($logArray);
                
                // logfile
                $log = new ReleaseLog($this->getWorkbench());
                $releaseLogEntry = new ReleaseLogEntry($log);
                $releaseLogEntry->addEntry($logArray);
                
                // update release file
                $installationSuccess = $selfUpdateInstaller->getInstallationSuccess();
                if($installationSuccess) {
                    $releaseLogEntry->addNewDeployment($uploader->getTimestamp(), $uploader->getInstallationFileName());
                }
                
                $headers = ['Content-Type' => 'text/plain-stream'];
                return new Response(200, $headers, $releaseLogEntry->getEntry());
                
                /*
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
                $stream = new IteratorStream($installer->install());
                */
                
            case $pathInFacade === 'status':
                $releaseLog = new ReleaseLog($this->getWorkbench());
                $output = "Last Deployment: " . $releaseLog->getLatestDeployment() . PHP_EOL. PHP_EOL;
                $output .= $releaseLog->getLatestLog();
                $headers = ['Content-Type' => 'text/plain-stream'];
                return new Response(200, $headers, $output);
                
            // Shows log-entries for all uploaded files
            case $pathInFacade === 'log':
                // Gets log-entries for all uploaded files as Json
                $releaseLog = new ReleaseLog($this->getWorkbench());
                $headers = ['Content-Type' => 'application/json'];
                return new Response(200, $headers, json_encode($releaseLog->getLogEntries(), JSON_PRETTY_PRINT));
                
            // Search for pathInFacade in log-directory
            default:
                $releaseLog = new ReleaseLog($this->getWorkbench());
                if($releaseLog->getLogContent($pathInFacade) !== null) {
                    $headers = ['Content-Type' => 'text/plain-stream'];
                    return new Response(200, $headers, $releaseLog->getLogContent($pathInFacade));
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