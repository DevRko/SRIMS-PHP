<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/inventory/low-stock';

$items = $pdo->query('SELECT i.*, c.name AS category_name FROM items i LEFT JOIN categories c ON c.id = i.category_id')->fetchAll();
$alertItems = [];
foreach ($items as $it) {
    $it['stock_status'] = getStockStatus($it);
    if (in_array($it['stock_status'], ['LOW', 'CRITICAL', 'OUT_OF_STOCK'], true)) $alertItems[] = $it;
}
$order = ['OUT_OF_STOCK' => 0, 'CRITICAL' => 1, 'LOW' => 2];
usort($alertItems, fn($a, $b) => $order[$a['stock_status']] <=> $order[$b['stock_status']]);

$criticalCount = count(array_filter($alertItems, fn($i) => $i['stock_status'] === 'CRITICAL'));
$lowCount = count(array_filter($alertItems, fn($i) => $i['stock_status'] === 'LOW'));
$outCount = count(array_filter($alertItems, fn($i) => $i['stock_status'] === 'OUT_OF_STOCK'));

$pageTitle = 'Low Stock Alerts';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Low Stock Alerts</h1><p class="text-page-subtitle text-text-secondary mt-1">Items requiring immediate attention or restocking</p></div>
    <a href="inward.php" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit">Restock Now <i data-lucide="arrow-up-right" style="width:14px;height:14px"></i></a>
</div>

<div class="mb-6 grid grid-cols-3 gap-4">
    <div class="rounded-card border border-border bg-surface-card p-4 text-center"><div class="text-[24px] font-bold text-gray-600"><?= $outCount ?></div><div class="text-[12px] text-text-secondary">Out of Stock</div></div>
    <div class="rounded-card border border-border bg-surface-card p-4 text-center"><div class="text-[24px] font-bold text-red-600"><?= $criticalCount ?></div><div class="text-[12px] text-text-secondary">Critical</div></div>
    <div class="rounded-card border border-border bg-surface-card p-4 text-center"><div class="text-[24px] font-bold text-amber-600"><?= $lowCount ?></div><div class="text-[12px] text-text-secondary">Low Stock</div></div>
</div>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Category</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Current Stock</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Min. Level</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Shortfall</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
            </tr></thead>
            <tbody>
                <?php if (empty($alertItems)): ?>
                <tr><td colspan="7" class="px-4 py-12 text-center text-[14px] text-text-muted">All items are sufficiently stocked 🎉</td></tr>
                <?php else: foreach ($alertItems as $item): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3"><div class="flex items-center gap-2"><?= itemIconSvg($item['icon_key'], $item['id'], 28) ?><div><div class="text-[13px] font-medium text-text-primary"><?= e($item['name']) ?></div><div class="text-[11px] text-text-muted"><?= e($item['id']) ?></div></div></div></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($item['category_name']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= (int) $item['current_stock'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-secondary"><?= (int) $item['min_stock_level'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-semibold text-red-600"><?= max(0, $item['min_stock_level'] - $item['current_stock']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill($item['stock_status'] === 'OUT_OF_STOCK' ? 'outOfStock' : ($item['stock_status'] === 'CRITICAL' ? 'critical' : 'low')) ?></td>
                    <td class="px-4 py-3 text-center"><a href="inward.php" class="rounded-button border border-brand-primary px-3 py-1 text-[11px] font-medium text-brand-primary hover:bg-blue-50">Reorder</a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
