<?php
require_once __DIR__ . '/config.php';
requireLogin();

$user = currentUser();

// Employee guard (matches the useEffect redirect in dashboard/page.tsx)
if ($user['role'] === 'USER') {
    header('Location: ' . BASE_URL . '/requisitions/my.php');
    exit;
}

$currentPath = '/dashboard';

// ─── Stat values ────────────────────────────────────────────────────────
$totalReqs    = (int) $pdo->query('SELECT COUNT(*) FROM requisitions')->fetchColumn();
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'PENDING'")->fetchColumn();
$issuedCount  = (int) $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status IN ('ISSUED','PARTIAL')")->fetchColumn();

$items = $pdo->query('SELECT * FROM items')->fetchAll();
$lowStockCount = 0;
$lowStockItems = [];
foreach ($items as $it) {
    $status = getStockStatus($it);
    if ($status !== 'IN_STOCK') {
        $lowStockCount++;
        $it['stockStatus'] = $status;
        $lowStockItems[] = $it;
    }
}
usort($lowStockItems, fn($a, $b) => $a['current_stock'] <=> $b['current_stock']);
$lowStockItems = array_slice($lowStockItems, 0, 4);

// ─── Trend chart data (matches buildTrend() in dashboard/page.tsx) ───────
$allReqs = $pdo->query('SELECT id, status, created_at FROM requisitions ORDER BY created_at ASC')->fetchAll();
$trendLabels = [];
$trendSubmitted = [];
$trendIssued = [];
if (!empty($allReqs)) {
    $fromTs = strtotime($allReqs[0]['created_at']);
    $toTs   = strtotime($allReqs[count($allReqs) - 1]['created_at']);
    $spanDays = ($toTs - $fromTs) / 86400;
    $gran = $spanDays < 31 ? 'day' : ($spanDays < 91 ? 'week' : 'month');

    $buckets = [];
    foreach ($allReqs as $r) {
        $ts = strtotime($r['created_at']);
        if ($gran === 'day') {
            $key = date('d M', $ts);
        } elseif ($gran === 'week') {
            $dow = (int) date('N', $ts); // 1(Mon)..7(Sun)
            $mon = $ts - (($dow - 1) * 86400);
            $key = date('d M', $mon);
        } else {
            $key = date('M y', $ts);
        }
        if (!isset($buckets[$key])) $buckets[$key] = ['submitted' => 0, 'issued' => 0];
        $buckets[$key]['submitted']++;
        if (in_array($r['status'], ['ISSUED', 'PARTIAL'], true)) $buckets[$key]['issued']++;
    }
    foreach ($buckets as $label => $v) {
        $trendLabels[] = $label;
        $trendSubmitted[] = $v['submitted'];
        $trendIssued[] = $v['issued'];
    }
}

// ─── Recent data ──────────────────────────────────────────────────────────
$recentReqs = $pdo->query(
    "SELECT r.*, u.name AS user_name FROM requisitions r JOIN users u ON u.id = r.user_id
     ORDER BY r.created_at DESC LIMIT 5"
)->fetchAll();

$recentTxns = $pdo->query(
    "SELECT st.*, i.name AS item_name, i.icon_key FROM stock_transactions st
     JOIN items i ON i.id = st.item_id ORDER BY st.date DESC, st.id DESC LIMIT 5"
)->fetchAll();

$pageTitle = 'Dashboard';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/app-shell-start.php';
?>

<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h1 class="text-page-title text-text-primary">Dashboard</h1>
        <p class="text-page-subtitle text-text-secondary mt-1">Live overview of requisitions and inventory</p>
    </div>
</div>

<!-- Row 1: Stat cards -->
<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-blue-bg">
            <i data-lucide="archive" class="text-tint-blue-icon" style="width:20px;height:20px"></i>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-card-label text-text-secondary">Total Requisitions</p>
            <p class="mt-1 text-card-value text-text-primary"><?= $totalReqs ?></p>
        </div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-amber-bg">
            <i data-lucide="hourglass" class="text-tint-amber-icon" style="width:20px;height:20px"></i>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-card-label text-text-secondary">Pending Approvals</p>
            <p class="mt-1 text-card-value text-text-primary"><?= $pendingCount ?></p>
        </div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-green-bg">
            <i data-lucide="package-check" class="text-tint-green-icon" style="width:20px;height:20px"></i>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-card-label text-text-secondary">Issued / Partial</p>
            <p class="mt-1 text-card-value text-text-primary"><?= $issuedCount ?></p>
        </div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-red-bg">
            <i data-lucide="alert-triangle" class="text-tint-red-icon" style="width:20px;height:20px"></i>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-card-label text-text-secondary">Low Stock Items</p>
            <p class="mt-1 text-card-value text-text-primary"><?= $lowStockCount ?></p>
        </div>
    </div>
</div>

