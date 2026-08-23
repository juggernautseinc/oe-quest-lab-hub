<?php

/**
 * Lightweight test bootstrap for the Quest Lab Hub module.
 *
 * Loads the module autoloader and the OpenEMR vendor autoloader
 * without initializing globals.php or requiring a database connection.
 * Suitable for isolated unit tests of DTOs, exceptions, validation logic,
 * and controller HTTP status mapping (with mocked services).
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

declare(strict_types=1);

// Module's own autoloader
$moduleAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($moduleAutoload)) {
    require_once $moduleAutoload;
}

// OpenEMR's vendor autoloader (provides PHPUnit, SystemLogger, etc.)
$oeAutoload = dirname(__DIR__, 5) . '/vendor/autoload.php';
if (file_exists($oeAutoload)) {
    require_once $oeAutoload;
}
