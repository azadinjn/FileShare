<?php
require_once __DIR__ . '/includes/functions.php';
purge_expired_files();

$maxBytes = get_max_upload_bytes();
$maxLabel = format_size($maxBytes);
$pageTitle = SITE_NAME . ' — Send Files Fast, Free & Secure';
require __DIR__ . '/includes/header.php';
?>

<section class="hero container">
    <div class="hero-text" data-aos="fade-up">
        <h1>Share files.<br><span class="gradient-text">Simply. Securely.</span></h1>
        <p>Drag a file in, get a link out. No sign-up, no clutter — files self-destruct after their expiry window.</p>
    </div>

    <div class="upload-card" data-aos="fade-up" data-aos-delay="100">

        <!-- STEP 1: Drop zone (accepts multiple files) -->
        <div id="dropZone" class="drop-zone">
            <input type="file" id="fileInput" multiple hidden>
            <div class="drop-zone-content">
                <div class="drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <h3>Drag &amp; drop files here</h3>
                <p>or</p>
                <button type="button" class="btn btn-primary" id="browseBtn">Browse Files</button>
                <p class="hint">Up to <?= (int) get_max_files_per_package() ?> files &middot; Max <?= e($maxLabel) ?> per file</p>
            </div>
        </div>

        <!-- STEP 2: Selected files / options -->
        <div id="fileReadyPanel" class="file-ready-panel hidden">
            <div class="file-list-header">
                <span id="fileListCount">0 files selected</span>
                <span id="fileListTotalSize">0 KB total</span>
            </div>

            <div id="fileList" class="file-list"></div>

            <button type="button" class="btn btn-ghost btn-block add-more-btn" id="addMoreBtn">
                <i class="fa-solid fa-plus"></i> Add More Files
            </button>

            <div class="form-group">
                <label for="packageNameInput"><i class="fa-regular fa-pen-to-square"></i> Package name (optional)</label>
                <input type="text" id="packageNameInput" class="text-input" maxlength="255" placeholder="e.g. Project Assets">
            </div>

            <div class="form-group">
                <label for="expirySelect"><i class="fa-regular fa-clock"></i> Expires in</label>
                <select id="expirySelect" class="select-input">
                    <?php foreach (EXPIRY_OPTIONS as $hours => $label): ?>
                        <option value="<?= (int) $hours ?>" <?= $hours == get_default_expiry_hours() ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="button" class="btn btn-primary btn-block" id="uploadBtn">
                <i class="fa-solid fa-paper-plane"></i> Upload &amp; Get Link
            </button>
        </div>

        <!-- STEP 3: Progress (per-file + total) -->
        <div id="progressPanel" class="progress-panel hidden">
            <div class="progress-total">
                <div class="progress-stats">
                    <span><strong>Total progress</strong></span>
                    <span id="progressPercent">0%</span>
                </div>
                <div class="progress-bar-track">
                    <div class="progress-bar-fill" id="progressFill"></div>
                </div>
                <div class="progress-stats">
                    <span id="progressCount">0 / 0 files</span>
                    <span id="progressSpeed">0 KB/s</span>
                </div>
            </div>
            <div id="perFileProgress" class="per-file-progress"></div>
        </div>

        <!-- STEP 4: Result (PACKAGE code/QR/link only — no per-file codes) -->
        <div id="resultPanel" class="result-panel hidden">
            <div class="result-success-icon"><i class="fa-solid fa-circle-check"></i></div>
            <h3 id="resultHeading">Your package is ready to share!</h3>
            <p class="muted" id="resultSubheading">0 files &middot; 0 KB</p>

            <div id="qrcode" class="qr-box"></div>

            <div class="result-code">
                <span id="resultCode">ABCD1234</span>
                <button type="button" class="icon-btn" id="copyCodeBtn" title="Copy code"><i class="fa-regular fa-copy"></i></button>
            </div>

            <div class="result-link-row">
                <input type="text" id="resultLink" readonly>
                <button type="button" class="btn btn-secondary" id="copyLinkBtn"><i class="fa-regular fa-copy"></i> Copy Link</button>
            </div>

            <div class="result-actions">
                <a href="#" class="btn btn-primary" id="downloadNowBtn" target="_blank"><i class="fa-solid fa-eye"></i> View Package</a>
                <button type="button" class="btn btn-secondary" id="shareBtn"><i class="fa-solid fa-share-nodes"></i> Share</button>
                <button type="button" class="btn btn-ghost" id="uploadAnotherBtn"><i class="fa-solid fa-plus"></i> Upload Another</button>
            </div>
        </div>
    </div>
</section>

<section class="features container" data-aos="fade-up">
    <div class="feature-card">
        <i class="fa-solid fa-shield-halved"></i>
        <h4>Secure by Design</h4>
        <p>Every upload is validated, sanitized, and stored with a randomized filename.</p>
    </div>
    <div class="feature-card">
        <i class="fa-solid fa-bolt"></i>
        <h4>Fast Transfers</h4>
        <p>Real-time progress and speed so you always know what's happening.</p>
    </div>
    <div class="feature-card">
        <i class="fa-solid fa-hourglass-half"></i>
        <h4>Auto-Expiring</h4>
        <p>Files disappear automatically after 24 hours or 7 days — your choice.</p>
    </div>
    <div class="feature-card">
        <i class="fa-solid fa-qrcode"></i>
        <h4>QR Code Sharing</h4>
        <p>Every link comes with a scannable QR code for quick mobile access.</p>
    </div>
</section>

<script>window.APP_CONFIG = { maxUploadBytes: <?= (int) $maxBytes ?>, maxFiles: <?= (int) get_max_files_per_package() ?>, siteUrl: '<?= e(SITE_URL) ?>', csrfToken: '<?= e(csrf_token()) ?>' };</script>
<script src="/assets/js/upload.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
