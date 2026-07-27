        <!-- footer.php — shared footer. $siteName is provided by header.php -->
        <footer class="site-footer">
            <div class="container footer-inner">
                <span><?= e($siteName ?? 'FileVault') ?> · v<?= e(APP_VERSION) ?></span>
                <span><?= e(setting('footer_text', '') ?? '') ?></span>
            </div>
        </footer>

        <!-- AOS init + page JS modules -->
        <script src="https://unpkg.com/aos@2.3.4/dist/aos.js" referrerpolicy="no-referrer"></script>
        <script>AOS.init({ duration: 600, once: true, offset: 60 });</script>

        <!-- QR code library: qr-code-styling (maintained, scannable on iOS/Android) -->
        <script src="https://unpkg.com/qr-code-styling@1.6.0-rc.1/lib/qr-code-styling.js"
                referrerpolicy="no-referrer"></script>

        <script src="<?= e(base_url()) ?>/assets/js/app.js"></script>
    </body>
</html>
