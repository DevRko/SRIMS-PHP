<?php
/**
 * SRIMS - App bootstrap
 * Include this file at the very top of every page (before any HTML output).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/dbcon.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// BASE_URL = the web-accessible path to the project root (this file's
// folder), so every page — no matter how deeply nested — can build correct
// links/asset URLs with BASE_URL . '/dashboard.php', BASE_URL . '/assets/...'
if (!defined('BASE_URL')) {
    $projectRootFs = str_replace('\\', '/', __DIR__);
    $docRoot       = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot !== '' && strpos($projectRootFs, $docRoot) === 0) {
        $base = substr($projectRootFs, strlen($docRoot));
    } else {
        $base = '';
    }
    define('BASE_URL', $base);
}

date_default_timezone_set('Asia/Kolkata');
