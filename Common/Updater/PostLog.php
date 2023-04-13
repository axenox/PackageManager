<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;
use axenox\PackageManager\Common\SelfUpdateInstaller;

class PostLog
{
    private $downloadedBytes = null;
    
    private $statusCode = null;
    
    private $headers = null;
    
    private $contentSize = null;
    
    public function postLog(string $url, $username, $password, $log, $status)
    {
        $client = new Client();
        $postRequest = $client->request('POST', $url, ['auth' => [$username, $password]],["body" => $log, "status" => $status]);
        return $postRequest;
    }
}