<!-- Row 2: Trend chart + Low Stock -->
<div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-5">
    <div class="col-span-1 rounded-card border border-border bg-surface-card p-4 lg:col-span-3">
        <h3 class="mb-0.5 text-[14px] font-semibold text-text-primary">Requisition Trend</h3>
        <p class="mb-3 text-[11px] text-text-muted">All requisitions to date</p>
        <div class="h-[190px]"><canvas id="trendChart"></canvas></div>
    </div>

    <div class="col-span-1 rounded-card border border-border bg-surface-card p-4 lg:col-span-2">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-[14px] font-semibold text-text-primary">Low Stock Alerts</h3>
            <a href="<?= BASE_URL ?>/inventory/low-stock.php" class="text-[12px] font-medium text-brand-primary hover:underline">View All</a>
        </div>
        <?php if (empty($lowStockItems)): ?>
        <div class="flex h-[155px] items-center justify-center text-[12px] text-text-muted">All items sufficiently stocked ✓</div>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($lowStockItems as $item): ?>
            <div class="flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2">
                <?= itemIconSvg($item['icon_key'], $item['id'], 20) ?>
                <span class="flex-1 truncate text-[12px] font-medium text-text-primary"><?= e($item['name']) ?></span>
                <span class="text-[11px] text-text-muted"><?= (int)$item['current_stock'] ?>/<?= (int)$item['min_stock_level'] ?></span>
                <?= statusPill(stockStatusVariant($item['stockStatus'])) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Row 3: Recent Requisitions + Recent Transactions -->
<div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
    <div class="rounded-card border border-border bg-surface-card p-4">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-[14px] font-semibold text-text-primary">Recent Requisitions</h3>
            <a href="<?= BASE_URL ?>/requisitions/my.php" class="text-[12px] font-medium text-brand-primary hover:underline">View All</a>
        </div>
        <table class="w-full">
            <thead>
                <tr class="border-b border-border">
                    <th class="pb-2 text-left text-[10px] font-semibold uppercase tracking-wide text-text-secondary">REQ No.</th>
                    <th class="pb-2 text-left text-[10px] font-semibold uppercase tracking-wide text-text-secondary">By</th>
                    <th class="pb-2 text-right text-[10px] font-semibold uppercase tracking-wide text-text-secondary">Amount</th>
                    <th class="pb-2 text-right text-[10px] font-semibold uppercase tracking-wide text-text-secondary">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentReqs)): ?>
                <tr><td colspan="4" class="py-8 text-center text-[12px] text-text-muted">No requisitions yet</td></tr>
                <?php else: foreach ($recentReqs as $req):
                    $firstName = explode(' ', $req['user_name'])[0];
                ?>
                <tr class="border-b border-border last:border-0">
                    <td class="py-2"><a href="<?= BASE_URL ?>/requisitions/my.php" class="text-[12px] font-medium text-brand-primary hover:underline"><?= e($req['id']) ?></a></td>
                    <td class="py-2 text-[12px] text-text-secondary"><?= e($firstName) ?></td>
                    <td class="py-2 text-right text-[12px] font-medium"><?= formatCurrency((float)$req['total_amount']) ?></td>
                    <td class="py-2 text-right"><?= statusPill(requisitionStatusVariant($req['status'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <div class="rounded-card border border-border bg-surface-card p-4">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-[14px] font-semibold text-text-primary">Recent Transactions</h3>
            <a href="<?= BASE_URL ?>/inventory/transactions.php" class="text-[12px] font-medium text-brand-primary hover:underline">View All</a>
        </div>
        <table class="w-full">
            <thead>
                <tr class="border-b border-border">
                    <th class="pb-2 text-left text-[10px] font-semibold uppercase tracking-wide text-text-secondary">Type</th>
                    <th class="pb-2 text-left text-[10px] font-semibold uppercase tracking-wide text-text-secondary">Item</th>
                    <th class="pb-2 text-right text-[10px] font-semibold uppercase tracking-wide text-text-secondary">Qty</th>
                    <th class="pb-2 text-left text-[10px] font-semibold uppercase tracking-wide text-text-secondary">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTxns)): ?>
                <tr><td colspan="4" class="py-8 text-center text-[12px] text-text-muted">No transactions yet</td></tr>
                <?php else: foreach ($recentTxns as $txn): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="py-2"><?= statusPill(txnTypeVariant($txn['type']), ucfirst(strtolower($txn['type']))) ?></td>
                    <td class="py-2">
                        <div class="flex items-center gap-1.5">
                            <?= itemIconSvg($txn['icon_key'], $txn['item_id'], 18) ?>
                            <span class="max-w-[100px] truncate text-[12px] text-text-primary"><?= e($txn['item_name']) ?></span>
                        </div>
                    </td>
                    <td class="py-2 text-right text-[12px] font-medium"><?= (int)$txn['quantity'] ?></td>
                    <td class="py-2 text-[12px] text-text-secondary"><?= formatDate($txn['date']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(function () {
    var ctx = document.getElementById('trendChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trendLabels) ?>,
            datasets: [
                {
                    label: 'Submitted', data: <?= json_encode($trendSubmitted) ?>,
                    borderColor: '#2563EB', backgroundColor: 'rgba(37,99,235,0.12)',
                    fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                },
                {
                    label: 'Issued', data: <?= json_encode($trendIssued) ?>,
                    borderColor: '#10B981', backgroundColor: 'rgba(16,185,129,0.12)',
                    fill: true, tension: 0.35, pointRadius: 0, borderWidth: 2,
                },
            ],
        },
        options: {
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 11 } } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#9CA3AF' } },
                y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 }, color: '#9CA3AF' }, grid: { color: '#F3F4F6' } },
            },
        },
    });
})();
</script>

<?php include __DIR__ . '/includes/app-shell-end.php'; ?>
