<?php
namespace axenox\PackageManager\Interfaces;

use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Allows additional audit engines without changing the audit action.
 * 
 * Implementations receive the workbench through their constructor.
 * Scanners consume task parameters, not input DataSheet rows: composer_lock
 * contains one Composer lock and sbom contains one CycloneDX or SPDX document.
 * Each artifact accepts a JSON string, decoded array or UxonObject. The action
 * owns context enrichment. Without artifact parameters, folder selects a local
 * build or defaults to the installation. Artifact parameters take precedence
 * over folder and disable folder fallback for all scanners.
 */
interface AuditScannerInterface
{
    /**
     * Determines whether the supplied artifacts or folder can be scanned.
     * 
     * The action calls this before auditing to exclude scanners that do not
     * support the supplied input.
     * 
     * @param TaskInterface $task
     * @return bool
     */
    public function supports(TaskInterface $task) : bool;

    /**
     * Returns typed findings for the supplied artifacts or folder.
     * 
     * Missing prerequisites are reported by getHints(), so an empty list of
     * findings must not be interpreted as a completed audit on its own.
     * 
     * @param TaskInterface $task
     * @return FindingInterface[]
     */
    public function audit(TaskInterface $task) : array;

    /**
     * Installs prerequisites for this configured scanner.
     * 
     * Installation must only run when explicitly requested, never as an
     * automatic side effect of supports() or audit().
     * 
     * @param TaskInterface $task
     * @return void
     */
    public function install(TaskInterface $task) : void;

    /**
     * Returns hints explaining skipped scans or missing prerequisites.
     * 
     * These messages distinguish an incomplete scan from a completed audit
     * that found no vulnerabilities.
     * 
     * @return string[]
     */
    public function getHints() : array;
}