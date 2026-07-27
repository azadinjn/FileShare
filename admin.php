<?php
/**
 * admin.php — admin dashboard, package management, settings, logs.
 *
 * URL params:
 *   ?p=packages       list / delete packages and files
 *   ?p=settings       edit site settings + maintenance mode
 *   ?p=logs           view recent log entries
 *   ?p=account        change admin password
 *   default           dashboard with stats
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

require_admin();

$page = preg_replace('/[^a-z]/', '', $_GET['p'] ?? '');
$page = $page === '' ? 'dashboard' : $page;

$siteName = setting('site_name', APP_NAME) ?? APP_NAME;
$pageTitle = 'Admin · ' . ucfirst($page);
$baseUrl  = base_url();

// ----- POST actions --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_admin_post($page);
}

include __DIR__ . '/includes/header.php';
?>
<main class="container admin-wrap">

    <nav class="admin-tabs glass anim-in" data-aos="fade-up">
        <a href="<?= e($baseUrl) ?>/admin.php"           class="<?= $page==='dashboard'?'active':''?>">Dashboard</a>
        <a href="<?= e($baseUrl) ?>/admin.php?p=packages" class="<?= $page==='packages'?'active':''?>">Packages</a>
        <a href="<?= e($baseUrl) ?>/admin.php?p=settings" class="<?= $page==='settings'?'active':''?>">Settings</a>
        <a href="<?= e($baseUrl) ?>/admin.php?p=logs"     class="<?= $page==='logs'?'active':''?>">Logs</a>
        <a href="<?= e($baseUrl) ?>/admin.php?p=account"  class="<?= $page==='account'?'active':''?>">Account</a>
        <a href="<?= e($baseUrl) ?>/logout.php" class="tab-spacer">Logout</a>
    </nav>

    <?php render_flash_inline(); ?>

    <?php
    switch ($page) {
        case 'packages': render_packages(); break;
        case 'settings': render_settings(); break;
        case 'logs':     render_logs();     break;
        case 'account':  render_account();  break;
        default:         render_dashboard();
    }
    ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
// ======================================================================
//  POST handling
// ======================================================================
function handle_admin_post(string $page): void
{
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        flash('Security token expired. Please retry.', 'error');
        return;
    }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'delete_package')  admin_delete_package();
        elseif ($action === 'delete_file') admin_delete_file();
        elseif ($action === 'save_settings') admin_save_settings();
        elseif ($action === 'change_password') admin_change_password();
        else { flash('Unknown action.', 'error'); }
    } catch (Throwable $e) {
        error_log('admin action: ' . $e->getMessage());
        flash('Action failed: ' . $e->getMessage(), 'error');
    }
    // Redirect to avoid resubmit.
    $back = $_POST['back'] ?? ('admin.php?p=' . $page);
    header('Location: ' . base_url() . '/' . $back);
    exit;
}

function admin_delete_package(): void
{
    $id = (int) ($_POST['package_id'] ?? 0);
    if ($id <= 0) { flash('Invalid package.', 'error'); return; }
    // Fetch files so we can delete them from disk.
    $fstmt = db()->prepare('SELECT stored_name FROM files WHERE package_id = ?');
    $fstmt->execute([$id]);
    foreach ($fstmt->fetchAll() as $f) {
        $path = safe_upload_path($f['stored_name']);
        if ($path !== null && is_file($path)) @unlink($path);
    }
    $del = db()->prepare('DELETE FROM packages WHERE id = ?');
    $del->execute([$id]); // FK CASCADE removes files rows
    log_event('info', 'Package deleted', "id=$id");
    flash('Package deleted.');
}

function admin_delete_file(): void
{
    $id = (int) ($_POST['file_id'] ?? 0);
    if ($id <= 0) { flash('Invalid file.', 'error'); return; }
    $stmt = db()->prepare('SELECT id, stored_name, package_id FROM files WHERE id = ?');
    $stmt->execute([$id]);
    $f = $stmt->fetch();
    if (!$f) { flash('File not found.', 'error'); return; }
    $path = safe_upload_path($f['stored_name']);
    if ($path !== null && is_file($path)) @unlink($path);
    db()->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
    log_event('info', 'File deleted', "id=$id");
    flash('File deleted.');
}

function admin_save_settings(): void
{
    $fields = [
        'site_name'     => trim($_POST['site_name']     ?? APP_NAME),
        'site_tagline'  => trim($_POST['site_tagline']  ?? ''),
        'footer_text'   => trim($_POST['footer_text']   ?? ''),
        'max_upload_mb' => (string) max(1, (int) ($_POST['max_upload_mb'] ?? 500)),
        'allow_uploads' => isset($_POST['allow_uploads']) ? '1' : '0',
        'maintenance'   => isset($_POST['maintenance'])   ? '1' : '0',
    ];
    foreach ($fields as $k => $v) {
        set_setting($k, $v);
    }
    log_event('info', 'Settings updated', json_encode(array_keys($fields)));
    flash('Settings saved.');
}

function admin_change_password(): void
{
    safe_session_start();
    $aid = (int) ($_SESSION['admin_id'] ?? 0);
    $cur = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $cnf = (string) ($_POST['confirm_password'] ?? '');

    if ($new === '' || strlen($new) < 6) { flash('New password must be at least 6 characters.', 'error'); return; }
    if ($new !== $cnf) { flash('New passwords do not match.', 'error'); return; }

    $stmt = db()->prepare('SELECT password FROM admins WHERE id = ?');
    $stmt->execute([$aid]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($cur, $row['password'])) {
        flash('Current password is incorrect.', 'error');
        return;
    }
    $hash = password_hash($new, PASSWORD_DEFAULT);
    db()->prepare('UPDATE admins SET password = ? WHERE id = ?')->execute([$hash, $aid]);
    log_event('info', 'Admin password changed', "id=$aid");
    flash('Password updated.');
}

// ======================================================================
//  Views
// ======================================================================
function render_dashboard(): void
{
    $pdo = db();
    $pkgCount   = (int) $pdo->query('SELECT COUNT(*) FROM packages')->fetchColumn();
    $fileCount  = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();
    $dlTotal    = (int) $pdo->query('SELECT COALESCE(SUM(downloads),0) FROM packages')->fetchColumn();
    $sizeTotal  = (int) $pdo->query('SELECT COALESCE(SUM(size),0) FROM files')->fetchColumn();
    $recent     = $pdo->query('SELECT id, code, name, created_at FROM packages ORDER BY id DESC LIMIT 6')->fetchAll();

    ?>
    <section class="glass admin-card anim-in" data-aos="fade-up">
        <h1><i class="fa-solid fa-gauge"></i> Dashboard</h1>
        <div class="stats-grid">
            <div class="stat"><div class="stat-num"><?= $pkgCount ?></div><div class="stat-lbl">Packages</div></div>
            <div class="stat"><div class="stat-num"><?= $fileCount ?></div><div class="stat-lbl">Files</div></div>
            <div class="stat"><div class="stat-num"><?= $dlTotal ?></div><div class="stat-lbl">Downloads</div></div>
            <div class="stat"><div class="stat-num"><?= e(human_size($sizeTotal)) ?></div><div class="stat-lbl">Storage used</div></div>
        </div>
    </section>

    <section class="glass admin-card anim-in" data-aos="fade-up" data-aos-delay="80">
        <h2>Recent packages</h2>
        <?php if (!$recent): ?>
            <p class="muted">No packages yet.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead><tr><th>Code</th><th>Name</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><code class="mono"><?= e($r['code']) ?></code></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= e(time_ago($r['created_at'])) ?></td>
                        <td><a class="btn btn-ghost btn-sm" href="<?= e(base_url()) ?>/download.php?code=<?= e($r['code']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
    <?php
}

function render_packages(): void
{
    $search = trim($_GET['q'] ?? '');
    $sql = 'SELECT id, code, name, expires_at, downloads, created_at FROM packages';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE code LIKE ? OR name LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like];
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    ?>
    <section class="glass admin-card anim-in" data-aos="fade-up">
        <h1><i class="fa-solid fa-box-open"></i> Packages</h1>
        <form class="inline-form" method="get">
            <input type="hidden" name="p" value="packages">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search code or name">
            <button class="btn btn-ghost btn-sm"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>

        <?php if (!$rows): ?>
            <p class="muted">No packages found.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead><tr><th>Code</th><th>Name</th><th>Files</th><th>Downloads</th><th>Expires</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $cnt = (int) db()->query('SELECT COUNT(*) FROM files WHERE package_id=' . (int)$r['id'])->fetchColumn();
                ?>
                    <tr>
                        <td><code class="mono"><?= e($r['code']) ?></code></td>
                        <td><?= e($r['name']) ?></td>
                        <td><?= $cnt ?></td>
                        <td><?= (int)$r['downloads'] ?></td>
                        <td><?= $r['expires_at'] ? e(date('Y-m-d', strtotime($r['expires_at']))) : '—' ?></td>
                        <td><?= e(time_ago($r['created_at'])) ?></td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-sm" href="<?= e(base_url()) ?>/download.php?code=<?= e($r['code']) ?>">View</a>
                            <form method="post" action="<?= e(base_url()) ?>/admin.php?p=packages"
                                  onsubmit="return confirm('Delete this package and all its files?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_package">
                                <input type="hidden" name="package_id" value="<?= (int)$r['id'] ?>">
                                <input type="hidden" name="back" value="admin.php?p=packages">
                                <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php
    // Optional: file-level view for one package.
    $viewPkg = (int) ($_GET['view'] ?? 0);
    if ($viewPkg > 0):
        $pstmt = db()->prepare('SELECT id, code, name FROM packages WHERE id = ?');
        $pstmt->execute([$viewPkg]);
        $pkg = $pstmt->fetch();
        if ($pkg):
            $fstmt = db()->prepare('SELECT id, original_name, size, downloads FROM files WHERE package_id = ? ORDER BY id');
            $fstmt->execute([$pkg['id']]);
            $files = $fstmt->fetchAll();
    ?>
    <section class="glass admin-card anim-in" data-aos="fade-up">
        <h2>Files in "<?= e($pkg['name']) ?>" <code class="mono">(<?= e($pkg['code']) ?>)</code></h2>
        <?php if (!$files): ?><p class="muted">No files.</p><?php else: ?>
        <table class="admin-table">
            <thead><tr><th>Name</th><th>Size</th><th>Downloads</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($files as $f): ?>
                <tr>
                    <td><?= e($f['original_name']) ?></td>
                    <td><?= e(human_size($f['size'])) ?></td>
                    <td><?= (int)$f['downloads'] ?></td>
                    <td>
                        <form method="post" action="<?= e(base_url()) ?>/admin.php?p=packages&view=<?= (int)$pkg['id'] ?>"
                              onsubmit="return confirm('Delete this file?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_file">
                            <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                            <input type="hidden" name="back" value="admin.php?p=packages&view=<?= (int)$pkg['id'] ?>">
                            <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
    <?php
        endif; // if ($pkg)
    endif; // if ($viewPkg > 0)
}

function render_settings(): void
{
    $vals = [
        'site_name'     => setting('site_name', APP_NAME) ?? APP_NAME,
        'site_tagline'  => setting('site_tagline', '') ?? '',
        'footer_text'   => setting('footer_text', '') ?? '',
        'max_upload_mb' => setting('max_upload_mb', '500') ?? '500',
        'allow_uploads' => setting('allow_uploads', '1') === '1',
        'maintenance'   => setting('maintenance', '0') === '1',
    ];
    ?>
    <section class="glass admin-card anim-in" data-aos="fade-up">
        <h1><i class="fa-solid fa-sliders"></i> Site settings</h1>
        <form method="post" action="<?= e(base_url()) ?>/admin.php?p=settings" class="settings-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="back" value="admin.php?p=settings">

            <div class="form-grid">
                <label class="field">
                    <span class="field-label">Site name</span>
                    <input type="text" name="site_name" value="<?= e($vals['site_name']) ?>" maxlength="64">
                </label>
                <label class="field">
                    <span class="field-label">Tagline</span>
                    <input type="text" name="site_tagline" value="<?= e($vals['site_tagline']) ?>" maxlength="160">
                </label>
                <label class="field">
                    <span class="field-label">Footer text</span>
                    <input type="text" name="footer_text" value="<?= e($vals['footer_text']) ?>" maxlength="160">
                </label>
                <label class="field">
                    <span class="field-label">Max upload (MB)</span>
                    <input type="number" name="max_upload_mb" value="<?= e($vals['max_upload_mb']) ?>" min="1" max="2048">
                </label>
            </div>

            <label class="check-row">
                <input type="checkbox" name="allow_uploads" <?= $vals['allow_uploads']?'checked':'' ?>>
                <span>Allow uploads</span>
            </label>
            <label class="check-row">
                <input type="checkbox" name="maintenance" <?= $vals['maintenance']?'checked':'' ?>>
                <span>Maintenance mode (pauses uploads & download page for visitors)</span>
            </label>

            <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
        </form>
    </section>
    <?php
}

function render_logs(): void
{
    $stmt = db()->query('SELECT id, level, message, context, ip, created_at
                         FROM logs ORDER BY id DESC LIMIT 200');
    $rows = $stmt->fetchAll();
    ?>
    <section class="glass admin-card anim-in" data-aos="fade-up">
        <h1><i class="fa-solid fa-list-ul"></i> Logs</h1>
        <?php if (!$rows): ?><p class="muted">No log entries yet.</p><?php else: ?>
        <table class="admin-table log-table">
            <thead><tr><th>Time</th><th>Level</th><th>Message</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="log-<?= e($r['level']) ?>">
                    <td><?= e(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
                    <td><span class="badge level-<?= e($r['level']) ?>"><?= e($r['level']) ?></span></td>
                    <td><?= e($r['message']) ?><?= $r['context'] ? ' <span class="muted">· '.e($r['context']).'</span>' : '' ?></td>
                    <td><?= e($r['ip'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
    <?php
}

function render_account(): void
{
    safe_session_start();
    $user = $_SESSION['admin_user'] ?? 'admin';
    ?>
    <section class="glass admin-card anim-in" data-aos="fade-up">
        <h1><i class="fa-solid fa-user-shield"></i> Account</h1>
        <p class="muted">Signed in as <strong><?= e($user) ?></strong></p>
        <form method="post" action="<?= e(base_url()) ?>/admin.php?p=account" class="settings-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="back" value="admin.php?p=account">
            <label class="field">
                <span class="field-label">Current password</span>
                <input type="password" name="current_password" required>
            </label>
            <label class="field">
                <span class="field-label">New password (min 6 chars)</span>
                <input type="password" name="new_password" required minlength="6">
            </label>
            <label class="field">
                <span class="field-label">Confirm new password</span>
                <input type="password" name="confirm_password" required minlength="6">
            </label>
            <button class="btn btn-primary"><i class="fa-solid fa-key"></i> Update password</button>
        </form>
    </section>
    <?php
}

function flash(?string $msg = null, string $type = 'success'): void
{
    if ($msg === null) {
        if (session_status() !== PHP_SESSION_ACTIVE) safe_session_start();
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return; // handled inline below
    }
    safe_session_start();
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
// Render flash inline right after tabs.
function render_flash_inline(): void
{
    safe_session_start();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if ($f):
        $cls = $f['type'] === 'error' ? 'alert-error' : 'alert-success';
        echo '<div class="alert ' . $cls . ' anim-in" data-aos="fade-up"><i class="fa-solid '
           . ($f['type'] === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check')
           . '"></i> ' . e($f['msg']) . '</div>';
    endif;
}
// Prepend flash before tabs by buffering in handle — simpler: emit here.
