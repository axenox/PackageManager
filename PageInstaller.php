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
            $page = UiPageFactory::create($this->getWorkbench()->ui(), '', null, $this->getApp()->getAliasWithNamespace());
            $page->importUxonObject(UxonObject::fromJson(file_get_contents($file)));
            // Wird eine Seite neu hinzugefuegt ist der parentDefaultAlias gleich dem
            // gesetzen parentAlias.
            $page->setMenuParentPageDefaultAlias($page->getMenuParentPageAlias());
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
                // Die Seite existiert bereits und wird aktualisiert.
                if ($pageDb->isUpdateable()) {
                    if ($pageDb->getMenuParentPageAlias() != $pageDb->getMenuParentPageDefaultAlias()) {
                        // Die Seite wurde manuell umgehaengt. Der parentDefaultAlias wird
                        // geupdated, die Position im Baum wird nicht geupdated.
                        $pageFile->setMenuIndex($pageDb->getMenuIndex());
                        $pageFile->setMenuParentPageAlias($pageDb->getMenuParentPageAlias());
                    }
                    $pagesUpdate[] = $pageFile;
                }
            } catch (UiPageNotFoundError $upnfe) {
                // Die Seite existiert noch nicht und muss erstellt werden.
                $pagesCreate[] = $pageFile;
            }
        }
        
        foreach ($pagesDb as $pageDb) {
            if (! $this->hasPage($pageDb, $pagesFile) && $pageDb->isUpdateable()) {
                // Die Seite existiert nicht mehr und wird geloescht.
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
        
        return count($pagesCreate) . ' created, ' . count($pagesUpdate) . ' updated, ' . count($pagesDelete) . ' deleted pages for "' . $this->getApp()->getAliasWithNamespace() . '".';
    }

    /**
     * Searches an array of UiPages for a certain UiPage and returns if it is contained.
     * 
     * @param UiPageInterface $page
     * @param UiPageInterface[] $pageArray
     * @return boolean
     */
    protected function hasPage(UiPageInterface $page, $pageArray)
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
     * in der zurueckgegebenen Reihenfolge im CMS aktualisiert, ist sichergestellt, dass der
     * Seitenbaum des Arrays intakt bleibt, egal wo er dann in den existierenden Baum
     * eingehaengt wird.
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
                $parentAlias = $page->getMenuParentPageAlias();
                $parentFound = false;
                // Hat die Seite einen Parent im inputArray?
                foreach ($inputPages as $parentPagePos => $parentPage) {
                    if (strcasecmp($parentAlias, $parentPage->getAliasWithNamespace()) == 0) {
                        $parentFound = true;
                        break;
                    }
                }
                if (! $parentFound) {
                    // Wenn die Seite keinen Parent im inputArray hat, hat sie einen im
                    // outputArray?
                    foreach ($sortedPages as $parentPagePos => $parentPage) {
                        if (strcasecmp($parentAlias, $parentPage->getAliasWithNamespace()) == 0) {
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
                    $pagePos++;
                }
                // Alle Seiten im inputArray durchgehen.
            } while ($pagePos < count($inputPages));
            $i++;
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
        
        return count($pages) . ' pages for "' . $this->getApp()->getAliasWithNamespace() . '" exported.';
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::uninstall()
     */
    public function uninstall()
    {}
}
