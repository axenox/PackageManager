<?php
namespace axenox\PackageManager;

use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\CommonLogic\Model\UiPage;
use exface\Core\Factories\UiPageFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Exceptions\UiPageNotFoundError;

class PageInstaller extends AbstractAppInstaller
{

    const FOLDER_NAME_PAGES = 'Install\\Pages';

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::install()
     */
    public function install($source_absolute_path)
    {
        $pagesFile = [];
        // Ordner entsprechend momentaner Sprache bestimmen.
        $baseDir = $source_absolute_path . DIRECTORY_SEPARATOR . $this::FOLDER_NAME_PAGES;
        if (is_dir($baseDir . DIRECTORY_SEPARATOR . $this->getApp()->getTranslator()->getLocale())) {
            $dir = $baseDir . DIRECTORY_SEPARATOR . $this->getApp()->getTranslator()->getLocale();
        } else {
            foreach ($this->getApp()->getTranslator()->getFallbackLocales() as $fallbackLocale) {
                if (is_dir($baseDir . DIRECTORY_SEPARATOR . $fallbackLocale)) {
                    $dir = $baseDir . DIRECTORY_SEPARATOR . $fallbackLocale;
                    break;
                }
            }
        }
        if (! $dir) {
            // Ist entsprechend der momentanen Sprache kein passender Ordner vorhanden, wird
            // nichts gemacht.
            return;
        }
        // Pages aus Dateien laden.
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
            $page = UiPageFactory::create($this->getWorkbench()->ui(), '');
            $page->importUxonObject(UxonObject::fromJson(file_get_contents($file)));
            $page->setAppAlias($this->getApp()->getAliasWithNamespace());
            $pagesFile[] = $page;
        }
        $pagesFile = $this->sortPages($pagesFile);
        
        // Pages aus der Datenbank laden.
        $pagesDb = $this->getWorkbench()->getCMS()->getPagesForApp($this->getApp());
        
        // Pages vergleichen und bestimmen welche erstellt, aktualisiert oder geloescht werden muessen.
        $pagesCreate = [];
        $pagesUpdate = [];
        $pagesDelete = [];
        
        foreach ($pagesFile as $pageFile) {
            try {
                $pageDb = $this->getWorkbench()->getCMS()->loadPageById($pageFile->getId(), true);
                if ($pageDb->isUpdateable()) {
                    $pagesUpdate[] = $pageFile;
                }
            } catch (UiPageNotFoundError $upnfe) {
                $pagesCreate[] = $pageFile;
            }
        }
        
        foreach ($pagesDb as $pageDb) {
            if (! $this->findPage($pageDb->getId(), $pagesFile) && $pageDb->isUpdateable()) {
                $pagesDelete[] = $pageDb;
            }
        }
        
        // Pages erstellen.
        foreach ($pagesCreate as $page) {
            $this->getWorkbench()->getCMS()->createPage($page);
        }
        
        // Pages aktualisieren.
        foreach ($pagesUpdate as $page) {
            $this->getWorkbench()->getCMS()->updatePage($page);
        }
        
        // Pages loeschen.
        foreach ($pagesDelete as $page) {
            $this->getWorkbench()->getCMS()->deletePage($page);
        }
    }

    /**
     * 
     * @param string $uid
     * @param UiPageInterface[] $pages
     * @return UiPageInterface|NULL
     */
    protected function findPage($uid, $pages)
    {
        foreach ($pages as $page) {
            if ($uid == $page->getId()) {
                return $page;
            }
        }
        return null;
    }

    /**
     * 
     * @param UiPageInterface[] $pages
     */
    protected function sortPages($pages)
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
                $parentId = $page->getMenuParentId();
                $parentFound = false;
                foreach ($inputPages as $parentPagePos => $parentPage) {
                    if ($parentId == $parentPage->getId()) {
                        $parentFound = true;
                        break;
                    }
                }
                if (! $parentFound) {
                    foreach ($sortedPages as $parentPagePos => $parentPage) {
                        if ($parentId == $parentPage->getId()) {
                            $parentFound = true;
                            break;
                        }
                    }
                    $out = array_splice($inputPages, $pagePos, 1);
                    array_splice($sortedPages, $parentFound ? $parentPagePos + 1 : 0, 0, $out);
                } else {
                    $pagePos++;
                }
            } while ($pagePos < count($inputPages));
            // Abbruch bei kreisfoermigen Referenzen
            $i++;
        } while (count($inputPages) > 0 && $i < count($pages));
        
        if (count($inputPages) > 0) {
            // Sortierung nicht erfolgreich, kreisfoermige Referenzen?
            return $pages;
        } else {
            return $sortedPages;
        }
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::update()
     */
    public function update($source_absolute_path)
    {
        $this->install($source_absolute_path);
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::backup()
     */
    public function backup($destination_absolute_path)
    {
        /** @var Filemanager $fileManager */
        /** @var UiPage $page */
        $fileManager = $this->getWorkbench()->filemanager();
        $dir = $destination_absolute_path . DIRECTORY_SEPARATOR . $this::FOLDER_NAME_PAGES . DIRECTORY_SEPARATOR . $this->getApp()->getTranslator()->getLocale();
        $fileManager->pathConstruct($dir);
        
        // Zuerst alle Dateien im Ordner loeschen.
        $fileManager->remove(glob($dir . DIRECTORY_SEPARATOR . '*'));
        
        // Dann alle Dialoge der App als Dateien in den Ordner schreiben.
        $pages = $this->getWorkbench()->getCMS()->getPagesForApp($this->getApp());
        foreach ($pages as $page) {
            $contents = $page->exportUxonObject()->toJson(true);
            $fileManager->dumpFile($dir . DIRECTORY_SEPARATOR . $page->getAliasWithNamespace() . '.json', $contents);
        }
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::uninstall()
     */
    public function uninstall()
    {}
}
