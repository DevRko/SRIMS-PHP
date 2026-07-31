<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/inventory/adjust';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_adjustment') {
    $itemId = $_POST['item_id'] ?? '';
    $direction = $_POST['direction'] ?? 'increase';
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $reason = trim($_POST['reason'] ?? '');

    $itemStmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch();

    if ($item && $reason !== '') {
        $signedQty = $direction === 'increase' ? $qty : -$qty;
        $refNo = 'ADJ-' . substr((string) time(), -6);
        $pdo->prepare('UPDATE items SET current_stock = GREATEST(0, current_stock + ?) WHERE id = ?')->execute([$signedQty, $itemId]);
        $pdo->prepare('INSERT INTO stock_transactions (id, type, item_id, quantity, unit_price, reference_no, reference_type, date, user_id) VALUES (?, \'ADJUSTMENT\', ?, ?, ?, ?, \'MANUAL\', CURDATE(), ?)')
            ->execute([generateId('st'), $itemId, $signedQty, $item['unit_price'], $refNo, $user['id']]);
        addAuditLog($pdo, $user['id'], 'STOCK_ADJUSTMENT', 'Item', $itemId, "Adjusted {$item['name']} by " . ($signedQty > 0 ? '+' : '') . "{$signedQty}. Ref: {$refNo} — {$reason}");
        flash('success', "Adjustment applied to {$item['name']}.");
    } else {
        flash('error', 'Please select an item and provide a reason.');
    }
    header('Location: adjust.php');
    exit;
}

$adjustmentHistory = $pdo->query(
    "SELECT st.*, i.name AS item_name, i.icon_key FROM stock_transactions st JOIN items i ON i.id = st.item_id
     WHERE st.type = 'ADJUSTMENT' ORDER BY st.date DESC, st.id DESC"
)->fetchAll();

$stockItems = $pdo->query('SELECT * FROM items ORDER BY name')->fetchAll();
$prefillItem = $_GET['item'] ?? '';

$pageTitle = 'Adjust Stock';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Adjust Stock</h1><p class="text-page-subtitle text-text-secondary mt-1">Correct stock discrepancies from physical counts, damage, or loss</p></div>
    <button type="button" onclick="document.getElementById('adjust-form').classList.toggle('hidden')" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit"><i data-lucide="plus" style="width:16px;height:16px"></i> New Adjustment</button>
</div>

<?php renderFlash(); ?>

