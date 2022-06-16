<?php
namespace axenox\PackageManager\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use exface\Core\DataTypes\StringDataType;
use GuzzleHttp\Psr7\Response;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\AppFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Factories\ActionFactory;
use axenox\PackageManager\StaticInstaller;
use exface\Core\CommonLogic\ArchiveManager;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Facades\AbstractHttpFacade\Middleware\RequestContextReader;
use exface\Core\Facades\AbstractHttpFacade\Middleware\AuthenticationMiddleware;

/**
 * HTTP facade allowing to install apps hosted on this workbench somewhere else via composer (i.e. a private packagist).
 * 
 * @author ralf.mulansky
 *
 */
class PackagistFacade extends AbstractHttpFacade
{
    const BRANCH_NAME = 'dev-puplished';
    
    private $appVersion = null;
    
    private $pathToPublishedFolder = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponse()
     */
    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $topics = explode('/',substr(StringDataType::substringAfter($path, $this->getUrlRouteDefault()), 1));
        if ($topics[0] === 'packages') {
            return $this->buildResponsePackagesJson();
        } elseif ($topics[0]) {
            return $this->buildResponsePackage($topics);
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
     * Returns the response including the packages.json
     * 
     * @return ResponseInterface
     */
    protected function buildResponsePackagesJson() : ResponseInterface
    {
        $workbench = $this->getWorkbench();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.APP');
        $ds->getColumns()->addMultiple(['FOLDER', 'PACKAGE', 'PACKAGE__version', 'ALIAS', 'PUPLISHED']);
        $ds->getFilters()->addConditionFromString('PUPLISHED', true, ComparatorDataType::EQUALS);
        $ds->dataRead();
        $json = [
            'packages' => []
        ];
        foreach($ds->getRows() as $row) {
            if ($row['PACKAGE__version']) {
                continue;
            }
            $alias = $row['ALIAS'];
            $app = AppFactory::createFromAlias($alias, $workbench);
            $packageManager = $this->getWorkbench()->getApp("axenox.PackageManager");
            $composerJson = $packageManager->getComposerJson($app);
            $composerJson['version'] = self::BRANCH_NAME;
            $composerJson['dist'] = [
                'type' => 'zip',
                'url' => $this->buildUrlToPackage($app),
                'reference' => $this->getAppVersion()
            ];
            $json['packages'][$composerJson['name']] = [];            
            $json['packages'][$composerJson['name']][self::BRANCH_NAME] = $composerJson;
            
        }        
        $headers = [];
        $headers['Content-type'] = 'application/json;charset=utf-8';
        $body = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new Response(200, $headers, $body);
    }
    
    protected function buildResponsePackage(array $topics) : ResponseInterface
    {
        $workbench = $this->getWorkbench();
        $filemanager = $workbench->filemanager();
        $packageAlias = '';
        foreach ($topics as $topic) {
            $packageAlias .= $topic . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER;
        }
        $packageAlias = substr($packageAlias, 0, -1);
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.APP');
        $ds->getColumns()->addMultiple(['ALIAS', 'PUPLISHED']);
        $ds->getFilters()->addConditionFromString('PUPLISHED', true, ComparatorDataType::EQUALS);
        $ds->getFilters()->addConditionFromString('ALIAS', $packageAlias, ComparatorDataType::EQUALS);
        $ds->dataRead();
        if ($ds->isEmpty()) {
            $packageAlias = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $packageAlias);
            return new Response(404, [], "The package '{$packageAlias}' does not exist or is not puplished!");
        }
        $app = AppFactory::createFromAlias($packageAlias, $workbench);        
        $backupAction = ActionFactory::createFromString($workbench, StaticInstaller::PACKAGE_MANAGER_BACKUP_ACTION_ALIAS);
        $path = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, DIRECTORY_SEPARATOR, $packageAlias) . DIRECTORY_SEPARATOR . $this->getAppVersion();
        $path = $this->getPathToPuplishedFolder() . DIRECTORY_SEPARATOR . $path;
        $backupAction->setBackupPath($path);
        $generator = $backupAction->backup($app->getSelector());
        foreach($generator as $gen) {
            continue;
        }
        if (!is_dir($path)) {
            return new Response(404, [], "The package '{$packageAlias}' could not be exported!");
        }
        $zip = new ArchiveManager($workbench, $path . '.zip');
        $zip->addFolder($path);
        $zip->close();
        $headers = [
            "Content-type" => "application/zip",
            "Content-Transfer-Encoding"=> "Binary",
        ];
        if (!is_file($path . '.zip')) {
            return new Response(404, [], "The zip file for package '{$packageAlias}' could not be created!");
        }
        $filemanager->deleteDir($path);
        return new Response(200, $headers, readfile($path . '.zip'));
        
    }
    
    /**
     * Build the URL to include in packages.json for the composer to download the app
     * 
     * @param AppInterface $app
     * @return string
     */
    public function buildUrlToPackage(AppInterface $app) : string
    {
        $baseUrl = $this->getConfig()->getOption('FACADES.PACKAGIST.URL');
        if (! $baseUrl) {
            $baseUrl = $this->buildUrlToFacade();
        }
        return $baseUrl . '/' . mb_strtolower($app->getVendor() . '/' . str_replace($app->getVendor() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '', $app->getAliasWithNamespace()));
    }

    /**
     * 
     * @return string
     */
    protected function getAppVersion(): string
    {
        if (!$this->appVersion) {
            $this->appVersion = date('Ymd_Hi');
        }
        return $this->appVersion;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/packagist';
    }
    
    /**
     * Returns the absolute path to the puplished folder
     *
     * @return string
     */
    public function getPathToPuplishedFolder() : string
    {
        if (is_null($this->pathToPublishedFolder)) {
            $this->pathToPublishedFolder = $this->getWorkbench()->filemanager()->getPathToDataFolder() . DIRECTORY_SEPARATOR . $this->getApp()->getConfig()->getOption('PAYLOAD.PUBLISHED_DATA_FOLDER_NAME');
            if (! is_dir($this->pathToPublishedFolder)) {
                Filemanager::pathConstruct($this->pathToPublishedFolder);
            }
        }
        return $this->pathToPublishedFolder;
    }
}