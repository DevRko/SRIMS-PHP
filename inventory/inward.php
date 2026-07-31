<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/inventory/inward';

$completedGrnId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_grn') {
    $supplierId = $_POST['supplier_id'] ?? '';
    $grnDate = $_POST['grn_date'] ?? date('Y-m-d');
    $invoiceNo = trim($_POST['invoice_no'] ?? '');
    $invoiceDate = $_POST['invoice_date'] ?? null;
    $deliveryChallan = trim($_POST['delivery_challan'] ?? '');
    $deliveryDate = $_POST['delivery_date'] ?? null;
    $remarks = trim($_POST['remarks'] ?? '');
    $itemIds = $_POST['line_item_id'] ?? [];
    $qtys = $_POST['line_qty'] ?? [];

    $validLines = [];
    foreach ($itemIds as $idx => $itemId) {
        $qty = (int) ($qtys[$idx] ?? 0);
        if ($itemId && $qty > 0) $validLines[] = ['item_id' => $itemId, 'qty' => $qty];
    }

    if (empty($validLines) || !$supplierId) {
        flash('error', 'Add at least one item with a quantity, and select a supplier.');
        header('Location: inward.php');
        exit;
    }

    $grnId = 'GRN-' . date('Y') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    $totalValue = 0;

    $pdo->beginTransaction();
    try {
        $priceStmt = $pdo->prepare('SELECT unit_price, name FROM items WHERE id = ?');
        $lines = [];
        foreach ($validLines as $l) {
            $priceStmt->execute([$l['item_id']]);
            $itm = $priceStmt->fetch();
            $lines[] = ['item_id' => $l['item_id'], 'qty' => $l['qty'], 'price' => (float) $itm['unit_price'], 'name' => $itm['name']];
            $totalValue += $l['qty'] * $itm['unit_price'];
        }

        $pdo->prepare('INSERT INTO grns (id, supplier_id, grn_date, invoice_no, invoice_date, delivery_challan, delivery_date, remarks, total_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$grnId, $supplierId, $grnDate, $invoiceNo ?: null, $invoiceDate ?: null, $deliveryChallan ?: null, $deliveryDate ?: null, $remarks ?: null, $totalValue]);

        $insGrnItem = $pdo->prepare('INSERT INTO grn_items (id, grn_id, item_id, received_qty, unit_price) VALUES (?, ?, ?, ?, ?)');
        $insTxn = $pdo->prepare('INSERT INTO stock_transactions (id, type, item_id, quantity, unit_price, reference_no, reference_type, date, user_id) VALUES (?, \'INWARD\', ?, ?, ?, ?, \'GRN\', ?, ?)');
        $updStock = $pdo->prepare('UPDATE items SET current_stock = current_stock + ? WHERE id = ?');
        foreach ($lines as $l) {
            $insGrnItem->execute([generateId('grni'), $grnId, $l['item_id'], $l['qty'], $l['price']]);
            $insTxn->execute([generateId('st'), $l['item_id'], $l['qty'], $l['price'], $grnId, $grnDate, $user['id']]);
            $updStock->execute([$l['qty'], $l['item_id']]);
        }
        $pdo->commit();
    } catch (Exception $ex) {
        $pdo->rollBack();
        flash('error', 'Could not save GRN: ' . $ex->getMessage());
        header('Location: inward.php');
        exit;
    }

    $supplierName = $pdo->query('SELECT name FROM suppliers WHERE id = ' . $pdo->quote($supplierId))->fetchColumn();
    addAuditLog($pdo, $user['id'], 'STOCK_INWARD', 'GRN', $grnId, 'Received ' . count($lines) . " item(s) from {$supplierName}. Total value: " . formatCurrency($totalValue));
    $completedGrnId = $grnId;
    $_SESSION['last_grn'] = ['id' => $grnId, 'items' => count($lines), 'total' => $totalValue];
    header('Location: inward.php?done=1');
    exit;
}

