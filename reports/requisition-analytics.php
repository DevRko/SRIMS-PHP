<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN']);
$user = currentUser();
$currentPath = '/reports/requisition-analytics';

// ─── Per-item demand stats ──────────────────────────────────────────────────
$itemRows = $pdo->query(
    "SELECT ri.item_id, i.name AS item_name, i.icon_key, SUM(ri.requested_qty) AS total_requested,
     SUM(ri.issued_qty) AS total_issued, COUNT(*) AS requisition_count
     FROM requisition_items ri JOIN items i ON i.id = ri.item_id
     GROUP BY ri.item_id, i.name, i.icon_key"
)->fetchAll();
foreach ($itemRows as &$r) {
    $r['fulfillment_rate'] = $r['total_requested'] > 0 ? round(($r['total_issued'] / $r['total_requested']) * 100) : 0;
}
unset($r);

// ─── Per-user demand stats ──────────────────────────────────────────────────
// NOTE: joins departments via the user's *current* department (u.department_id),
// not the department stored on each individual requisition (r.department_id).
// Joining on r.department_id would split one user's requisitions across
// multiple rows/bars whenever they were raised under different departments —
// exactly the "duplicate name" bug this replaces.
$userRows = $pdo->query(
    "SELECT r.user_id, u.name AS user_name, d.name AS department_name, COUNT(*) AS requisition_count,
     SUM((SELECT COALESCE(SUM(requested_qty),0) FROM requisition_items WHERE requisition_id = r.id)) AS total_items_requested,
     SUM(r.total_amount) AS total_value
     FROM requisitions r JOIN users u ON u.id = r.user_id LEFT JOIN departments d ON d.id = u.department_id
     GROUP BY r.user_id, u.name, d.name"
)->fetchAll();

$sortDesc = fn(array $arr, string $key) => (function ($a) use ($key) { usort($a, fn($x, $y) => $y[$key] <=> $x[$key]); return $a; })($arr);

$byRequested = $sortDesc($itemRows, 'total_requested');
$highestDemandItem = $byRequested[0] ?? null;
$lowestDemandCandidates = array_values(array_filter($itemRows, fn($i) => $i['total_requested'] > 0));
usort($lowestDemandCandidates, fn($a, $b) => $a['total_requested'] <=> $b['total_requested']);
$lowestDemandItem = $lowestDemandCandidates[0] ?? null;

$byReqCount = $sortDesc($userRows, 'requisition_count');
$topRequester = $byReqCount[0] ?? null;
$lowestReqCandidates = $userRows;
usort($lowestReqCandidates, fn($a, $b) => $a['requisition_count'] <=> $b['requisition_count']);
$lowestRequester = $lowestReqCandidates[0] ?? null;

$chartItems = array_slice($byRequested, 0, 8);
$chartUsers = array_slice($byReqCount, 0, 8);

$itemSearch = trim($_GET['item_q'] ?? '');
$itemSort = $_GET['item_sort'] ?? 'total_requested';
$userSearch = trim($_GET['user_q'] ?? '');
$userSort = $_GET['user_sort'] ?? 'requisition_count';

$filteredItemStats = $itemRows;
if ($itemSearch) { $q = mb_strtolower($itemSearch); $filteredItemStats = array_values(array_filter($filteredItemStats, fn($i) => str_contains(mb_strtolower($i['item_name']), $q))); }
usort($filteredItemStats, fn($a, $b) => $b[$itemSort] <=> $a[$itemSort]);

$filteredUserStats = $userRows;
if ($userSearch) { $q = mb_strtolower($userSearch); $filteredUserStats = array_values(array_filter($filteredUserStats, fn($u) => str_contains(mb_strtolower($u['user_name']), $q) || str_contains(mb_strtolower($u['department_name']), $q))); }
usort($filteredUserStats, fn($a, $b) => $b[$userSort] <=> $a[$userSort]);

