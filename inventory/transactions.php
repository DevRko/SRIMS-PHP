<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/inventory/transactions';

$searchQuery = trim($_GET['q'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

$sql = 'SELECT st.*, i.name AS item_name, i.icon_key FROM stock_transactions st JOIN items i ON i.id = st.item_id WHERE 1=1';
$params = [];
if ($typeFilter) { $sql .= ' AND st.type = ?'; $params[] = $typeFilter; }
if ($searchQuery) { $sql .= ' AND (i.name LIKE ? OR st.reference_no LIKE ?)'; $like = "%$searchQuery%"; array_push($params, $like, $like); }
$sql .= ' ORDER BY st.date DESC, st.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$filtered = $stmt->fetchAll();

$totalPages = max(1, (int) ceil(count($filtered) / $perPage));
$page = min($page, $totalPages);
$paginated = array_slice($filtered, ($page - 1) * $perPage, $perPage);

$qs = function (array $overrides = []) use ($searchQuery, $typeFilter) {
    $q = array_merge(['q' => $searchQuery, 'type' => $typeFilter], $overrides);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
};

$pageTitle = 'All Stock Transactions';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">All Stock Transactions</h1><p class="text-page-subtitle text-text-secondary mt-1">Complete ledger of inward, outward, and adjustment movements</p></div>

<div class="rounded-card border border-border bg-surface-card">
    <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
        <div class="relative flex-1 min-w-[200px]">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:16px;height:16px"></i>
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search by item or reference..." class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
        </div>
        <select name="type" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary focus:border-brand-primary focus:outline-none">
            <option value="">All Types</option>
            <option value="INWARD" <?= $typeFilter === 'INWARD' ? 'selected' : '' ?>>Inward</option>
            <option value="OUTWARD" <?= $typeFilter === 'OUTWARD' ? 'selected' : '' ?>>Outward</option>
            <option value="ADJUSTMENT" <?= $typeFilter === 'ADJUSTMENT' ? 'selected' : '' ?>>Adjustment</option>
        </select>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Type</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Quantity</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Reference</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Date</th>
            </tr></thead>
            <tbody>
                <?php if (empty($paginated)): ?>
                <tr><td colspan="5" class="px-4 py-12 text-center text-[14px] text-text-muted">No transactions found</td></tr>
                <?php else: foreach ($paginated as $txn): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3"><?= statusPill(txnTypeVariant($txn['type']), ucfirst(strtolower($txn['type']))) ?></td>
                    <td class="px-4 py-3"><div class="flex items-center gap-2"><?= itemIconSvg($txn['icon_key'], $txn['item_id'], 24) ?><span class="text-[13px] text-text-primary"><?= e($txn['item_name']) ?></span></div></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium">
                        <span class="<?= $txn['type'] === 'OUTWARD' || $txn['quantity'] < 0 ? 'text-red-600' : 'text-green-600' ?>">
                            <?= $txn['type'] === 'OUTWARD' ? '-' : ($txn['quantity'] > 0 ? '+' : '') ?><?= abs((int) $txn['quantity']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($txn['reference_no']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDateTime($txn['date']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between border-t border-border px-4 py-3">
        <span class="text-[12px] text-text-secondary">Showing <?= ($page - 1) * $perPage + 1 ?> to <?= min($page * $perPage, count($filtered)) ?> of <?= count($filtered) ?></span>
        <div class="flex items-center gap-1">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= $qs(['page' => $p]) ?>" class="min-w-[28px] rounded px-1.5 py-0.5 text-center text-[12px] font-medium <?= $p === $page ? 'bg-brand-primary text-white' : 'text-text-secondary hover:bg-gray-100' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
