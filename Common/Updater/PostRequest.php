<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;

class PostRequest
{
    private $downloadedBytes = null;
    
    private $statusCode = null;
    
    private $headers = null;
    
    private $contentSize = null;
    
    public function sendRequest(string $url, $username, $password, $log, $status)
    {
        $client = new Client();
        $statusCode = $status === false ? 0 : 1;
        //$postRequest = $client->request('POST', $url, ['auth' => ['admin', 'admin']],["body" => $log]);
        $postRequest = $client->request('POST', $url, ['auth' => ['admin', 'admin']],["body" => $log, "status" => $statusCode]);
        return $postRequest;
    }
}