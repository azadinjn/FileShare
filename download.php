<?php
/**
 * download.php — package download page.
 *
 * GET  ?code=XXXX            -> render the package page (with QR + file list)
 * GET  ?code=XXXX&zip=1      -> stream "all files" as a ZIP
 * GET  ?code=XXXX&file=N     -> stream a single file
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/zip.php';

try {
    if (setting('maintenance') === '1') {
        http_response_code(503);
        include __DIR__ . '/includes/header.php';
        echo '<main class="container narrow"><section class="glass hero-card">'
           . '<h1>Under maintenance</h1><p>The site will be back shortly.</p></section></main>';
        include __DIR__ . '/includes/footer.php';
        exit;
    }

    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['code'] ?? ''));

    if ($code === '') {
        header('Location: ' . base_url() . '/search.php');
        exit;
    }

    $pkg = fetch_package($code);
    if (!$pkg) {
        render_not_found($code);
        exit;
    }

    // Single file download?
    if (isset($_GET['file'])) {
        stream_single_file($pkg, (int) $_GET['file']);
        exit;
    }

    // Whole-package ZIP?
    if (isset($_GET['zip'])) {
        stream_package_zip($pkg);
        exit;
    }

    render_package_page($pkg);
} catch (Throwable $e) {
    fail($e, 'Could not load this package.');
}

// ======================================================================

function fetch_package(string $code): ?array
{
    $stmt = db()->prepare(
        'SELECT id, code, name, expires_at, downloads, created_at
         FROM packages
         WHERE code = ?
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $pkg = $stmt->fetch() ?: null;
    if (!$pkg) return null;

    // Expired? Hide it.
    if ($pkg['expires_at'] !== null && strtotime($pkg['expires_at']) < time()) {
        return null;
    }

    $fstmt = db()->prepare(
        'SELECT id, original_name, stored_name, mime, size, downloads
         FROM files WHERE package_id = ? ORDER BY id'
    );
    $fstmt->execute([$pkg['id']]);
    $pkg['files'] = $fstmt->fetchAll();

    return $pkg;
}

function render_not_found(string $code): void
{
    $siteName = setting('site_name', APP_NAME) ?? APP_NAME;
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="container narrow search-page">
        <section class="glass error-card anim-in" data-aos="fade-up">
            <div class="error-emoji">🗂️</div>
            <h2>Package "<?= e($code) ?>" not found</h2>
            <p>This package may have expired or been removed.</p>
            <a class="btn btn-primary" href="<?= e(base_url()) ?>/search.php">Search again</a>
        </section>
    </main>
    <?php
    include __DIR__ . '/includes/footer.php';
}

function render_package_page(array $pkg): void
{
    $totalSize = 0;
    foreach ($pkg['files'] as $f) { $totalSize += (int) $f['size']; }
    $fileCount = count($pkg['files']);
    $dlUrl = base_url() . '/download.php?code=' . $pkg['code'];

    include __DIR__ . '/includes/header.php';
    ?>
    <main class="container package-page">
        <section class="glass package-head anim-in" data-aos="fade-up">
            <div class="pkg-info">
                <h1><?= e($pkg['name']) ?></h1>
                <ul class="pkg-meta">
                    <li><i class="fa-solid fa-file"></i> <?= $fileCount ?> file<?= $fileCount === 1 ? '' : 's' ?></li>
                    <li><i class="fa-solid fa-database"></i> <?= e(human_size($totalSize)) ?></li>
                    <li><i class="fa-solid fa-clock"></i> <?= e(time_ago($pkg['created_at'])) ?></li>
                    <li><i class="fa-solid fa-arrow-down"></i> <?= (int) $pkg['downloads'] ?> downloads</li>
                    <?php if ($pkg['expires_at']): ?>
                        <li><i class="fa-solid fa-hourglass-half"></i> expires <?= e(date('M j, Y', strtotime($pkg['expires_at']))) ?></li>
                    <?php else: ?>
                        <li><i class="fa-solid fa-infinity"></i> no expiration</li>
                    <?php endif; ?>
                </ul>

                <div class="code-row">
                    <span class="code-label">Download code</span>
                    <code class="mono code-pill" id="packageCode"><?= e($pkg['code']) ?></code>
                </div>

                <div class="share-row">
                    <button class="btn btn-ghost" id="copyLinkBtn" data-url="<?= e($dlUrl) ?>">
                        <i class="fa-solid fa-link"></i> Copy link
                    </button>
                    <button class="btn btn-ghost" id="copyCodeBtn" data-code="<?= e($pkg['code']) ?>">
                        <i class="fa-solid fa-copy"></i> Copy code
                    </button>
                    <button class="btn btn-ghost" id="shareBtn" data-url="<?= e($dlUrl) ?>" data-title="<?= e($pkg['name']) ?>">
                        <i class="fa-solid fa-share-nodes"></i> Share
                    </button>
                    <a class="btn btn-primary btn-lg" href="<?= e($dlUrl) ?>&zip=1">
                        <i class="fa-solid fa-file-zipper"></i> Download all (ZIP)
                    </a>
                </div>
            </div>

            <div class="qr-wrap">
                <div id="qrcode"></div>
                <p class="qr-hint">Scan to open on any phone</p>
            </div>
        </section>

        <section class="file-list anim-in" data-aos="fade-up" data-aos-delay="100">
            <h2><i class="fa-solid fa-folder-open"></i> Files</h2>
            <ul class="files">
                <?php foreach ($pkg['files'] as $f):
                    $icon = file_icon($f['original_name']);
                    $url  = $dlUrl . '&file=' . (int) $f['id'];
                ?>
                    <li class="glass file-row">
                        <div class="file-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                        <div class="file-meta">
                            <div class="file-name"><?= e($f['original_name']) ?></div>
                            <div class="file-sub">
                                <?= e(human_size($f['size'])) ?> · <?= (int) $f['downloads'] ?> downloads
                            </div>
                        </div>
                        <a class="btn btn-primary" href="<?= e($url) ?>">
                            <i class="fa-solid fa-download"></i>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </main>

    <script>
        window.PACKAGE_DOWNLOAD_URL = <?= json_encode($dlUrl) ?>;
        window.PACKAGE_CODE = <?= json_encode($pkg['code']) ?>;
    </script>
    <?php
    include __DIR__ . '/includes/footer.php';
}

function stream_single_file(array $pkg, int $fileId): void
{
    foreach ($pkg['files'] as $f) {
        if ((int) $f['id'] === $fileId) {
            $path = safe_upload_path($f['stored_name']);
            if ($path === null || !is_file($path)) {
                http_response_code(404);
                echo 'File missing.';
                exit;
            }
            bump_package_downloads($pkg['id']);
            bump_file_downloads($fileId);

            $dlName = $f['original_name'];
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $f['mime']);
            header('Content-Disposition: attachment; filename="'
                . rawurlencode($dlName) . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store');

            $fp = fopen($path, 'rb');
            while (!feof($fp)) {
                echo fread($fp, 1048576);
                flush();
            }
            fclose($fp);
            exit;
        }
    }
    http_response_code(404);
    echo 'File not part of this package.';
    exit;
}

function stream_package_zip(array $pkg): void
{
    $files = [];
    foreach ($pkg['files'] as $f) {
        $path = safe_upload_path($f['stored_name']);
        if ($path !== null && is_file($path)) {
            // De-duplicate names inside the ZIP.
            $files[] = ['path' => $path, 'name' => unique_zip_name($files, $f['original_name'])];
        }
    }
    if (!$files) {
        http_response_code(404);
        echo 'No files available.';
        exit;
    }

    $tmp = TEMP_DIR . '/pkg_' . $pkg['code'] . '_' . bin2hex(random_bytes(4)) . '.zip';
    if (!create_zip($files, $tmp)) {
        @unlink($tmp);
        http_response_code(500);
        echo 'Failed to build ZIP.';
        exit;
    }

    bump_package_downloads($pkg['id']);
    $zipName = preg_replace('/[^\w\.\-]+/', '_', $pkg['name']) . '_' . $pkg['code'] . '.zip';
    download_zip($tmp, $zipName);
}

function unique_zip_name(array $existing, string $name): string
{
    $used = [];
    foreach ($existing as $e) { $used[$e['name']] = true; }
    if (!isset($used[$name])) return $name;
    $i = 1;
    $pi = pathinfo($name);
    $base = $pi['filename'] ?? $name;
    $ext  = isset($pi['extension']) ? '.' . $pi['extension'] : '';
    while (true) {
        $cand = $base . " ($i)" . $ext;
        if (!isset($used[$cand])) return $cand;
        $i++;
    }
}

function bump_package_downloads(int $pkgId): void
{
    db()->prepare('UPDATE packages SET downloads = downloads + 1 WHERE id = ?')
        ->execute([$pkgId]);
}
function bump_file_downloads(int $fileId): void
{
    db()->prepare('UPDATE files SET downloads = downloads + 1 WHERE id = ?')
        ->execute([$fileId]);
}

function file_icon(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return [
        'zip'  => 'fa-file-zipper',
        'rar'  => 'fa-file-zipper',
        '7z'   => 'fa-file-zipper',
        'pdf'  => 'fa-file-pdf',
        'doc'  => 'fa-file-word',
        'docx' => 'fa-file-word',
        'xls'  => 'fa-file-excel',
        'xlsx' => 'fa-file-excel',
        'ppt'  => 'fa-file-powerpoint',
        'pptx' => 'fa-file-powerpoint',
        'jpg'  => 'fa-file-image',
        'jpeg' => 'fa-file-image',
        'png'  => 'fa-file-image',
        'gif'  => 'fa-file-image',
        'mp4'  => 'fa-file-video',
        'mp3'  => 'fa-file-audio',
        'exe'  => 'fa-gear',
        'apk'  => 'fa-mobile-screen',
        'php'  => 'fa-code',
        'js'   => 'fa-code',
        'html' => 'fa-code',
        'css'  => 'fa-code',
    ][$ext] ?? 'fa-file';
}
