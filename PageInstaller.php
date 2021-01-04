<?php
namespace axenox\PackageManager;

use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\CommonLogic\Model\UiPage;
use exface\Core\Factories\UiPageFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Exceptions\UiPage\UiPageNotFoundError;
use exface\Core\Exceptions\UiPage\UiPageIdMissingError;
use exface\Core\Exceptions\Installers\InstallerRuntimeError;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Behaviors\TranslatableBehavior;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\Selectors\SelectorInterface;
use exface\Core\DataTypes\FilePathDataType;

/**
 * Saves pages as UXON (JSON) files and imports these files back into model when needed.
 * 
 * Each page is stored as a separate JSON file using it's `UiPageInterface::exportUxonObject()`.
 * All pages are stored in a single folder. The path to that folder can be passed to the
 * installer's constructor.
 * 
 * NOTE: The `TimeStampingBehavior` of the model objects is disabled before install, so the
 * create/update stamps of the exported model are saved correctly.
 * 
 * @author Andrej Kabachnik
 *
 */
class PageInstaller extends AbstractAppInstaller
{
    private $transaction = null;
    
    private $path = null;
    
    /**
     * 
     * @param SelectorInterface $selectorToInstall
     * @param string $pathRelativeToAppFolder
     */
    public function __construct(SelectorInterface $selectorToInstall, string $pathRelativeToAppFolder = 'Model'.DIRECTORY_SEPARATOR.'Pages')
    {
        parent::__construct($selectorToInstall);
        $this->path = $pathRelativeToAppFolder;
    }

    /**
     * 
     * @param string $source_path
     * @return string
     */
    protected function getPagesPathAbsolute(string $source_path) : string
    {
        return $source_path . DIRECTORY_SEPARATOR . $this->path;
    }
    
