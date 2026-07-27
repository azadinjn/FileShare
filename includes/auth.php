<?php
/**
 * auth.php — admin authentication helpers (password_hash + session).
 */

require_once __DIR__ . '/helpers.php';

/** True if an admin is logged in for this session. */
function is_admin_logged_in(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        safe_session_start();
    }
    return !empty($_SESSION['admin_id']);
}

/** Require an admin session; otherwise redirect to login. */
function require_admin(): void
{
    if (!is_admin_logged_in()) {
        header('Location: ' . base_url() . '/login.php');
        exit;
    }
}

/** Attempt a login. Returns true on success, false on bad credentials. */
function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, username, password FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($password, $row['password'])) {
        log_event('warn', 'Failed admin login', $username);
        return false;
    }

    // Re-hash if PHP's default has strengthened since the hash was made.
    if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
        $new = password_hash($password, PASSWORD_DEFAULT);
        $up = db()->prepare('UPDATE admins SET password = ? WHERE id = ?');
        $up->execute([$new, $row['id']]);
    }

    safe_session_start();
    session_regenerate_id(true);
    $_SESSION['admin_id']   = (int) $row['id'];
    $_SESSION['admin_user'] = $row['username'];

    log_event('info', 'Admin login', $username);
    return true;
}

/** Log the admin out and destroy the session. */
function logout_admin(): void
{
    safe_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '',
            $p['secure'] ?? false, $p['httponly'] ?? false);
    }
    session_destroy();
}
