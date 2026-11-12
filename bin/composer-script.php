<?php
/**
 * Entry point for the `@php` Composer scripts of the package manager - see `axenox\PackageManager\StaticInstaller`.
 * 
 * Usage: `php vendor/axenox/packagemanager/bin/composer-script.php composerFinishUpdate`
 * 
 * Runs in its own PHP process, so the workbench only loads the libraries from the `vendor` folder of the installation
 * and never those bundled with Composer.
 * 
 * Security: only works on the command line, because the `vendor` folder may be reachable via web server. The method
 * name is checked against a whitelist in `StaticInstaller::runComposerScript()`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../../../autoload.php';

exit(\axenox\PackageManager\StaticInstaller::runComposerScript($argv[1] ?? ''));