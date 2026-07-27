<?php
/**
 * helpers.php — small framework-agnostic utilities used everywhere.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ----------------------------------------------------------------------
//  Output / request helpers
// ----------------------------------------------------------------------

/** True when the current request was made via XMLHttpRequest or fetch(). */
function is_ajax(): bool
{
    $h = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return strtolower($h) === 'xmlhttprequest';
}

/** JSON-encode and exit. */
function json_out(array $data, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * HTML-escape a string for output. Convenience wrapper so views stay short.
 */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Robust base URL, host-agnostic (works on localhost and InfinityFree). */
function base_url(): string
{
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https'
        : (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http') === 'https' ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Strip any script filename and trailing slash from the path.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir    = str_replace('\\', '/', dirname($script));
    if (in_array($dir, ['.', '/'], true)) {
        $dir = '';
    }
    return $proto . '://' . $host . $dir;
}

// ----------------------------------------------------------------------
//  Security helpers
// ----------------------------------------------------------------------

/** Generate a CSRF token, storing it in the session. */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        safe_session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Render a hidden CSRF input for use inside a form. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verify a submitted CSRF token against the session one (constant-time). */
function csrf_verify(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        safe_session_start();
    }
    $expected = $_SESSION['csrf'] ?? '';
    if ($expected === '' || $token === null || $token === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

/** Session starter that survives headers-already-sent edge cases. */
function safe_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (headers_sent()) {
        // Best-effort: start without custom cookie params.
        @session_start();
        return;
    }
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

/** Strong random package code (base32, unambiguous alphabet). */
function generate_code(int $length = PACKAGE_CODE_LEN): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
    $max      = strlen($alphabet) - 1;
    $out      = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/**
 * Generate a guaranteed-unique package code by retrying on collision.
 */
function generate_unique_code(int $length = PACKAGE_CODE_LEN): string
{
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $code = generate_code($length);
        $stmt = db()->prepare('SELECT 1 FROM packages WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() === false) {
            return $code;
        }
    }
    // Extremely unlikely; lengthen and try once more.
    return generate_code($length + 2);
}

/**
 * Build a safe stored filename: random base + original extension.
 * The original extension is preserved but lowercased and stripped of dots.
 */
function generate_stored_name(string $original): string
{
    $ext = pathinfo($original, PATHINFO_EXTENSION);
    $ext = preg_replace('/[^A-Za-z0-9]/', '', $ext);
    $base = bin2hex(random_bytes(12));
    return $ext !== '' ? $base . '.' . strtolower($ext) : $base;
}

/** Format a byte count as a human-readable string. */
function human_size(int|float $bytes): string
{
    $b = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    return ($i === 0 ? (int) $b : round($b, 2)) . ' ' . $units[$i];
}

/** Relative time ("3 minutes ago"). Falls back to a date string. */
function time_ago(?string $ts): string
{
    if ($ts === null) return 'never';
    $t = strtotime($ts);
    if ($t === false) return '—';
    $diff = max(1, time() - $t);
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
    return date('Y-m-d', $t);
}

/**
 * Canonicalise a user-supplied path so it cannot escape UPLOAD_DIR.
 * Returns the absolute real path if it stays inside, or null on traversal.
 */
function safe_upload_path(string $storedName): ?string
{
    $base = realpath(UPLOAD_DIR);
    if ($base === false) {
        return null;
    }
    // Reject obvious traversal in the name itself.
    if (preg_match('/[\\/]|\\.\\.|\\x00/', $storedName)) {
        return null;
    }
    $candidate = $base . DIRECTORY_SEPARATOR . $storedName;
    $real = realpath($candidate);
    if ($real === false || strpos($real, $base) !== 0) {
        return null;
    }
    return $real;
}

// ----------------------------------------------------------------------
//  Settings (cached key/value store)
// ----------------------------------------------------------------------

/** Fetch one setting, with optional default. */
function setting(string $name, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $rows = db()->query('SELECT name, value FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['name']] = $r['value'];
            }
        } catch (Throwable $e) {
            error_log('settings load: ' . $e->getMessage());
        }
    }
    return $cache[$name] ?? $default;
}

/** Persist a setting (admin actions). */
function set_setting(string $name, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([$name, $value]);
}

// ----------------------------------------------------------------------
//  Logging
// ----------------------------------------------------------------------

/** Write a row to the logs table, never throwing. */
function log_event(string $level, string $message, ?string $context = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO logs (level, message, context, ip)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $level,
            safe_substr($message, 0, 2000),
            $context ? safe_substr($context, 0, 2000) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('log_event failed: ' . $e->getMessage());
    }
}

/** GlobalThrowable -> JSON or HTML, used by upload/download entry points. */
function fail(Throwable $e, string $userMsg = 'Something went wrong'): void
{
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    log_event('error', $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    if (is_ajax()) {
        json_out(['ok' => false, 'error' => $userMsg], 500);
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
       . '<div style="font-family:system-ui;padding:3rem;text-align:center">'
       . '<h2>' . e($userMsg) . '</h2>'
       . '<p>Please try again in a moment.</p></div>';
    exit;
}

/** Client IP for logs (handles common proxy header). */
function client_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Multibyte-safe substring with a fallback for hosts without mbstring.
 */
function safe_substr(string $str, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($str, $start, $length);
    }
    return substr($str, $start, $length);
}

/**
 * Multibyte-safe string length with a fallback for hosts without mbstring.
 */
function safe_strlen(string $str): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($str);
    }
    return strlen($str);
}
