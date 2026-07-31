<?php
/**
 * SRIMS - Root redirect (ported 1:1 from src/app/page.tsx)
 * Not logged in            -> /login.php  (dashboard.php would do this
 *                              anyway via requireLogin(), but we redirect
 *                              straight there to avoid an extra hop)
 * Logged in, role = USER   -> /requisitions/my.php
 * Logged in, any other role -> /dashboard.php
 */
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user = currentUser();
if ($user['role'] === 'USER') {
    header('Location: ' . BASE_URL . '/requisitions/my.php');
} else {
    header('Location: ' . BASE_URL . '/dashboard.php');
}
exit;
