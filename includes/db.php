<?php
/**
 * db.php — singleton PDO connection.
 *
 * Uses the credentials from config.php. Prepared statements are the caller's
 * responsibility; this file only hands out a configured PDO instance.
 */

/**
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;

    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 10,
    ];

    try {
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    } catch (PDOException $e) {
        // Never leak credentials or full DSN to the browser.
        error_log('DB connect failed: ' . $e->getMessage());
        http_response_code(500);
        if (is_ajax()) {
            json_out(['ok' => false, 'error' => 'Database is temporarily unavailable.']);
        }
        echo '<!doctype html><meta charset="utf-8"><title>Maintenance</title>'
           . '<div style="font-family:system-ui;padding:3rem;text-align:center">'
           . '<h2>Database unavailable</h2><p>Please try again in a moment.</p></div>';
        exit;
    }

    return $pdo;
}
