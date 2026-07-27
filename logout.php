<?php
/**
 * logout.php — destroy the admin session and return home.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

logout_admin();
header('Location: ' . base_url() . '/');
exit;
