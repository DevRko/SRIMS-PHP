<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/inventory/outward';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_outward') {
    $itemId = $_POST['item_id'] ?? '';
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $reason = trim($_POST['reason'] ?? '');
    $refNo = trim($_POST['reference_no'] ?? '') ?: ('OUT-' . substr((string) time(), -6));
    $linkedReq = $_POST['linked_requisition_id'] ?? '';

    $itemStmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
    $itemStmt->execute([$itemId]);
    $item = $itemStmt->fetch();

    if ($item && $qty <= $item['current_stock']) {
        $pdo->prepare('UPDATE items SET current_stock = GREATEST(0, current_stock - ?) WHERE id = ?')->execute([$qty, $itemId]);
        $pdo->prepare('INSERT INTO stock_transactions (id, type, item_id, quantity, unit_price, reference_no, reference_type, linked_requisition_id, date, user_id) VALUES (?, \'OUTWARD\', ?, ?, ?, ?, \'MANUAL\', ?, CURDATE(), ?)')
            ->execute([generateId('st'), $itemId, $qty, $item['unit_price'], $refNo, $linkedReq ?: null, $user['id']]);
        addAuditLog($pdo, $user['id'], 'STOCK_OUTWARD', 'Item', $itemId, "Issued {$item['name']} by -{$qty}. Ref: {$refNo}" . ($reason ? " — {$reason}" : ''));
        flash('success', "Recorded outward movement of {$qty} × {$item['name']}.");
    } else {
        flash('error', 'Quantity exceeds available stock, or item not found.');
    }
    header('Location: outward.php');
    exit;
}

// CSV export
if (($_GET['export'] ?? '') === 'csv') {
    $period = $_GET['period'] ?? 'all';
    $rows = fetchOutwardHistory($pdo, $period, '');
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock_outward_' . $period . '_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Item', 'Qty', 'Requisition ID', 'Reference No', 'Unit Price', 'Total']);
    foreach ($rows as $r) {
        fputcsv($out, [formatDateTime($r['date']), $r['item_name'], $r['quantity'], $r['linked_requisition_id'] ?: '—', $r['reference_no'], number_format($r['unit_price'], 2), number_format($r['quantity'] * $r['unit_price'], 2)]);
    }
    fclose($out);
    exit;
}

