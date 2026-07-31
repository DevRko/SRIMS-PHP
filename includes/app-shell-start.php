<?php
/**
 * SRIMS - App shell open (ported from src/app/(dashboard)/layout.tsx)
 * Include AFTER requireLogin() (and any requireRole()) and AFTER header.php.
 * Expects: $user, $currentPath already set by the including page.
 */
$user = $user ?? currentUser();
?>
<div class="min-h-screen bg-surface-app">
<?php include __DIR__ . '/sidebar.php'; ?>
<?php include __DIR__ . '/topbar.php'; ?>
<main class="app-main pt-topbar">
<div class="p-page-padding">
