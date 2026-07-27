<?php

/**
 * index.php — landing / upload page.
 */

require_once __DIR__ . '/includes/helpers.php';

$siteName = setting('site_name', APP_NAME) ?? APP_NAME;
$tagline  = setting('site_tagline', '') ?? '';
$allowUp  = setting('allow_uploads', '1') === '1';
$inMaint  = setting('maintenance') === '1';

if ($inMaint) {
    $pageTitle = 'Maintenance';
    include __DIR__ . '/includes/header.php';
    ?>
    <main class="container narrow">
        <section class="glass hero-card anim-in" data-aos="fade-up">
            <h1><i class="fa-solid fa-screwdriver-wrench"></i> Under maintenance</h1>
            <p>The site is temporarily unavailable. Please check back soon.</p>
        </section>
    </main>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = 'Upload & share files';
include __DIR__ . '/includes/header.php';
?>
<main class="container upload-page">

    <section class="glass hero-card anim-in" data-aos="fade-up">
        <h1><?= e($siteName) ?></h1>
        <p class="hero-tagline"><?= e($tagline) ?></p>
    </section>

    <?php if (!$allowUp): ?>
        <section class="glass info-card anim-in" data-aos="fade-up">
            <h2><i class="fa-solid fa-circle-info"></i> Uploads paused</h2>
            <p>Uploading is currently disabled by the administrator.</p>
        </section>
    <?php else: ?>
        <section class="glass uploader anim-in" data-aos="fade-up" data-aos-delay="80">
            <form id="uploadForm" class="uploader-form">
                <?= csrf_field() ?>

                <div class="form-grid">
                    <label class="field">
                        <span class="field-label">Package name</span>
                        <input type="text" id="packageName" name="package_name"
                               maxlength="120" placeholder="My project files" autocomplete="off">
                    </label>
                    <label class="field">
                        <span class="field-label">Expires (optional)</span>
                        <input type="datetime-local" id="packageExpiry" name="expires_at">
                    </label>
                </div>

                <div id="dropZone" class="dropzone" tabindex="0" role="button"
                     aria-label="Choose or drop files to upload">
                    <i class="fa-solid fa-cloud-arrow-up drop-icon"></i>
                    <div class="drop-title">Drag &amp; drop files here</div>
                    <div class="drop-sub">or <span class="linkish">browse your device</span> — any file type, multiple files OK</div>
                    <input type="file" id="fileInput" multiple hidden>
                </div>

                <ul id="fileQueue" class="file-queue" hidden></ul>

                <div class="uploader-actions">
                    <button type="submit" id="uploadBtn" class="btn btn-primary btn-lg"
                            disabled>
                        <i class="fa-solid fa-rocket"></i> Upload &amp; share
                    </button>
                </div>
            </form>
        </section>

        <section id="resultCard" class="glass result-card" hidden data-aos="fade-up">
            <div class="result-head">
                <i class="fa-solid fa-circle-check result-check"></i>
                <div>
                    <h2>Your package is ready</h2>
                    <p>Share the code or link below — anyone can download it.</p>
                </div>
            </div>
            <div class="result-code-row">
                <code id="resultCode" class="mono code-pill"></code>
                <a id="resultLink" class="btn btn-primary" href="#">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open
                </a>
            </div>
        </section>

        <section class="features anim-in" data-aos="fade-up" data-aos-delay="160">
            <div class="glass feature"><i class="fa-solid fa-bolt"></i><h3>Instant</h3><p>Get a shareable code the moment your upload finishes.</p></div>
            <div class="glass feature"><i class="fa-solid fa-shield-halved"></i><h3>Safe</h3><p>Files are stored with random names and served as forced downloads.</p></div>
            <div class="glass feature"><i class="fa-solid fa-qrcode"></i><h3>QR ready</h3><p>Every package page shows a scannable QR code for phones.</p></div>
            <div class="glass feature"><i class="fa-solid fa-file-zipper"></i><h3>Bulk ZIP</h3><p>Download all files as one ZIP, or pick them individually.</p></div>
        </section>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
