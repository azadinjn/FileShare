<?php
/**
 * login.php — admin login form + handler.
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

if (is_admin_logged_in()) {
    header('Location: ' . base_url() . '/admin.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = 'Security token expired. Please refresh the page.';
    } else {
        $u = trim($_POST['username'] ?? '');
        $p = (string) ($_POST['password'] ?? '');
        if ($u === '' || $p === '') {
            $error = 'Enter both username and password.';
        } elseif (attempt_login($u, $p)) {
            header('Location: ' . base_url() . '/admin.php');
            exit;
        } else {
            $error = 'Invalid credentials.';
        }
    }
}

$siteName = setting('site_name', APP_NAME) ?? APP_NAME;
$pageTitle = 'Sign in';
include __DIR__ . '/includes/header.php';
?>
<main class="container narrow login-page">
    <section class="glass login-card anim-in" data-aos="fade-up">
        <h1><i class="fa-solid fa-lock"></i> Sign in</h1>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(base_url()) ?>/login.php" class="login-form">
            <?= csrf_field() ?>
            <label class="field">
                <span class="field-label">Username</span>
                <input type="text" name="username" autocomplete="username" required autofocus>
            </label>
            <label class="field">
                <span class="field-label">Password</span>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa-solid fa-right-to-bracket"></i> Sign in
            </button>
        </form>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
