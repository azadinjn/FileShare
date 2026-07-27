<?php
/**
 * header.php — shared <head> + top navigation. Expects $pageTitle and
 * $siteName to be set by the including script.
 */
$siteName  = $siteName  ?? (setting('site_name', APP_NAME) ?: APP_NAME);
$pageTitle = $pageTitle ?? $siteName;
$baseUrl   = base_url();
$inMaint   = setting('maintenance') === '1';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= e($pageTitle) ?> · <?= e($siteName) ?></title>

    <link rel="icon" href="<?= e($baseUrl) ?>/assets/img/favicon.svg" type="image/svg+xml">

    <!-- Font Awesome -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
          referrerpolicy="no-referrer">

    <!-- AOS animations -->
    <link rel="stylesheet"
          href="https://unpkg.com/aos@2.3.4/dist/aos.css"
          referrerpolicy="no-referrer">

    <link rel="stylesheet" href="<?= e($baseUrl) ?>/assets/css/style.css">
</head>
<body>
<?php if ($inMaint): ?>
    <div class="maint-banner"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance mode is on — uploads are paused for visitors.</div>
<?php endif; ?>

<header class="site-header glass">
    <div class="container header-inner">
        <a class="brand" href="<?= e($baseUrl) ?>/">
            <span class="brand-mark"><i class="fa-solid fa-cube"></i></span>
            <span class="brand-text"><?= e($siteName) ?></span>
        </a>
        <nav class="site-nav">
            <a href="<?= e($baseUrl) ?>/">Upload</a>
            <a href="<?= e($baseUrl) ?>/search.php">Search</a>
        </nav>
    </div>
</header>
