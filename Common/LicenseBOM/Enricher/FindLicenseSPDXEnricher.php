<?php
namespace axenox\PackageManager\Common\LicenseBOM\Enricher;

use axenox\PackageManager\Common\LicenseBOM\BOMPackage;
use axenox\PackageManager\Interfaces\BOMPackageEnricherInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use exface\Core\Exceptions\RuntimeException;

/**
 * Resolves SPDX terms independently for each license object in a package.
 * 
 * @author Thomas Ressel
 *
 */
class FindLicenseSPDXEnricher implements BOMPackageEnricherInterface
{
    private $client;
    
    /**
     * Uses a replaceable HTTP boundary so SPDX resolution can be verified without live services.
     *
     * @param string $vendorPath
     * @param ClientInterface|null $client
     */
    public function __construct(string $vendorPath, ?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }
    
    /**
    * Resolves missing standard terms on each license object, including unselected alternatives.
     *
    * {@inheritDoc}
    *
    * @see BOMPackageEnricherInterface::enrich()
     */
    public function enrich(BOMPackage $package) : BOMPackage
    {
        foreach ($package->getLicenses() as $license) {
            $licenseName = $license->getName();
            if (trim($license->getText() ?? '') !== ''
                || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9.+-]*$/D', $licenseName)
                || in_array(strtolower($licenseName), ['proprietary', 'other', 'none', 'noassertion'], true)) {
                continue;
            }
            $identifier = preg_replace('/^(A?GPL|LGPL|GFDL)-(\d+\.\d+)\+$/', '$1-$2-or-later', $licenseName);
            foreach ([$identifier, 'deprecated_' . $identifier] as $file) {
                $response = $this->client->request('GET',
                    'https://raw.githubusercontent.com/spdx/license-list-data/main/text/' . rawurlencode($file) . '.txt',
                    ['http_errors' => false, 'timeout' => 30, 'allow_redirects' => false]
                );
                if ($response->getStatusCode() === 404) {
                    continue;
                }
                if ($response->getStatusCode() !== 200) {
                    throw new RuntimeException('Cannot resolve SPDX license ' . $licenseName . ': HTTP ' . $response->getStatusCode());
                }
                $text = (string) $response->getBody();
                if (trim($text) !== '') {
                    $license->setText($text, 'spdx');
                }
                break;
            }
        }
        return $package;
    }
}