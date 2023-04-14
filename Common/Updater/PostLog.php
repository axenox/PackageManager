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
        $statusCode = $status === false ? 0 : 1;
        $postRequest = $client->request('POST', $url, ['auth' => ['admin', 'admin']],["body" => $log]);
        //$postRequest = $client->request('POST', $url, ['auth' => [$username, $password]],["body" => $log, "status" => $status]);
        return $postRequest;
    }
}