function fetchOutwardHistory(PDO $pdo, string $period, string $search): array
{
    $sql = "SELECT st.*, i.name AS item_name, i.icon_key FROM stock_transactions st JOIN items i ON i.id = st.item_id WHERE st.type = 'OUTWARD'";
    $params = [];
    $ranges = [
        'this_month' => date('Y-m-01'), 'last_3_months' => date('Y-m-d', strtotime('-3 months')),
        'last_6_months' => date('Y-m-d', strtotime('-6 months')), 'last_year' => date('Y-m-d', strtotime('-1 year')),
    ];
    if ($period !== 'all' && isset($ranges[$period])) { $sql .= ' AND st.date >= ?'; $params[] = $ranges[$period]; }
    if ($search) { $sql .= ' AND (i.name LIKE ? OR st.reference_no LIKE ? OR st.linked_requisition_id LIKE ?)'; $like = "%$search%"; array_push($params, $like, $like, $like); }
    $sql .= ' ORDER BY st.date DESC, st.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$searchQuery = trim($_GET['q'] ?? '');
$period = $_GET['period'] ?? 'all';
$filteredHistory = fetchOutwardHistory($pdo, $period, $searchQuery);
$periodLabels = ['all' => 'All Time', 'this_month' => 'This Month', 'last_3_months' => 'Last 3 Months', 'last_6_months' => 'Last 6 Months', 'last_year' => 'Last Year'];

$stockItems = $pdo->query('SELECT * FROM items ORDER BY name')->fetchAll();
$linkableReqs = $pdo->query("SELECT r.*, u.name AS user_name, d.name AS department_name FROM requisitions r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=r.department_id WHERE r.status IN ('APPROVED','ISSUED','PARTIAL') ORDER BY r.created_at DESC")->fetchAll();

$prefillItem = $_GET['item'] ?? '';
$grandTotal = array_sum(array_map(fn($t) => $t['quantity'] * $t['unit_price'], $filteredHistory));

$pageTitle = 'Stock Outward';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Stock Outward</h1><p class="text-page-subtitle text-text-secondary mt-1">Outward movements — auto-generated on approval + manual entries</p></div>
    <button type="button" onclick="document.getElementById('outward-form').classList.toggle('hidden')" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit"><i data-lucide="plus" style="width:16px;height:16px"></i> Manual Entry</button>
</div>

<?php renderFlash(); ?>

<div id="outward-form" class="mb-5 rounded-card border border-border bg-surface-card p-4 <?= $prefillItem ? '' : 'hidden' ?>">
    <h3 class="mb-3 text-[14px] font-semibold text-text-primary">New Manual Stock Outward</h3>
    <form method="POST">
    <input type="hidden" name="action" value="record_outward">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="mb-1 block text-[12px] font-medium text-text-primary">Item *</label>
            <select name="item_id" required class="w-full rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none">
                <option value="">Select item...</option>
                <?php foreach ($stockItems as $i): ?><option value="<?= e($i['id']) ?>" <?= $prefillItem === $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?> (Stock: <?= (int) $i['current_stock'] ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Quantity *</label><input type="number" name="quantity" value="1" min="1" required class="w-full rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none"></div>
        <div class="col-span-2">
            <label class="mb-1 block text-[12px] font-medium text-text-primary">Link to Requisition (optional)</label>
            <select name="linked_requisition_id" class="w-full rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none">
                <option value="">No requisition — independent movement</option>
                <?php foreach ($linkableReqs as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['id']) ?> — <?= e($r['user_name']) ?> (<?= e($r['department_name']) ?>)</option><?php endforeach; ?>
            </select>
        </div>
        <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Reference No.</label><input type="text" name="reference_no" placeholder="Auto if blank" class="w-full rounded-button border border-border px-3 py-2 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none"></div>
        <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Reason</label><input type="text" name="reason" placeholder="e.g. Damaged, Write-off" class="w-full rounded-button border border-border px-3 py-2 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none"></div>
    </div>
    <div class="mt-4 flex gap-2">
        <button type="button" onclick="document.getElementById('outward-form').classList.add('hidden')" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
        <button type="submit" class="rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover">Record Outward</button>
    </div>
    </form>
</div>

<div class="rounded-card border border-border bg-surface-card">
    <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
        <div class="relative flex-1 min-w-[180px]">
            <i data-lucide="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-text-muted" style="width:14px;height:14px"></i>
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search item, reference, req ID..." class="w-full rounded-button border border-border py-1.5 pl-8 pr-2 text-[12px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none">
        </div>
        <select name="period" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-1.5 text-[12px] text-text-secondary focus:border-brand-primary focus:outline-none">
            <?php foreach ($periodLabels as $k => $l): ?><option value="<?= $k ?>" <?= $period === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
        <a href="?export=csv&period=<?= e($period) ?>&q=<?= e($searchQuery) ?>" class="flex items-center gap-1.5 rounded-button border border-border px-3 py-1.5 text-[12px] font-medium text-text-secondary hover:bg-gray-50"><i data-lucide="download" style="width:14px;height:14px"></i> CSV</a>
        <span class="text-[11px] text-text-muted ml-auto"><?= count($filteredHistory) ?> movement<?= count($filteredHistory) !== 1 ? 's' : '' ?></span>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Qty</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Requisition ID</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Reference No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Date</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
            </tr></thead>
            <tbody>
                <?php if (empty($filteredHistory)): ?>
                <tr><td colspan="6" class="px-4 py-12 text-center text-[14px] text-text-muted">No outward movements in <?= mb_strtolower($periodLabels[$period]) ?></td></tr>
                <?php else: foreach ($filteredHistory as $txn): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="px-4 py-3"><div class="flex items-center gap-2"><?= itemIconSvg($txn['icon_key'], $txn['item_id'], 22) ?><span class="text-[13px] text-text-primary"><?= e($txn['item_name']) ?></span></div></td>
                    <td class="px-4 py-3 text-right"><span class="flex items-center justify-end gap-1 text-[13px] font-medium text-red-600"><i data-lucide="arrow-up-from-line" style="width:11px;height:11px"></i><?= (int) $txn['quantity'] ?></span></td>
                    <td class="px-4 py-3"><?php if ($txn['linked_requisition_id']): ?><button type="button" onclick="openReqModal('<?= e($txn['linked_requisition_id']) ?>')" class="text-[13px] font-medium text-brand-primary hover:underline"><?= e($txn['linked_requisition_id']) ?></button><?php else: ?><span class="text-[13px] text-text-muted">—</span><?php endif; ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($txn['reference_no']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDateTime($txn['date']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency($txn['quantity'] * $txn['unit_price']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($filteredHistory)): ?>
            <tfoot><tr class="border-t border-border bg-gray-50">
                <td colspan="5" class="px-4 py-2 text-right text-[12px] font-semibold text-text-primary">Total (<?= $periodLabels[$period] ?>)</td>
                <td class="px-4 py-2 text-right text-[13px] font-bold text-brand-primary"><?= formatCurrency($grandTotal) ?></td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/requisition-modal.php';
$linkedIds = array_unique(array_filter(array_column($filteredHistory, 'linked_requisition_id')));
if ($linkedIds) {
    $ph = implode(',', array_fill(0, count($linkedIds), '?'));
    $rs = $pdo->prepare("SELECT r.*, u.name AS user_name FROM requisitions r JOIN users u ON u.id=r.user_id WHERE r.id IN ($ph)");
    $rs->execute(array_values($linkedIds));
    foreach ($rs->fetchAll() as $req) { renderRequisitionModal($pdo, $req); }
}
include __DIR__ . '/../includes/app-shell-end.php';