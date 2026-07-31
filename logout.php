<?php
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    $user = currentUser();
    addAuditLog($pdo, $user['id'], 'LOGOUT', 'User', $user['id'], "User {$user['name']} logged out");
}

logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
