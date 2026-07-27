<?php
/**
 * install.php — one-time setup helper.
 *
 * Creates all tables from sql/schema.sql and seeds the default settings and
 * admin account. Safe to re-run (uses CREATE TABLE IF NOT EXISTS and
 * ON DUPLICATE KEY UPDATE).
 *
 * After a successful run, DELETE this file from the server.
 */

require_once __DIR__ . '/includes/helpers.php';

$ok = false;
$messages = [];

try {
    $pdo = db();

    $sqlFile = ROOT_PATH . '/sql/schema.sql';
    if (!is_file($sqlFile)) {
        throw new RuntimeException('sql/schema.sql not found.');
    }
    $sql = file_get_contents($sqlFile);

    // Run each statement. Split on ";" at line ends (simple but sufficient
    // for this schema, which has no stored procedures).
    $statements = preg_split('/;\s*[\r\n]+/', $sql);
    $count = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        $pdo->exec($stmt);
        $count++;
    }

    $messages[] = "Schema applied ($count statements).";
    $messages[] = 'Default admin: <strong>admin</strong> / <strong>admin123</strong> — change this immediately.';
    $messages[] = 'Delete install.php now.';
    $ok = true;
} catch (Throwable $e) {
    $messages[] = 'Error: ' . $e->getMessage();
}

$siteName = setting('site_name', APP_NAME) ?? APP_NAME;
$pageTitle = 'Install';
include __DIR__ . '/includes/header.php';
?>
<main class="container narrow">
    <section class="glass hero-card anim-in" data-aos="fade-up">
        <h1><i class="fa-solid fa-screwdriver-wrench"></i> Installer</h1>
        <?php if ($ok): ?>
            <p class="hero-tagline">Setup complete.</p>
        <?php else: ?>
            <p class="hero-tagline">Setup failed.</p>
        <?php endif; ?>
        <ul style="text-align:left; max-width:560px; margin:18px auto 0; line-height:1.9;">
            <?php foreach ($messages as $m): ?>
                <li><?= $m ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($ok): ?>
            <p style="margin-top:18px;">
                <a class="btn btn-primary" href="<?= e(base_url()) ?>/">Go to site</a>
                <a class="btn btn-ghost" href="<?= e(base_url()) ?>/admin.php">Open admin</a>
            </p>
        <?php endif; ?>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
