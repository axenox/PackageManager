<?php
namespace axenox\PackageManager\Tests\PHPUnit\Unit;

use axenox\PackageManager\Common\LicenseBOM\Format\JsonBOM;
use axenox\PackageManager\Common\LicenseBOM\LicenseBOM;
use axenox\PackageManager\Common\LicenseBOM\LocalVendorBOM;
use axenox\PackageManager\Interfaces\LicenseBOMInterface;
use exface\Core\Exceptions\RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the enrichment pipeline shared by installation exports and build snapshots.
 */
class LicenseBOMEnrichmentTest extends TestCase
{
    /**
     * Remote terms must update the inventory itself and survive minimal serialization.
     *
     * @return void
     */
    public function testRemoteSourcesPopulateMinimalEvidence() : void
    {
        $link = 'https://raw.githubusercontent.com/example/library/main/LICENSE';
        $bom = new LicenseBOM(['packages' => [
            ['name' => 'bower-asset/outlayer', 'version' => 'v2.1.1', 'license' => ['MIT']],
            ['name' => 'example/library', 'licenses' => [['name' => 'MIT', 'link' => $link]]]
        ]]);
        $responses = new MockHandler([
            new Response(200, [], 'SPDX terms'),
            new Response(200, [], 'GitHub terms')
        ]);
        $history = [];
        $handler = HandlerStack::create($responses);
        $handler->push(Middleware::history($history));
        $bom = $this->enrichBOM($bom, sys_get_temp_dir(), new Client(['handler' => $handler]));

        $packages = array_values($bom->getPackages());
        self::assertSame('SPDX terms', $packages[0]->getLicenses()['MIT']->getText());
        self::assertSame('GitHub terms', $packages[1]->getLicenses()['MIT']->getText());
        $snapshot = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['name' => 'MIT', 'source' => 'spdx', 'text_available' => true]], $snapshot['packages'][0]['licenses']);
        self::assertSame([['name' => 'MIT', 'source' => 'github', 'text_available' => true]], $snapshot['packages'][1]['licenses']);
        self::assertSame('https://raw.githubusercontent.com/spdx/license-list-data/main/text/MIT.txt', (string) $history[0]['request']->getUri());
        self::assertSame($link, (string) $history[1]['request']->getUri());
        self::assertSame(0, $responses->count());
    }

    /**
     * Explicit relative files must win over generic package files and remote standard terms.
     *
     * @return void
     */
    public function testConfiguredFilePrecedesGenericAndRemoteTerms() : void
    {
        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('shared-license-pipeline-', true);
        $packagePath = $folder . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'library';
        $configuredPath = $folder . DIRECTORY_SEPARATOR . 'terms.txt';
        $genericPath = $packagePath . DIRECTORY_SEPARATOR . 'LICENSE';
        mkdir($packagePath, 0777, true);
        try {
            file_put_contents($configuredPath, 'Configured terms');
            file_put_contents($genericPath, 'Generic terms');
            $bom = new LicenseBOM(['packages' => [[
                'name' => 'example/library', 'licenses' => [[
                    'name' => 'MIT', 'file' => 'terms.txt',
                    'link' => 'https://raw.githubusercontent.com/example/library/main/LICENSE'
                ]]
            ]]]);
            $bom = $this->enrichBOM($bom, $folder, new Client(['handler' => new MockHandler()]));
            $license = $bom->getPackages()['composer:example/library:']->getLicenses()['MIT'];
            self::assertSame('Configured terms', $license->getText());
            self::assertSame('local', $license->getSource());
        } finally {
            foreach ([$configuredPath, $genericPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($packagePath);
            rmdir(dirname($packagePath));
            rmdir($folder);
        }
    }

    /**
     * Missing GitHub files must still allow standard-license resolution through SPDX.
     *
     * @return void
     */
    public function testMissingGithubFileFallsBackToSpdx() : void
    {
        $bom = new LicenseBOM(['packages' => [[
            'name' => 'example/library', 'licenses' => [[
                'name' => 'MIT', 'link' => 'https://raw.githubusercontent.com/example/library/main/LICENSE'
            ]]
        ]]]);
        $responses = new MockHandler([new Response(404), new Response(200, [], 'SPDX terms')]);
        $bom = $this->enrichBOM($bom, sys_get_temp_dir(), new Client(['handler' => $responses]));
        $license = $bom->getPackages()['composer:example/library:']->getLicenses()['MIT'];
        self::assertSame('SPDX terms', $license->getText());
        self::assertSame('spdx', $license->getSource());
        self::assertSame(0, $responses->count());
    }

    /**
     * Failed identifier lookups must not turn a declaration into resolved text evidence.
     *
     * @return void
     */
    public function testUnresolvableIdentifierRemainsUnavailable() : void
    {
        $bom = new LicenseBOM(['packages' => [['name' => 'example/library', 'license' => ['Unknown-Terms']]]]);
        $responses = new MockHandler([new Response(404), new Response(404)]);
        $bom = $this->enrichBOM($bom, sys_get_temp_dir(), new Client(['handler' => $responses]));
        $snapshot = json_decode((new JsonBOM($bom))->setFormat('minimal')->buildJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['name' => 'Unknown-Terms', 'text_available' => false]], $snapshot['packages'][0]['licenses']);
        self::assertSame(0, $responses->count());
    }

    /**
     * Unavailable remote services must produce explicit errors rather than incomplete success.
     *
     * @param string|null $link
     * @return void
     */
    #[DataProvider('remoteSources')]
    public function testRemoteServiceFailuresPropagate(?string $link) : void
    {
        $license = ['name' => 'MIT'];
        if ($link !== null) {
            $license['link'] = $link;
        }
        $bom = new LicenseBOM(['packages' => [[
            'name' => 'example/library', 'licenses' => [$license]
        ]]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');
        $this->enrichBOM($bom, sys_get_temp_dir(), new Client(['handler' => new MockHandler([new Response(503)])]));
    }

    /**
     * Covers both remote enrichers registered by the shared pipeline.
     *
     * @return array<string,array{string|null}>
     */
    public static function remoteSources() : array
    {
        return [
            'SPDX' => [null],
            'GitHub' => ['https://raw.githubusercontent.com/example/library/main/LICENSE']
        ];
    }

    /**
     * Enriches a lightweight source through the specialized top-level inventory.
     *
     * @param LicenseBOMInterface $source
     * @param string $vendorPath
     * @param ClientInterface $client
     * @return LocalVendorBOM
     */
    private function enrichBOM(LicenseBOMInterface $source, string $vendorPath, ClientInterface $client) : LocalVendorBOM
    {
        $bom = new LocalVendorBOM($vendorPath, $client);
        $bom->merge($source);
        $bom->enrich();
        return $bom;
    }
}
