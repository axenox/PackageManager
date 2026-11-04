<?php
namespace axenox\PackageManager\Common\LicenseBOM\Enricher;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use exface\Core\Exceptions\RuntimeException;

/**
 * Downloads missing terms independently from each license's raw GitHub link.
 * 
 * @author Thomas Ressel
 *
 */
class FindLicenseGithubEnricher implements BOMPackageEnricherInterface
{
    private $client;
    
    /**
     * Preserves the shared enricher construction contract used by BOM generation.
     *
     * @param string $vendorPath
     * @param ClientInterface|null $client HTTP transport shared with other remote license sources.
     */
    public function __construct(string $vendorPath, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }
    
    /**
     * Updates each downloaded license object without changing the package's selected license.
     *
     * {@inheritDoc}
     *
     * @see BOMPackageEnricherInterface::enrich()
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        foreach ($package->getLicenses() as $license) {
            $link = $license->getLink();
            if ($link !== null && str_contains($link, 'raw.githubusercontent.com') && $license->getText() === null) {
                $response = $this->client->request('GET', $link, [
                    'http_errors' => false, 'timeout' => 30, 'allow_redirects' => false
                ]);
                if ($response->getStatusCode() === 404) {
                    continue;
                }
                if ($response->getStatusCode() !== 200) {
                    throw new RuntimeException('Cannot download license ' . $license->getName() . ': HTTP ' . $response->getStatusCode());
                }
                $content = (string) $response->getBody();
                if (trim($content) !== '') {
                    $license->setText($content, 'github');
                }
            }
        }
        return $package;
    }
}