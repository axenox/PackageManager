<?php
namespace axenox\PackageManager\Common\Updater;

use GuzzleHttp\Client;

class InstallationResponse
{
    /**
     * 
     * @param string $url
     * @param string $username
     * @param string $password
     * @param string $log
     * @param string $installationSuccess
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function sendRequest(string $url, string $username, string $password, string $log, string $installationSuccess)
    {
        $client = new Client();
        return $client->request('POST', $url, ['auth' => [$username, $password]],["body" => $log, "status" => $installationSuccess]);
    }
}