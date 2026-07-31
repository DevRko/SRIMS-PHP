<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/inventory/overview';

$searchQuery = trim($_GET['q'] ?? '');
$categoryFilter = $_GET['category'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;

$items = $pdo->query('SELECT i.*, c.name AS category_name FROM items i LEFT JOIN categories c ON c.id = i.category_id ORDER BY i.name')->fetchAll();
foreach ($items as &$it) { $it['stock_status'] = getStockStatus($it); }
unset($it);

$totalItems = count($items);
$totalStock = array_sum(array_column($items, 'current_stock'));
$totalValue = array_sum(array_map(fn($i) => $i['current_stock'] * $i['unit_price'], $items));
$lowStockCount = count(array_filter($items, fn($i) => $i['stock_status'] === 'LOW'));
$criticalCount = count(array_filter($items, fn($i) => $i['stock_status'] === 'CRITICAL'));
$outOfStockCount = count(array_filter($items, fn($i) => $i['stock_status'] === 'OUT_OF_STOCK'));
$inStockCount = count(array_filter($items, fn($i) => $i['stock_status'] === 'IN_STOCK'));

$filteredItems = $items;
if ($searchQuery) {
    $q = mb_strtolower($searchQuery);
    $filteredItems = array_filter($filteredItems, fn($i) => str_contains(mb_strtolower($i['name']), $q) || str_contains(mb_strtolower($i['id']), $q));
}
if ($categoryFilter) $filteredItems = array_filter($filteredItems, fn($i) => $i['category_id'] === $categoryFilter);
if ($statusFilter) $filteredItems = array_filter($filteredItems, fn($i) => $i['stock_status'] === $statusFilter);
$filteredItems = array_values($filteredItems);

$totalPages = max(1, (int) ceil(count($filteredItems) / $perPage));
$page = min($page, $totalPages);
$paginated = array_slice($filteredItems, ($page - 1) * $perPage, $perPage);

$lowStockItems = array_slice(array_values(array_filter($items, fn($i) => in_array($i['stock_status'], ['LOW', 'CRITICAL'], true))), 0, 4);
$recentInward = $pdo->query("SELECT * FROM stock_transactions WHERE type='INWARD' ORDER BY date DESC, id DESC LIMIT 3")->fetchAll();

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

$qs = function (array $overrides = []) use ($searchQuery, $categoryFilter, $statusFilter) {
    $q = array_merge(['q' => $searchQuery, 'category' => $categoryFilter, 'status' => $statusFilter], $overrides);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
};

$pageTitle = 'Inventory';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Inventory</h1><p class="text-page-subtitle text-text-secondary mt-1">Manage and track all stationery items and stock levels</p></div>
    <a href="inward.php" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit"><i data-lucide="plus" style="width:16px;height:16px"></i> Add Stock (Inward)</a>
</div>

<?php renderFlash(); ?>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
    <?php foreach ([
        ['folder-open', 'blue', 'Total Items', $totalItems],
        ['package', 'green', 'Total Stock (Qty)', number_format($totalStock)],
        ['wallet', 'blue', 'Total Value', formatCurrency($totalValue)],
        ['alert-triangle', 'amber', 'Low Stock Items', $lowStockCount + $criticalCount],
        ['x-circle', 'red', 'Out of Stock', $outOfStockCount],
    ] as [$icon, $tint, $label, $value]): ?>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-<?= $tint ?>-bg"><i data-lucide="<?= $icon ?>" class="text-tint-<?= $tint ?>-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary"><?= $label ?></p><p class="mt-1 text-card-value text-text-primary"><?= $value ?></p></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-10">
    <div class="lg:col-span-7">
        <div class="rounded-card border border-border bg-surface-card">
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                <div class="relative flex-1 min-w-[200px]">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:16px;height:16px"></i>
                    <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search items..." class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
                </div>
                <select name="category" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary focus:border-brand-primary focus:outline-none">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>" <?= $categoryFilter === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
                <select name="status" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary focus:border-brand-primary focus:outline-none">
                    <option value="">Stock Status</option>
                    <option value="IN_STOCK" <?= $statusFilter === 'IN_STOCK' ? 'selected' : '' ?>>In Stock</option>
                    <option value="LOW" <?= $statusFilter === 'LOW' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="CRITICAL" <?= $statusFilter === 'CRITICAL' ? 'selected' : '' ?>>Critical</option>
                    <option value="OUT_OF_STOCK" <?= $statusFilter === 'OUT_OF_STOCK' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
                <button type="submit" class="hidden"></button>
                <a href="overview.php" class="flex items-center gap-1 rounded-button border border-border px-3 py-2 text-[13px] font-medium text-text-secondary hover:bg-gray-50"><i data-lucide="sliders-horizontal" style="width:14px;height:14px"></i> Clear Filters</a>
            </form>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead><tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                        <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Category</th>
                        <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Unit</th>
                        <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Current</th>
                        <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Min Level</th>
                        <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                        <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Unit Price</th>
                        <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Value</th>
                        <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary w-12"></th>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($paginated)): ?>
                        <tr><td colspan="9" class="px-4 py-12 text-center text-[14px] text-text-muted">No items match your filters</td></tr>
                        <?php else: foreach ($paginated as $item): ?>
                        <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                            <td class="px-4 py-3"><div class="flex items-center gap-2"><?= itemIconSvg($item['icon_key'], $item['id'], 28) ?><div><div class="text-[13px] font-medium text-text-primary"><?= e($item['name']) ?></div><div class="text-[11px] text-text-muted"><?= e($item['id']) ?></div></div></div></td>
                            <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($item['category_name']) ?></td>
                            <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($item['unit']) ?></td>
                            <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= (int) $item['current_stock'] ?></td>
                            <td class="px-4 py-3 text-right text-[13px] text-text-secondary"><?= (int) $item['min_stock_level'] ?></td>
                            <td class="px-4 py-3 text-center"><?= statusPill(stockStatusVariant($item['stock_status'])) ?></td>
                            <td class="px-4 py-3 text-right text-[13px] text-text-primary"><?= formatCurrency((float) $item['unit_price']) ?></td>
                            <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency($item['current_stock'] * $item['unit_price']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <div class="relative inline-block">
                                    <button type="button" onclick="var m=document.getElementById('menu-<?= e($item['id']) ?>'); document.querySelectorAll('.item-menu').forEach(function(x){if(x!==m)x.classList.add('hidden');}); m.classList.toggle('hidden');" class="rounded p-1 text-text-muted hover:bg-gray-100"><i data-lucide="more-vertical" style="width:16px;height:16px"></i></button>
                                    <div id="menu-<?= e($item['id']) ?>" class="item-menu hidden absolute right-0 top-full z-50 mt-1 w-44 rounded-md border border-border bg-surface-card py-1 shadow-lg">
                                        <a href="../masters/items.php?edit=<?= e($item['id']) ?>" class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] text-text-primary hover:bg-gray-50"><i data-lucide="pencil" class="text-text-secondary" style="width:14px;height:14px"></i> Edit Item</a>
                                        <a href="adjust.php?item=<?= e($item['id']) ?>" class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] text-text-primary hover:bg-gray-50"><i data-lucide="package" class="text-text-secondary" style="width:14px;height:14px"></i> Adjust Stock</a>
                                        <a href="outward.php?item=<?= e($item['id']) ?>" class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] text-text-primary hover:bg-gray-50"><i data-lucide="arrow-up-from-line" class="text-text-secondary" style="width:14px;height:14px"></i> Stock Outward</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between border-t border-border px-4 py-3">
                <span class="text-[12px] text-text-secondary">Showing <?= ($page - 1) * $perPage + 1 ?> to <?= min($page * $perPage, count($filteredItems)) ?> of <?= count($filteredItems) ?></span>
                <div class="flex items-center gap-1">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?= $qs(['page' => $p]) ?>" class="min-w-[28px] rounded px-1.5 py-0.5 text-center text-[12px] font-medium <?= $p === $page ? 'bg-brand-primary text-white' : 'text-text-secondary hover:bg-gray-100' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="lg:col-span-3 space-y-4">
        <div class="rounded-card border border-border bg-surface-card p-card-padding">
            <h3 class="mb-3 text-[14px] font-semibold text-text-primary">Stock Summary</h3>
            <div class="relative h-[200px] w-full"><canvas id="stockDonut"></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-[22px] font-bold text-text-primary"><?= $totalItems ?></span>
                    <span class="text-[11px] text-text-muted">Total Items</span>
                </div>
            </div>
            <div class="mt-2 grid grid-cols-2 gap-2">
                <div class="flex items-center gap-2"><div class="h-2.5 w-2.5 rounded-full" style="background:#059669"></div><span class="text-[11px] text-text-secondary">In Stock (<?= $inStockCount ?>)</span></div>
                <div class="flex items-center gap-2"><div class="h-2.5 w-2.5 rounded-full" style="background:#D97706"></div><span class="text-[11px] text-text-secondary">Low Stock (<?= $lowStockCount ?>)</span></div>
                <div class="flex items-center gap-2"><div class="h-2.5 w-2.5 rounded-full" style="background:#DC2626"></div><span class="text-[11px] text-text-secondary">Critical (<?= $criticalCount ?>)</span></div>
                <div class="flex items-center gap-2"><div class="h-2.5 w-2.5 rounded-full" style="background:#9CA3AF"></div><span class="text-[11px] text-text-secondary">Out of Stock (<?= $outOfStockCount ?>)</span></div>
            </div>
        </div>

        <div class="rounded-card border border-border bg-surface-card p-card-padding">
            <div class="mb-3 flex items-center justify-between"><h3 class="text-[14px] font-semibold text-text-primary">Low Stock Alerts</h3><a href="low-stock.php" class="text-[12px] font-medium text-brand-primary hover:underline">View All</a></div>
            <div class="space-y-2">
                <?php foreach ($lowStockItems as $item): ?>
                <div class="flex items-center gap-2 rounded-md bg-gray-50 p-2">
                    <?= itemIconSvg($item['icon_key'], $item['id'], 24) ?>
                    <div class="flex-1 min-w-0"><div class="text-[12px] font-medium text-text-primary truncate"><?= e($item['name']) ?></div><div class="text-[10px] text-text-muted">Current: <?= (int) $item['current_stock'] ?> | Min: <?= (int) $item['min_stock_level'] ?></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($lowStockItems)): ?><p class="text-[12px] text-text-muted">All items sufficiently stocked</p><?php endif; ?>
            </div>
        </div>

        <div class="rounded-card border border-border bg-surface-card p-card-padding">
            <div class="mb-3 flex items-center justify-between"><h3 class="text-[14px] font-semibold text-text-primary">Recent Stock Inward</h3><a href="inward.php" class="text-[12px] font-medium text-brand-primary hover:underline">View All</a></div>
            <div class="space-y-2">
                <?php foreach ($recentInward as $txn): ?>
                <div class="flex items-center justify-between rounded-md bg-gray-50 p-2">
                    <div><div class="text-[12px] font-medium text-text-primary"><?= e($txn['reference_no']) ?></div><div class="text-[10px] text-text-muted"><?php
                        $inm = $pdo->prepare('SELECT name FROM items WHERE id=?'); $inm->execute([$txn['item_id']]); echo e($inm->fetchColumn());
                    ?></div></div>
                    <div class="text-right"><div class="text-[12px] font-medium text-green-600">+<?= (int) $txn['quantity'] ?></div><div class="text-[10px] text-text-muted"><?= e($txn['date']) ?></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($recentInward)): ?><p class="text-[12px] text-text-muted">No inward movements yet</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
new Chart(document.getElementById('stockDonut'), {
    type: 'doughnut',
    data: {
        labels: ['In Stock', 'Low Stock', 'Critical', 'Out of Stock'],
        datasets: [{ data: [<?= $inStockCount ?>, <?= $lowStockCount ?>, <?= $criticalCount ?>, <?= $outOfStockCount ?>], backgroundColor: ['#059669', '#D97706', '#DC2626', '#9CA3AF'], borderWidth: 0 }],
    },
    options: {
        maintainAspectRatio: false,
        responsive: true,
        cutout: '68%',
        layout: { padding: 0 },
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ctx.label + ': ' + ctx.parsed + ' items' } } },
    },
});
document.addEventListener('click', function (e) {
    if (!e.target.closest('.item-menu') && !e.target.closest('button[onclick*="item-menu"]')) {
        document.querySelectorAll('.item-menu').forEach(function (m) { if (!m.contains(e.target)) m.classList.add('hidden'); });
    }
});
</script>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
