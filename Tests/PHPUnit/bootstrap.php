<?php

$packageRoot = dirname(__DIR__, 2);
$autoloadCandidates = [
    $packageRoot . '/vendor/autoload.php',
    dirname($packageRoot, 2) . '/autoload.php'
];

foreach ($autoloadCandidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

if (! class_exists(\PHPUnit\Framework\TestCase::class)) {
    throw new \RuntimeException('Composer autoloader with PHPUnit could not be found.');
}

require_once __DIR__ . '/Support/AuditTestCase.php';
require_once __DIR__ . '/Support/AuditFixtures.php';