$pageTitle = 'Requisition Analytics';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Requisition Analytics</h1><p class="text-page-subtitle text-text-secondary mt-1">Track item demand and individual user request patterns across the system</p></div>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-green-bg"><i data-lucide="trending-up" class="text-tint-green-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Highest Demand Item</p><p class="mt-1 text-[16px] font-bold text-text-primary"><?= e($highestDemandItem['item_name'] ?? '—') ?></p><?php if ($highestDemandItem): ?><p class="text-card-delta text-green-600"><?= (int)$highestDemandItem['total_requested'] ?> units requested</p><?php endif; ?></div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-amber-bg"><i data-lucide="trending-down" class="text-tint-amber-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Lowest Demand Item</p><p class="mt-1 text-[16px] font-bold text-text-primary"><?= e($lowestDemandItem['item_name'] ?? '—') ?></p><?php if ($lowestDemandItem): ?><p class="text-card-delta text-amber-600"><?= (int)$lowestDemandItem['total_requested'] ?> units requested</p><?php endif; ?></div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-blue-bg"><i data-lucide="archive" class="text-tint-blue-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Top Requester</p><p class="mt-1 text-[16px] font-bold text-text-primary"><?= e($topRequester['user_name'] ?? '—') ?></p><?php if ($topRequester): ?><p class="text-card-delta text-green-600"><?= (int)$topRequester['requisition_count'] ?> requisitions</p><?php endif; ?></div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-purple-bg"><i data-lucide="package" class="text-tint-purple-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Lowest Requester</p><p class="mt-1 text-[16px] font-bold text-text-primary"><?= e($lowestRequester['user_name'] ?? '—') ?></p><?php if ($lowestRequester): ?><p class="text-card-delta text-amber-600"><?= (int)$lowestRequester['requisition_count'] ?> requisition<?= $lowestRequester['requisition_count'] != 1 ? 's' : '' ?></p><?php endif; ?></div>
    </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <h3 class="mb-4 text-[15px] font-semibold text-text-primary">Top Items by Demand</h3>
        <div class="h-[280px]"><canvas id="itemsChart"></canvas></div>
    </div>
    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <h3 class="mb-4 text-[15px] font-semibold text-text-primary">Requisitions by User</h3>
        <div class="h-[280px]"><canvas id="usersChart"></canvas></div>
    </div>
</div>

