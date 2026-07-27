<?php
/**
 * config.php — central configuration.
 *
 * Edit the $DB_* block to match your host. On InfinityFree the values are
 * shown in the control panel ("MySQL Databases"). On XAMPP the defaults
 * below work out of the box.
 *
 * Nothing in this file is host-specific beyond the DB credentials, so the
 * same code runs unchanged on InfinityFree and localhost.
 */

// ----------------------------------------------------------------------
//  Database credentials
// ----------------------------------------------------------------------
$DB_HOST = 'localhost';   // InfinityFree uses the hostname shown in the panel
$DB_NAME = 'filevault';   // create this DB (or use the one InfinityFree gave you)
$DB_USER = 'root';        // XAMPP default; on InfinityFree use the panel username
$DB_PASS = '';            // XAMPP default; on InfinityFree use the panel password
$DB_CHARSET = 'utf8mb4';

// ----------------------------------------------------------------------
//  Application constants
// ----------------------------------------------------------------------
const APP_NAME      = 'FileVault';
const APP_TIMEZONE  = 'UTC';
const APP_VERSION   = '1.0.0';

// Upload limits (bytes). InfinityFree caps PHP at its own limits; we stay below.
const MAX_UPLOAD_BYTES = 524288000;          // 500 MB per file (hard PHP-side cap)
const PACKAGE_CODE_LEN = 10;                  // length of generated download codes

// Folder paths resolved from this file so they work on any host.
// Use define() (not const) because dirname() is a function call — const
// expressions cannot contain function calls.
define('ROOT_PATH',  dirname(__DIR__));
define('UPLOAD_DIR', ROOT_PATH . '/uploads');
define('TEMP_DIR',   ROOT_PATH . '/temp');
define('LOG_DIR',    ROOT_PATH . '/temp');

// Session cookie hardening (best-effort: ignored if headers already sent).
const SESSION_NAME = 'FVSESSID';

// ----------------------------------------------------------------------
//  PHP runtime tuning
// ----------------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);

// Show errors only on localhost; production hides them and logs instead.
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || ($_SERVER['HTTP_HOST'] ?? '') === 'localhost';

if ($isLocal) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', LOG_DIR . '/php_errors.log');
}

// Safety: make sure essential folders exist (shared hosts create them too).
foreach ([UPLOAD_DIR, TEMP_DIR, LOG_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
