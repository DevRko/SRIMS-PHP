<?php
/**
 * SRIMS - Cart AJAX endpoint (Step 1 of New Requisition)
 * Mutates $_SESSION['req_cart'] and returns the freshly-rendered cart
 * panel HTML (via renderCartPanelBody, shared with new.php) so the page
 * never has to reload while building a requisition.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cart-panel.php';
requireLogin();
header('Content-Type: application/json');

$cart = $_SESSION['req_cart'] ?? [];
$action = $_POST['action'] ?? '';
$itemId = $_POST['item_id'] ?? '';

if ($action === 'add') {
    $stmt = $pdo->prepare('SELECT i.*, c.name AS category_name FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    if ($item && !isset($cart[$itemId])) {
        $cart[$itemId] = [
            'itemName' => $item['name'], 'categoryName' => $item['category_name'],
            'unit' => $item['unit'], 'unitPrice' => (float) $item['unit_price'],
            'availableStock' => (int) $item['current_stock'], 'quantity' => 1, 'iconKey' => $item['icon_key'],
        ];
        $_SESSION['req_cart'] = $cart;
    }
} elseif ($action === 'remove') {
    unset($cart[$itemId]);
    $_SESSION['req_cart'] = $cart;
} elseif ($action === 'update_qty') {
    if (isset($cart[$itemId])) {
        $max = $cart[$itemId]['availableStock'] ?: 9999;
        $qty = max(1, min($max, (int) ($_POST['qty'] ?? 1)));
        $cart[$itemId]['quantity'] = $qty;
        $_SESSION['req_cart'] = $cart;
    }
} else {
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$cartTotal = 0;
foreach ($cart as $c) $cartTotal += $c['unitPrice'] * $c['quantity'];

ob_start();
renderCartPanelBody($cart, $cartTotal);
$cartHtml = ob_get_clean();

echo json_encode([
    'ok' => true,
    'cartHtml' => $cartHtml,
    'itemId' => $itemId,
    'inCart' => isset($cart[$itemId]),
    'cartCount' => count($cart),
]);