    /**
     * 
     * @param MetaObjectInterface $object
     * @return void
     */
    protected function disableTimestampingBehavior(MetaObjectInterface $object)
    {
        // Disable timestamping behavior because it will prevent multiple installations of the same
        // model since the first install will set the update timestamp to something later than the
        // timestamp saved in the model files
        foreach ($object->getBehaviors()->getByPrototypeClass(TimeStampingBehavior::class) as $behavior) {
            $behavior->disable();
        }
        
        $object->getAttribute('MODIFIED_BY_USER')->setFixedValue(null);
        $object->getAttribute('MODIFIED_ON')->setFixedValue(null);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::install()
     */
    public function install(string $source_absolute_path) : \Iterator
    {
        $idt = $this->getOutputIndentation();
        $pagesFile = [];
        $workbench = $this->getWorkbench();
        
        $this->disableTimestampingBehavior($this->getWorkbench()->model()->getObject('exface.Core.PAGE'));
        
        yield $idt . 'Pages: ' . PHP_EOL;
        
        $dir = $this->getPagesPathAbsolute($source_absolute_path);
        if (! is_dir($dir)) {
            $formerDir = $this->getPagesPathOld($source_absolute_path);
            if (is_dir($formerDir)) {
                $dir = $formerDir;
            } else {
                yield $idt . 'No pages to install' . PHP_EOL;
            }
        }
        
        // Find pages files. 
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            $workbench->getLogger()->logException((new InstallerRuntimeError($this, 'Error reading folder "' . $dir . DIRECTORY_SEPARATOR . '*.json"! - no pages were installed!')));
        }
        // Make sure, the array only contains existing files.
        $files = array_filter($files, 'is_file');
        
        // Load pages. If anything goes wrong, the installer should not continue to avoid broken menu
        // structures etc., so don't silence any exceptions here.
        foreach ($files as $file) {
            try {
                $page = UiPageFactory::createFromUxon($workbench, UxonObject::fromJson(file_get_contents($file)));
                $page->setApp($this->getApp()->getSelector());
                // Wird eine Seite neu hinzugefuegt ist die menuDefaultPosition gleich der
                // gesetzen Position.
                $page->setParentPageSelectorDefault($page->getParentPageSelector());
                $page->setMenuIndexDefault($page->getMenuIndex());
                $pagesFile[] = $page;
            } catch (\Throwable $e) {
                throw new InstallerRuntimeError($this, 'Cannot load page model from file "' . $file . '": corrupted UXON?', null, $e);
            }
        }
        $pagesFile = $this->sortPages($pagesFile);
        
        // Pages aus der Datenbank laden.
        $pagesDb = $this->getPagesForApp($this->getApp());
        
        // Pages vergleichen und bestimmen welche erstellt, aktualisiert oder geloescht werden muessen.
        $pagesCreate = [];
        $pagesCreateErrors = [];
        $pagesUpdate = [];
        $pagesUpdateErrors = [];
        $pagesUpdateDisabled = [];
        $pagesUpdateMoved = [];
        $pagesDelete = [];
        $pagesDeleteErrors = [];
        
        foreach ($pagesFile as $pageFile) {
            // Try to load the local copy of the page
            try {
                $pageExisting = UiPageFactory::createFromModel($this->getWorkbench(), $pageFile->getUid(), true);
                // Only update things, if updates are not explicitly disabled for the page
                if ($pageExisting->isUpdateable() === true) {
                    // If the page was moved elsewhere on the local system, keep it's position,
                    // but update the rest of it.
                    if ($pageExisting->isMoved()) {
                        $pageFile->setMenuIndex($pageExisting->getMenuIndex());
                        $pageFile->setParentPageSelector($pageExisting->getParentPageSelector());
                        // Remember the page to be able to print it in a separate part of the response
                        $pagesUpdateMoved[] = $pageFile;
                    }
                    // Remember the page to peform the update later
                    $pagesUpdate[] = $pageFile;
                } else {
                    $pagesUpdateDisabled[] = $pageFile;
                }
            } catch (UiPageNotFoundError $upnfe) {
                // If no local page was found, it obviously needs to be created.
                $pagesCreate[] = $pageFile;
            }
        }
        
        foreach ($pagesDb as $pageExisting) {
            if (! $this->hasPage($pageExisting, $pagesFile) && $pageExisting->isUpdateable()) {
                // Die Seite existiert nicht mehr und wird geloescht.
                $pagesDelete[] = $pageExisting;
            }
        }
        
        // Pages erstellen.
        $pagesCreatedCounter = 0;
        foreach ($pagesCreate as $page) {
            // Check if the installaed page has a parent and that parent page exists.
            if ($page->getParentPageSelector() !== null) {
                try {
                    $page->getParentPage(true);
                } catch (UiPageNotFoundError $eParent) {
                    // If the parent selector is as UID, we can still leave it in place,
                    // so the parent page may be installed afterwards. However, if it's not
                    // a UID, there is no place to get the UID from and, thus, we don't have
                    // anything to save. In this case, we 
                    if (! $page->getParentPageSelector()->isUid()) {
                        $pagesCreateErrors[] = ['page' => $page, 'exception' => new RuntimeException('Parent page "' . $page->getParentPageSelector()->__toString() . '" of page "' . $page->getAliasWithNamespace() . '" not found! The parent-relationship will be lost!')];
                        $page->setParentPageSelector(null);
                        $page->setParentPageSelectorDefault(null);
                    } else {
                        $pagesCreateErrors[] = ['page' => $page, 'exception' => new RuntimeException('Parent page "' . $page->getParentPageSelector()->__toString() . '" of page "' . $page->getAliasWithNamespace() . '" not found! The parent-relationship will be restored once the parent page ist installed.')];
                    }
                    $page->setPublished(false);
                }
            }
            // Now create the page in the model
            try {
                $pagesCreatedCounter += $this->createPage($page);
            } catch (\Throwable $e) {
                $workbench->getLogger()->logException($e);
                $pagesCreateErrors[] = ['page' => $page, 'exception' => $e];
            }
        }
        if ($pagesCreatedCounter) {
            yield $idt.$idt . 'Created - ' . $pagesCreatedCounter . PHP_EOL;
        }
        $pagesCreatedErrorCounter = count($pagesCreateErrors);
        if ($pagesCreatedErrorCounter > 0) {
            yield $idt.$idt . 'Create errors:' . PHP_EOL;
            foreach ($pagesCreateErrors as $err) {
                $pageFile = $err['page'];
                $exception = $err['exception'];
                yield $idt.$idt.$idt . '- ' . $pageFile->getAliasWithNamespace() . ' (' . $pageFile->getId() . '): ' . $exception->getMessage() . ' in ' . $exception->getFile() . ' on ' . $exception->getLine(). PHP_EOL;
            }
        }
        
        // Pages aktualisieren.
        $pagesUpdatedCounter = 0;
        foreach ($pagesUpdate as $page) {
            try {
                $pagesUpdatedCounter += $this->updatePage($page);
            } catch (\Throwable $e) {
                $workbench->getLogger()->logException($e);
                $pagesUpdateErrors[] = ['page' => $page, 'exception' => $e];
            }
        }
        if ($pagesUpdatedCounter) {
            yield $idt.$idt . 'Updated - ' . $pagesUpdatedCounter . PHP_EOL;
        }
        if (empty($pagesUpdateDisabled) === false) {
            yield $idt.$idt . 'Update disabled in page model:' . PHP_EOL;
            foreach ($pagesUpdateDisabled as $pageFile) {
                yield $idt.$idt.$idt . '- ' . $pageFile->getAliasWithNamespace() . ' (' . $pageFile->getId() . ')' . PHP_EOL;
            }
        }
        if (empty($pagesUpdateMoved) === false) {
            yield $idt.$idt . 'Updated partially because moved to another menu position:' . PHP_EOL;
            foreach ($pagesUpdateMoved as $pageFile) {
                yield $idt.$idt.$idt . '- ' . $pageFile->getAliasWithNamespace() . ' (' . $pageFile->getId() . ')' . PHP_EOL;
            }
        }
        $pagesUpdatedErrorCounter = count($pagesUpdateErrors);
        if ($pagesUpdatedErrorCounter) {
            yield $idt.$idt . 'Update errors:' . PHP_EOL;
            foreach ($pagesUpdateErrors as $err) {
                $pageFile = $err['page'];
                $exception = $err['exception'];
                yield $idt.$idt.$idt . '- ' . $pageFile->getAliasWithNamespace() . ' (' . $pageFile->getId() . '): ' . $exception->getMessage() . ' in ' . $exception->getFile() . ' on ' . $exception->getLine(). PHP_EOL;
            }
        }
        
        // Pages loeschen.
        $pagesDeletedCounter = 0;
        foreach ($pagesDelete as $page) {
            try {
                $pagesDeletedCounter += $this->deletePage($page);
            } catch (\Throwable $e) {
                $workbench->getLogger()->logException($e);
                $pagesDeleteErrors[] = $page;
            }
        }
        if ($pagesDeletedCounter) {
            yield $idt.$idt . 'Deleted - ' . $pagesDeletedCounter . PHP_EOL;
        }
        $pagesDeletedErrorCounter = count($pagesDeleteErrors);
        if ($pagesDeletedErrorCounter > 0) {
            yield $idt.$idt . 'Delete errors:' . PHP_EOL;
            foreach ($pagesDeleteErrors as $pageFile) {
                yield $idt.$idt.$idt . '- ' . $pageFile->getAliasWithNamespace() . ' (' . $pageFile->getId() . ')' . PHP_EOL;
            }
        }
        
        if ($pagesCreatedCounter+$pagesCreatedErrorCounter+$pagesUpdatedCounter+$pagesUpdatedErrorCounter+$pagesDeletedErrorCounter+$pagesDeletedCounter === 0) {
            yield $idt.$idt . 'No changes found' . PHP_EOL;
        }
    }
    