<div class="mb-6 rounded-card border border-border bg-surface-card">
    <form method="GET" class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
        <h3 class="text-[14px] font-semibold text-text-primary">Item Demand Breakdown</h3>
        <div class="flex items-center gap-2">
            <div class="relative"><i data-lucide="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-text-muted" style="width:14px;height:14px"></i>
                <input type="text" name="item_q" value="<?= e($itemSearch) ?>" placeholder="Search items..." class="w-44 rounded-button border border-border py-1.5 pl-8 pr-2 text-[12px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none">
            </div>
            <select name="item_sort" onchange="this.form.submit()" class="rounded-button border border-border px-2 py-1.5 text-[12px] text-text-secondary focus:border-brand-primary focus:outline-none">
                <option value="total_requested" <?= $itemSort === 'total_requested' ? 'selected' : '' ?>>Sort: Total Requested</option>
                <option value="total_issued" <?= $itemSort === 'total_issued' ? 'selected' : '' ?>>Sort: Total Issued</option>
                <option value="fulfillment_rate" <?= $itemSort === 'fulfillment_rate' ? 'selected' : '' ?>>Sort: Fulfillment Rate</option>
                <option value="requisition_count" <?= $itemSort === 'requisition_count' ? 'selected' : '' ?>>Sort: # Requisitions</option>
            </select>
            <button type="submit" class="hidden"></button>
        </div>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Total Requested</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Total Issued</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Fulfillment Rate</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary"># Requisitions</th>
            </tr></thead>
            <tbody>
                <?php if (empty($filteredItemStats)): ?>
                <tr><td colspan="5" class="px-4 py-10 text-center text-[13px] text-text-muted">No matching items</td></tr>
                <?php else: foreach ($filteredItemStats as $item): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-2.5"><div class="flex items-center gap-2"><?= itemIconSvg($item['icon_key'], $item['item_id'], 22) ?><span class="text-[13px] text-text-primary"><?= e($item['item_name']) ?></span></div></td>
                    <td class="px-4 py-2.5 text-right text-[13px] font-medium text-text-primary"><?= (int) $item['total_requested'] ?></td>
                    <td class="px-4 py-2.5 text-right text-[13px] text-text-secondary"><?= (int) $item['total_issued'] ?></td>
                    <td class="px-4 py-2.5 text-right"><span class="text-[13px] font-medium <?= $item['fulfillment_rate'] >= 90 ? 'text-green-600' : ($item['fulfillment_rate'] >= 50 ? 'text-amber-600' : 'text-red-600') ?>"><?= (int) $item['fulfillment_rate'] ?>%</span></td>
                    <td class="px-4 py-2.5 text-right text-[13px] text-text-secondary"><?= (int) $item['requisition_count'] ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="rounded-card border border-border bg-surface-card">
    <form method="GET" class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
        <h3 class="text-[14px] font-semibold text-text-primary">User Demand Patterns <span class="ml-2 text-[11px] font-normal text-text-muted">(users with at least one requisition)</span></h3>
        <div class="flex items-center gap-2">
            <div class="relative"><i data-lucide="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 text-text-muted" style="width:14px;height:14px"></i>
                <input type="text" name="user_q" value="<?= e($userSearch) ?>" placeholder="Search users..." class="w-44 rounded-button border border-border py-1.5 pl-8 pr-2 text-[12px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none">
            </div>
            <select name="user_sort" onchange="this.form.submit()" class="rounded-button border border-border px-2 py-1.5 text-[12px] text-text-secondary focus:border-brand-primary focus:outline-none">
                <option value="requisition_count" <?= $userSort === 'requisition_count' ? 'selected' : '' ?>>Sort: # Requisitions</option>
                <option value="total_items_requested" <?= $userSort === 'total_items_requested' ? 'selected' : '' ?>>Sort: Total Items</option>
                <option value="total_value" <?= $userSort === 'total_value' ? 'selected' : '' ?>>Sort: Total Value</option>
            </select>
            <button type="submit" class="hidden"></button>
        </div>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">User</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary"># Requisitions</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Total Items Requested</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Total Value</th>
            </tr></thead>
            <tbody>
                <?php if (empty($filteredUserStats)): ?>
                <tr><td colspan="5" class="px-4 py-10 text-center text-[13px] text-text-muted">No matching users</td></tr>
                <?php else: foreach ($filteredUserStats as $u): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-2.5 text-[13px] font-medium text-text-primary"><?= e($u['user_name']) ?></td>
                    <td class="px-4 py-2.5 text-[13px] text-text-secondary"><?= e($u['department_name']) ?></td>
                    <td class="px-4 py-2.5 text-right text-[13px] text-text-primary"><?= (int) $u['requisition_count'] ?></td>
                    <td class="px-4 py-2.5 text-right text-[13px] text-text-secondary"><?= (int) $u['total_items_requested'] ?></td>
                    <td class="px-4 py-2.5 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $u['total_value']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
new Chart(document.getElementById('itemsChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($chartItems, 'item_name')) ?>,
        datasets: [
            { label: 'Requested', data: <?= json_encode(array_map('intval', array_column($chartItems, 'total_requested'))) ?>, backgroundColor: '#2563EB', borderRadius: 4 },
            { label: 'Issued', data: <?= json_encode(array_map('intval', array_column($chartItems, 'total_issued'))) ?>, backgroundColor: '#10B981', borderRadius: 4 },
        ],
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 11 } } } }, scales: { x: { ticks: { font: { size: 10 } } }, y: { beginAtZero: true, ticks: { precision: 0 } } } },
});
new Chart(document.getElementById('usersChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($chartUsers, 'user_name')) ?>,
        datasets: [{ label: 'Requisitions', data: <?= json_encode(array_map('intval', array_column($chartUsers, 'requisition_count'))) ?>, backgroundColor: <?= json_encode(array_map(fn($i) => $i === 0 ? '#2563EB' : '#93C5FD', array_keys($chartUsers))) ?>, borderRadius: 4 }],
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } },
});
</script>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
