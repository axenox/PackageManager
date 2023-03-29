<?php
namespace axenox\PackageManager\Actions;

use GuzzleHttp\Client;
use axenox\PackageManager\Common\SelfUpdateInstaller;

class SelfUpdate
{
    private $downloadedBytes = null;
    
    public function download($url, $downloadPath) : string
    {
        $client = new Client(['base_uri' => $url]);
        /* @var $client \GuzzleHttp\Client */
        $response = $client->request('GET', $client->getConfig()['base_uri'], 
            ['progress' => function($downloadTotal,$downloadedBytes) 
            {
               $this->progress($downloadTotal,$downloadedBytes);
            }
            ]);
        if ($response->getStatusCode() === 200) {
            $content = $response->getBody();
            $fileName = end(explode("/", $url));
            file_put_contents($downloadPath . $fileName, $content);
            return "Downloaded " . $this->getContentSize($response) . " bytes of data.";
        } else {
            return "Download failed. Status-Code: " . $response->getStatusCode();
        }
    }

    protected function getContentSize($response) : string
    {
        if ($response->hasHeader('content-length')){
            $contentLength = $response->getHeader('content-length')[0];
            return $contentLength;
        } return "Unknown";
    }
    
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