    /**
     * 
     * @param AppInterface $app
     * @return UiPageInterface[]
     */
    protected function getPagesForApp(AppInterface $app) : array
    {
        $pageObj = $app->getWorkbench()->model()->getObject('exface.Core.PAGE');
        $pagesDs = DataSheetFactory::createFromObject($pageObj);
        $pagesDs->getColumns()->addFromUidAttribute();
        $pagesDs->getFilters()->addConditionFromString('APP__ALIAS', $app->getAliasWithNamespace(), ComparatorDataType::EQUALS);
        $pagesDs->dataRead();
        
        $pages = [];
        foreach ($pagesDs->getUidColumn()->getValues() as $pageUid) {
            $pages[] = UiPageFactory::createFromModel($app->getWorkbench(), $pageUid, true);
        }
        
        return $pages;
    }

    /**
     * Searches an array of UiPages for a certain UiPage and returns if it is contained.
     * 
     * @param UiPageInterface $page
     * @param UiPageInterface[] $pageArray
     * @return bool
     */
    protected function hasPage(UiPageInterface $page, array $pageArray) : bool
    {
        foreach ($pageArray as $arrayPage) {
            if ($page->isExactly($arrayPage)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ein Array von UiPages wird sortiert und zurueckgegeben. Die Sortierung erfolgt so, dass
     * Seiten ohne Parent im uebergebenen Array, ganz nach oben sortiert werden. Hat die Seite
     * einen Parent im Array, so wird sie nach diesem Parent einsortiert. Werden die Seiten
     * in der zurueckgegebenen Reihenfolge im Modell aktualisiert, ist sichergestellt, dass der
     * Seitenbaum des Arrays intakt bleibt, egal wo er dann in den existierenden Baum
     * eingehaengt wird.
     * 
     * @param UiPageInterface[] $pages
     * @return UiPageInterface[]
     */
    protected function sortPages(array $pages) : array
    {
        if (empty($pages)) {
            return $pages;
        }
        
        $inputPages = $pages;
        $sortedPages = [];
        $i = 0;
        do {
            $pagePos = 0;
            do {
                $page = $inputPages[$pagePos];
                $parentSelector = $page->getParentPageSelector();
                $parentFound = false;
                // Hat die Seite einen Parent im inputArray?
                foreach ($inputPages as $parentPagePos => $parentPage) {
                    if ($parentPage->isExactly($parentSelector)) {
                        $parentFound = true;
                        break;
                    }
                }
                if (! $parentFound) {
                    // Wenn die Seite keinen Parent im inputArray hat, hat sie einen im
                    // outputArray?
                    foreach ($sortedPages as $parentPagePos => $parentPage) {
                        if ($parentPage->isExactly($parentSelector)) {
                            $parentFound = true;
                            break;
                        }
                    }
                    // Hat sie einen Parent im outputArray, dann wird sie nach diesem
                    // einsortiert, sonst wird sie am Anfang einsortiert.
                    $out = array_splice($inputPages, $pagePos, 1);
                    array_splice($sortedPages, $parentFound ? $parentPagePos + 1 : 0, 0, $out);
                } else {
                    // Hat die Seite einen Parent im inputArray dann wird sie erstmal ueber-
                    // sprungen. Sie wird erst im outputArray einsortiert, nachdem ihr Parent
                    // dort einsortiert wurde.
                    $pagePos ++;
                }
                // Alle Seiten im inputArray durchgehen.
            } while ($pagePos < count($inputPages));
            $i ++;
            // So oft wiederholen wie es Seiten im inputArray gibt oder die Abbruchbedingung
            // erfuellt ist (kreisfoermige Referenzen).
        } while (count($inputPages) > 0 && $i < count($pages));
        
        if (count($inputPages) > 0) {
            // Sortierung nicht erfolgreich, kreisfoermige Referenzen? Die unsortierten Seiten
            // werden zurueckgegeben.
            return $pages;
        } else {
            return $sortedPages;
        }
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::backup()
     */
    public function backup(string $destination_absolute_path) : \Iterator
    {
        /** @var Filemanager $fileManager */
        $fileManager = $this->getWorkbench()->filemanager();
        $idt = $this->getOutputIndentation();
        $path = $this->getPagesPathAbsolute($destination_absolute_path);
        
        // Empty pages folder in case it is an update
        try {
            if (is_dir($path)) {
                $fileManager::emptyDir($this->getPagesPathAbsolute($destination_absolute_path));
            }
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
        }
        if (is_dir($this->getPagesPathOld($destination_absolute_path))) {
            $fileManager::deleteDir(FilePathDataType::findFolderPath($this->getPagesPathOld($destination_absolute_path)));
        }
        
        // Start a new workbench with a custom config. Remove all static listeners 
        // of the TranslatableBehavior from that config to ensure, that page properties
        // do not get translated when being loaded.
        $exportConfig = [
            'EVENTS.STATIC_LISTENERS' => $this->getWorkbench()->getConfig()->getOption('EVENTS.STATIC_LISTENERS')->toArray()
        ];
        foreach ($exportConfig['EVENTS.STATIC_LISTENERS'] as $eventName => $listeners) {
            foreach ($listeners as $idx => $listener) {
                if (StringDataType::startsWith($listener, '\\' . TranslatableBehavior::class . '::')) {
                    unset($exportConfig['EVENTS.STATIC_LISTENERS'][$eventName][$idx]);
                }
            }
        }
        $exportWb = Workbench::startNewInstance($exportConfig);
        $exportApp = $exportWb->getApp($this->getApp()->getSelector());
        
        // Dann alle Dialoge der App als Dateien in den Ordner schreiben.
        $pages = $this->getPagesForApp($exportApp);
        
        if (! empty($pages)) {
            $fileManager->pathConstruct($path);
        }
        
        /** @var UiPage $page */
        foreach ($pages as $page) {
            try {
                // Hat die Seite keine UID wird ein Fehler geworfen. Ohne UID kann die Seite nicht
                // manipuliert werden, da beim Aktualisieren oder Loeschen die UID benoetigt wird.
                if (! $page->getUid()) {
                    throw new UiPageIdMissingError('The UiPage "' . $page->getAliasWithNamespace() . '" has no UID.');
                }
                
                // Exportieren der Seite
                $contents = $page->exportUxonObject()->toJson(true);
                $fileManager->dumpFile($path . DIRECTORY_SEPARATOR . $page->getAliasWithNamespace() . '.json', $contents);
            } catch (\Throwable $e) {
                throw new InstallerRuntimeError($this, 'Unknown error while backing up page "' . $page->getAliasWithNamespace() . '"!', null, $e);
            }
        }
        
        yield $idt . 'Exported ' . count($pages) . ' pages successfully.' . PHP_EOL;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::uninstall()
     */
    public function uninstall() : \Iterator
    {
        $idt = $this->getOutputIndentation();
        $pages = $this->getPagesForApp($this->getApp());
        
        yield $idt . 'Uninstalling pages...';
        
        if (empty($pages)) {
            yield $idt . ' No pages to uninstall' . PHP_EOL;
        }
        
        /** @var UiPage $page */
        $counter = 0;
        foreach ($pages as $page) {
            try {
                $this->deletePage($page);
                $counter++; 
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
                yield $idt . $idt . 'ERROR deleting page "' . $page->getName() . '" (' . $page->getAliasWithNamespace() . ')!';
            }
        }
        
        yield ' removed ' . $counter . ' pages successfully' . PHP_EOL;
    }
    
    /**
     * 
     * @param UiPageInterface $page
     * @param DataTransactionInterface $transaction
     * @return int
     */
    protected function createPage(UiPageInterface $page, DataTransactionInterface $transaction = null) : int
    {
        $transaction = $transaction ?? $this->getTransaction();
        $ds = $this->createPageDataSheet();
        $page->exportDataRow($ds);
        return $ds->dataCreate(false, $transaction);
    }
    
    /**
     * 
     * @param UiPageInterface $page
     * @param DataTransactionInterface $transaction
     * @return int
     */
    protected function updatePage(UiPageInterface $page, DataTransactionInterface $transaction = null) : int
    {
        $transaction = $transaction ?? $this->getTransaction();
        $ds = $this->createPageDataSheet();
        $page->exportDataRow($ds);
        return $ds->dataUpdate(false, $transaction);
    }
    
    /**
     * 
     * @param UiPageInterface $page
     * @param DataTransactionInterface $transaction
     * @return int
     */
    protected function deletePage(UiPageInterface $page, DataTransactionInterface $transaction = null) : int
    {
        $transaction = $transaction ?? $this->getTransaction();
        $ds = $this->createPageDataSheet();
        $page->exportDataRow($ds);
        return $ds->dataDelete($transaction);
    }
    
    /**
     * 
     * @return DataSheetInterface
     */
    protected function createPageDataSheet() : DataSheetInterface
    {
        $data_sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.PAGE');        
        return $data_sheet;
    }
    
    /**
     * 
     * @return DataTransactionInterface
     */
    public function getTransaction() : DataTransactionInterface
    {
        if ($this->transaction === null) {
            $this->transaction = $this->getWorkbench()->data()->startTransaction();
        }
        return $this->transaction;
    }
    
    /**
     * 
     * @param DataTransactionInterface $transaction
     * @return PageInstaller
     */
    public function setTransaction(DataTransactionInterface $transaction) : PageInstaller
    {
        $this->transaction = $transaction;
        return $this;
    }
    
    /**
     * Returns the old (0.x) path to pages to support older app exports.
     * 
     * In the 0.x version of the workbench the pages were stored in `Install/Pages/{lang}`.
     * 
     * @param string $source_absolute_path
     * @return string
     */
    private function getPagesPathOld(string $source_absolute_path) : string
    {
        $languageCode = $this->getApp()->getLanguageDefault();
        if (! $languageCode) {
            $defaultLocale = $this->getWorkbench()->getConfig()->getOption("SERVER.DEFAULT_LOCALE");
            $languageCode = substr($defaultLocale, 0, strpos($defaultLocale, '_'));
        }
        return $source_absolute_path . DIRECTORY_SEPARATOR . 'Install' . DIRECTORY_SEPARATOR . 'Pages' . DIRECTORY_SEPARATOR . $languageCode;
    }
}