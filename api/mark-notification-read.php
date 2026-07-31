<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$id = $_POST['id'] ?? '';
if ($id === '') { echo json_encode(['ok' => false]); exit; }

$stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
$stmt->execute([$id, currentUser()['id']]);

echo json_encode(['ok' => true]);
