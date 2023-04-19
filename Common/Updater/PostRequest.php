<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;

class PostRequest
{

    /**
     * 
     * @param string $url
     * @param string $username
     * @param string $password
     * @param string $log
     * @param string $status
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendRequest(string $url, string $username, string $password, string $log, string $status)
    {
        $client = new Client();
        $postRequest = $client->request('POST', $url, ['auth' => [$username, $password]],["body" => $log, "status" => $status]);
        return $postRequest;
    }
}