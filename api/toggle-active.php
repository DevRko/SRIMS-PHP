<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit; }

$currentUser = currentUser();
$entity = $_POST['entity'] ?? ''; // 'item' | 'user'
$id = $_POST['id'] ?? '';

if ($entity === 'item') {
    if (!in_array($currentUser['role'], ['ADMIN', 'INVENTORY_MGR'], true)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

    $stmt = $pdo->prepare('SELECT id, name, is_active FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if (!$item) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Item not found']); exit; }

    $newStatus = $item['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE items SET is_active = ? WHERE id = ?')->execute([$newStatus, $id]);
    addAuditLog($pdo, $currentUser['id'], 'UPDATE', 'Item', $id, ($newStatus ? 'Activated' : 'Deactivated') . " item {$item['name']}");

    echo json_encode(['ok' => true, 'is_active' => (bool) $newStatus]);
    exit;
}

if ($entity === 'user') {
    if ($currentUser['role'] !== 'ADMIN') { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }
    if ($id === $currentUser['id']) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'You cannot deactivate your own account']); exit; }

    $stmt = $pdo->prepare('SELECT id, name, is_active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'User not found']); exit; }

    $newStatus = $target['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$newStatus, $id]);
    addAuditLog($pdo, $currentUser['id'], 'UPDATE', 'User', $id, ($newStatus ? 'Activated' : 'Deactivated') . ' user account');
    addNotificationToRoles($pdo, ['ADMIN'], "User {$target['name']} has been " . ($newStatus ? 'activated' : 'deactivated'), '/masters/users');

    echo json_encode(['ok' => true, 'is_active' => (bool) $newStatus, 'name' => $target['name']]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown entity']);