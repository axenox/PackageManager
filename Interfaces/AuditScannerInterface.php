<?php
namespace axenox\PackageManager\Interfaces;

use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Allows additional audit engines without changing the audit action.
 * Implementations receive the workbench through their constructor.
 */
interface AuditScannerInterface
{
    /** Determines whether the supplied artifacts or folder can be scanned. */
    public function supports(TaskInterface $task) : bool;

    /** Returns normalized finding rows; missing prerequisites are reported by getHints(). */
    public function audit(TaskInterface $task) : array;

    /** Installs prerequisites only when explicitly requested for this configured scanner. */
    public function install(TaskInterface $task) : void;

    /** Exposes skipped scans so an empty finding list is not mistaken for a complete audit. */
    public function getHints() : array;
}