if (($_GET['done'] ?? '') === '1' && !empty($_SESSION['last_grn'])) {
    $completedGrnId = $_SESSION['last_grn']['id'];
    $doneItems = $_SESSION['last_grn']['items'];
    $doneTotal = $_SESSION['last_grn']['total'];
    unset($_SESSION['last_grn']);
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$stockItems = $pdo->query('SELECT i.*, c.name AS category_name FROM items i LEFT JOIN categories c ON c.id = i.category_id ORDER BY i.name')->fetchAll();

$pageTitle = 'Stock Inward';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Stock Inward</h1><p class="text-page-subtitle text-text-secondary mt-1">Record goods received from suppliers (GRN)</p></div>

<?php renderFlash(); ?>

<?php if ($completedGrnId): ?>
<div class="mx-auto max-w-lg text-center">
    <div class="rounded-card border border-border bg-surface-card p-10">
        <div class="mb-4 flex justify-center"><div class="flex h-16 w-16 items-center justify-center rounded-full bg-green-100"><i data-lucide="check-circle-2" class="text-green-600" style="width:32px;height:32px"></i></div></div>
        <h2 class="mb-2 text-[20px] font-bold text-text-primary">Stock Updated!</h2>
        <p class="mb-6 text-[14px] text-text-secondary"><?= e($completedGrnId) ?> has been recorded and stock levels updated.</p>
        <div class="mb-6 rounded-lg bg-gray-50 p-4 text-left">
            <div class="flex justify-between text-[13px] mb-2"><span class="text-text-secondary">Items Received</span><span class="font-semibold text-text-primary"><?= $doneItems ?? '' ?></span></div>
            <div class="flex justify-between text-[13px]"><span class="text-text-secondary">Total Value</span><span class="font-semibold text-text-primary"><?= isset($doneTotal) ? formatCurrency($doneTotal) : '' ?></span></div>
        </div>
        <a href="overview.php" class="block w-full rounded-button bg-brand-primary py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover">View Stock Overview</a>
    </div>
</div>
<?php else: ?>

<div class="rounded-card border border-border bg-surface-card p-card-padding">
    <h3 class="mb-4 text-[15px] font-semibold text-text-primary">Goods Receipt Note (GRN)</h3>
    <form method="POST" id="grnForm">
    <input type="hidden" name="action" value="submit_grn">
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">GRN Date</label><input type="date" name="grn_date" value="<?= date('Y-m-d') ?>" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
        <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Supplier *</label>
            <select name="supplier_id" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none">
                <?php foreach ($suppliers as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Invoice No.</label><input type="text" name="invoice_no" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
        <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Invoice Date</label><input type="date" name="invoice_date" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
        <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Delivery Challan No.</label><input type="text" name="delivery_challan" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
        <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Delivery Date</label><input type="date" name="delivery_date" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
    </div>

    <h4 class="mb-3 text-[14px] font-semibold text-text-primary">Items Received</h4>
    <table class="w-full mb-2" id="grnLinesTable">
        <thead><tr class="border-b border-border">
            <th class="pb-2 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
            <th class="pb-2 text-right text-table-header uppercase tracking-table-header text-text-secondary w-28">Qty Received</th>
            <th class="pb-2 text-right text-table-header uppercase tracking-table-header text-text-secondary w-28">Unit Price</th>
            <th class="pb-2 text-right text-table-header uppercase tracking-table-header text-text-secondary w-28">Total</th>
            <th class="pb-2 w-10"></th>
        </tr></thead>
        <tbody id="grnLinesBody"></tbody>
        <tfoot><tr class="border-t-2 border-border"><td colspan="3" class="py-3 text-right text-[14px] font-semibold text-text-primary">Grand Total</td><td class="py-3 text-right text-[16px] font-bold text-green-600" id="grnGrandTotal">₹0.00</td><td></td></tr></tfoot>
    </table>
    <button type="button" onclick="addGrnLine()" class="mb-4 flex items-center gap-1.5 rounded-button border border-border px-3 py-1.5 text-[12px] font-medium text-text-secondary hover:bg-gray-50"><i data-lucide="plus" style="width:14px;height:14px"></i> Add Line</button>

    <div class="flex items-start gap-2 rounded-md bg-tint-blue-bg p-2.5">
        <i data-lucide="info" class="mt-0.5 flex-shrink-0 text-tint-blue-icon" style="width:14px;height:14px"></i>
        <span class="text-[11px] text-tint-blue-icon">After submission, stock quantity will be updated and available for issuance.</span>
    </div>

    <div class="mt-6 flex items-center justify-between">
        <a href="overview.php" class="rounded-button border border-border px-4 py-2 text-[13px] font-medium text-text-secondary hover:bg-gray-50">Cancel</a>
        <button type="submit" id="grnSubmitBtn" disabled class="rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-40">Submit &amp; Update Stock</button>
    </div>
    </form>
</div>

<script>
var GRN_ITEMS = <?= json_encode(array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'price' => (float) $i['unit_price']], $stockItems)) ?>;
var grnLineCount = 0;