<div id="adjust-form" class="mb-6 rounded-card border border-border bg-surface-card p-card-padding <?= $prefillItem ? '' : 'hidden' ?>">
    <h3 class="mb-4 text-[15px] font-semibold text-text-primary">New Stock Adjustment</h3>
    <form method="POST" id="adjustForm">
    <input type="hidden" name="action" value="apply_adjustment">
    <div class="grid grid-cols-2 gap-4">
        <div class="col-span-2">
            <label class="mb-1 block text-[12px] font-medium text-text-primary">Item *</label>
            <select name="item_id" id="adjItemSelect" required onchange="updateAdjPreview()" class="w-full rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none">
                <option value="">Select item...</option>
                <?php foreach ($stockItems as $i): ?><option value="<?= e($i['id']) ?>" data-stock="<?= (int) $i['current_stock'] ?>" data-unit="<?= e($i['unit']) ?>" <?= $prefillItem === $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?> (Current: <?= (int) $i['current_stock'] ?> <?= e($i['unit']) ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-[12px] font-medium text-text-primary">Direction</label>
            <div class="flex gap-2">
                <label class="flex flex-1"><input type="radio" name="direction" value="increase" checked class="hidden adj-dir-radio" onchange="updateAdjPreview()">
                    <span class="adj-dir-btn flex flex-1 items-center justify-center gap-1.5 rounded-button border px-3 py-2 text-[13px] font-medium cursor-pointer border-green-500 bg-green-50 text-green-700 w-full"><i data-lucide="arrow-up" style="width:14px;height:14px"></i> Increase</span>
                </label>
                <label class="flex flex-1"><input type="radio" name="direction" value="decrease" class="hidden adj-dir-radio" onchange="updateAdjPreview()">
                    <span class="adj-dir-btn flex flex-1 items-center justify-center gap-1.5 rounded-button border px-3 py-2 text-[13px] font-medium cursor-pointer border-border text-text-secondary w-full"><i data-lucide="arrow-down" style="width:14px;height:14px"></i> Decrease</span>
                </label>
            </div>
        </div>
        <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Quantity *</label><input type="number" name="quantity" id="adjQty" value="1" min="1" oninput="updateAdjPreview()" class="w-full rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none"></div>
        <div class="col-span-2"><label class="mb-1 block text-[12px] font-medium text-text-primary">Reason *</label><input type="text" name="reason" required placeholder="e.g. Physical count correction, Damaged in storage" class="w-full rounded-button border border-border px-3 py-2 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none"></div>
    </div>
    <div id="adjPreview" class="mt-3 rounded-md bg-gray-50 p-2.5 text-[12px] text-text-secondary hidden">New stock level will be: <span id="adjPreviewValue" class="font-semibold text-text-primary"></span></div>
    <div class="mt-4 flex gap-2">
        <button type="button" onclick="document.getElementById('adjust-form').classList.add('hidden')" class="rounded-button border border-border px-4 py-2 text-[13px] font-medium text-text-secondary hover:bg-gray-50">Cancel</button>
        <button type="submit" class="rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover">Apply Adjustment</button>
    </div>
    </form>
</div>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Adjustment</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Reference</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Date</th>
            </tr></thead>
            <tbody>
                <?php if (empty($adjustmentHistory)): ?>
                <tr><td colspan="4" class="px-4 py-12 text-center text-[14px] text-text-muted">No adjustments recorded</td></tr>
                <?php else: foreach ($adjustmentHistory as $txn): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="px-4 py-3"><div class="flex items-center gap-2"><?= itemIconSvg($txn['icon_key'], $txn['item_id'], 24) ?><span class="text-[13px] text-text-primary"><?= e($txn['item_name']) ?></span></div></td>
                    <td class="px-4 py-3 text-right"><span class="text-[13px] font-medium <?= $txn['quantity'] > 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $txn['quantity'] > 0 ? '+' : '' ?><?= (int) $txn['quantity'] ?></span></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($txn['reference_no']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDateTime($txn['date']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.querySelectorAll('.adj-dir-radio').forEach(function (r) {
    r.addEventListener('change', function () {
        document.querySelectorAll('.adj-dir-btn').forEach(function (b) { b.className = 'adj-dir-btn flex flex-1 items-center justify-center gap-1.5 rounded-button border px-3 py-2 text-[13px] font-medium cursor-pointer border-border text-text-secondary w-full'; });
        var btn = r.nextElementSibling;
        if (r.value === 'increase') btn.className = 'adj-dir-btn flex flex-1 items-center justify-center gap-1.5 rounded-button border px-3 py-2 text-[13px] font-medium cursor-pointer border-green-500 bg-green-50 text-green-700 w-full';
        else btn.className = 'adj-dir-btn flex flex-1 items-center justify-center gap-1.5 rounded-button border px-3 py-2 text-[13px] font-medium cursor-pointer border-red-500 bg-red-50 text-red-700 w-full';
    });
});
function updateAdjPreview() {
    var sel = document.getElementById('adjItemSelect');
    var opt = sel.options[sel.selectedIndex];
    var preview = document.getElementById('adjPreview');
    if (!opt || !opt.value) { preview.classList.add('hidden'); return; }
    var stock = parseInt(opt.getAttribute('data-stock'), 10) || 0;
    var unit = opt.getAttribute('data-unit') || '';
    var qty = parseInt(document.getElementById('adjQty').value, 10) || 0;
    var dir = document.querySelector('input[name="direction"]:checked').value;
    var next = Math.max(0, stock + (dir === 'increase' ? qty : -qty));
    document.getElementById('adjPreviewValue').textContent = next + ' ' + unit;
    preview.classList.remove('hidden');
}
updateAdjPreview();
</script>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>