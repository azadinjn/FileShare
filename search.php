<?php
/**
 * search.php — look up a package by its code.
 * GET  q=<code>        : redirect to download page or render "not found"
 * POST code=<code>     : same, but used by the search form
 */

require_once __DIR__ . '/includes/helpers.php';

try {
    $code = trim($_REQUEST['code'] ?? $_REQUEST['q'] ?? '');
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

    if ($code === '') {
        // No code supplied: just render the search page.
        render_search_page('');
        exit;
    }

    $pkg = find_package($code);

    if ($pkg) {
        header('Location: ' . base_url() . '/download.php?code=' . $code);
        exit;
    }

    render_search_page($code, true);
} catch (Throwable $e) {
    fail($e, 'Search is temporarily unavailable.');
}

/**
 * Look up a non-expired package by code. Returns assoc array or null.
 */
function find_package(string $code): ?array
{
    $stmt = db()->prepare(
        'SELECT id, code, name, expires_at, downloads, created_at
         FROM packages
         WHERE code = ?
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function render_search_page(string $code, bool $notFound = false): void
{
    $siteName  = setting('site_name', APP_NAME) ?? APP_NAME;
    $tagline   = setting('site_tagline', '') ?? '';
    $pageTitle = $notFound ? 'Code not found' : 'Find your files';
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="container narrow search-page">
        <?php if ($notFound): ?>
            <section class="glass error-card anim-in" data-aos="fade-up">
                <div class="error-emoji">🔍</div>
                <h2>Code "<?= e($code) ?>" not found</h2>
                <p>The code you entered doesn't match any active package. It may
                   have expired, been deleted, or contain a typo.</p>
                <a class="btn btn-primary" href="<?= e(base_url()) ?>/search.php">Try again</a>
            </section>
        <?php else: ?>
            <section class="glass hero-card anim-in" data-aos="fade-up">
                <h1>Find your files</h1>
                <p>Enter the package code shared with you to reach the download page.</p>
                <form method="post" action="<?= e(base_url()) ?>/search.php" class="search-form">
                    <?= csrf_field() ?>
                    <div class="field">
                        <input type="text" name="code" placeholder="e.g. AB12CD34EF"
                               autocomplete="off" spellcheck="false"
                               class="mono input-code" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fa-solid fa-magnifying-glass"></i> Find package
                    </button>
                </form>
            </section>
        <?php endif; ?>
    </main>
    <?php
    include __DIR__ . '/includes/footer.php';
}