function grnItemOptions(selectedId) {
    var html = '<option value="">Select item...</option>';
    GRN_ITEMS.forEach(function (it) {
        html += '<option value="' + it.id + '" data-price="' + it.price + '"' + (it.id === selectedId ? ' selected' : '') + '>' + it.name + '</option>';
    });
    return html;
}

function addGrnLine() {
    grnLineCount++;
    var tr = document.createElement('tr');
    tr.className = 'border-b border-border last:border-0';
    tr.id = 'grn-line-' + grnLineCount;
    tr.innerHTML =
        '<td class="py-2"><select name="line_item_id[]" onchange="grnLineChanged(' + grnLineCount + ')" class="w-full rounded-button border border-border px-2 py-1.5 text-[13px] focus:border-brand-primary focus:outline-none">' + grnItemOptions() + '</select></td>' +
        '<td class="py-2"><input type="number" name="line_qty[]" min="1" value="1" oninput="grnLineChanged(' + grnLineCount + ')" class="w-full rounded-button border border-border px-2 py-1.5 text-right text-[13px] focus:border-brand-primary focus:outline-none"></td>' +
        '<td class="py-2 text-right text-[13px] text-text-primary" id="grn-price-' + grnLineCount + '">₹0.00</td>' +
        '<td class="py-2 text-right text-[13px] font-medium text-text-primary" id="grn-total-' + grnLineCount + '">₹0.00</td>' +
        '<td class="py-2 text-center"><button type="button" onclick="removeGrnLine(' + grnLineCount + ')" class="text-red-400 hover:text-red-600"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button></td>';
    document.getElementById('grnLinesBody').appendChild(tr);
    if (window.lucide) lucide.createIcons();
    updateGrnSubmitState();
}

function removeGrnLine(id) {
    var row = document.getElementById('grn-line-' + id);
    if (row) row.remove();
    recalcGrnTotal();
    updateGrnSubmitState();
}

function grnLineChanged(id) {
    var row = document.getElementById('grn-line-' + id);
    var select = row.querySelector('select');
    var qtyInput = row.querySelector('input[name="line_qty[]"]');
    var price = parseFloat(select.selectedOptions[0] ? select.selectedOptions[0].getAttribute('data-price') : 0) || 0;
    var qty = parseInt(qtyInput.value, 10) || 0;
    document.getElementById('grn-price-' + id).textContent = '₹' + price.toFixed(2);
    document.getElementById('grn-total-' + id).textContent = '₹' + (price * qty).toFixed(2);
    recalcGrnTotal();
    updateGrnSubmitState();
}

function recalcGrnTotal() {
    var total = 0;
    document.querySelectorAll('#grnLinesBody tr').forEach(function (row) {
        var select = row.querySelector('select');
        var qtyInput = row.querySelector('input[name="line_qty[]"]');
        var price = parseFloat(select.selectedOptions[0] ? select.selectedOptions[0].getAttribute('data-price') : 0) || 0;
        var qty = parseInt(qtyInput.value, 10) || 0;
        total += price * qty;
    });
    document.getElementById('grnGrandTotal').textContent = '₹' + total.toFixed(2);
}

function updateGrnSubmitState() {
    var hasValid = false;
    document.querySelectorAll('#grnLinesBody tr').forEach(function (row) {
        var select = row.querySelector('select');
        var qtyInput = row.querySelector('input[name="line_qty[]"]');
        if (select.value && parseInt(qtyInput.value, 10) > 0) hasValid = true;
    });
    document.getElementById('grnSubmitBtn').disabled = !hasValid;
}

addGrnLine();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>