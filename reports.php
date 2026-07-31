<?php
require_once __DIR__ . '/config.php';
requireLogin();
requireRole(['ADMIN', 'APPROVER', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/reports';

$view = $_GET['view'] ?? null; // null | inventory | requisitions | audit
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

function csvDownload(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

if (($_GET['export'] ?? '') === 'inventory') {
    $items = $pdo->query('SELECT i.*, c.name AS category_name FROM items i LEFT JOIN categories c ON c.id = i.category_id')->fetchAll();
    $rows = array_map(fn($i) => [$i['id'], $i['name'], $i['category_name'], $i['current_stock'], $i['min_stock_level'], getStockStatus($i), number_format($i['unit_price'], 2), number_format($i['current_stock'] * $i['unit_price'], 2)], $items);
    csvDownload('inventory-report.csv', ['Item ID', 'Name', 'Category', 'Current Stock', 'Min Level', 'Status', 'Unit Price', 'Value'], $rows);
}
if (($_GET['export'] ?? '') === 'requisitions') {
    $sql = 'SELECT r.*, u.name AS user_name, d.name AS department_name FROM requisitions r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=r.department_id WHERE 1=1';
    $params = [];
    if ($dateFrom) { $sql .= ' AND r.created_at >= ?'; $params[] = $dateFrom; }
    if ($dateTo) { $sql .= ' AND r.created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $rows = array_map(fn($r) => [$r['id'], $r['user_name'], $r['department_name'], formatDate($r['created_at']), $r['purpose'], number_format($r['total_amount'], 2), $r['status']], $stmt->fetchAll());
    csvDownload('requisition-history.csv', ['REQ No.', 'Requested By', 'Department', 'Date', 'Purpose', 'Amount', 'Status'], $rows);
}
if (($_GET['export'] ?? '') === 'audit' && $user['role'] === 'ADMIN') {
    $logs = $pdo->query('SELECT al.*, u.name AS actor_name FROM audit_logs al JOIN users u ON u.id = al.actor_id ORDER BY al.timestamp DESC')->fetchAll();
    $rows = array_map(fn($l) => [formatDateTime($l['timestamp']), $l['actor_name'], $l['action'], $l['entity'], $l['entity_id'], $l['after_json']], $logs);
    csvDownload('audit-trail.csv', ['Timestamp', 'Actor', 'Action', 'Entity', 'Entity ID', 'Details'], $rows);
}

$pageTitle = 'Reports';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/app-shell-start.php';
?>

<?php if (!$view): ?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Reports</h1><p class="text-page-subtitle text-text-secondary mt-1">Generate and export reports across the system</p></div>
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <a href="?view=inventory" class="rounded-card border border-border bg-surface-card p-card-padding text-left hover:border-brand-primary hover:shadow-sm transition-all block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-blue-bg"><i data-lucide="package" class="text-tint-blue-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[15px] font-semibold text-text-primary mb-1">Inventory Reports</h3><p class="text-[12px] text-text-secondary">Stock status, fast-moving items, and valuation</p>
    </a>
    <a href="?view=requisitions" class="rounded-card border border-border bg-surface-card p-card-padding text-left hover:border-brand-primary hover:shadow-sm transition-all block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-green-bg"><i data-lucide="file-text" class="text-tint-green-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[15px] font-semibold text-text-primary mb-1">Requisition History</h3><p class="text-[12px] text-text-secondary">Filterable by date, department, user, or category</p>
    </a>
    <a href="?view=audit" class="rounded-card border border-border bg-surface-card p-card-padding text-left hover:border-brand-primary hover:shadow-sm transition-all block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-purple-bg"><i data-lucide="scroll-text" class="text-tint-purple-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[15px] font-semibold text-text-primary mb-1">Audit Trail</h3><p class="text-[12px] text-text-secondary">Who did what, when, across the whole system</p>
    </a>
    <?php if ($user['role'] === 'ADMIN'): ?>
    <a href="?view=users" class="rounded-card border border-border bg-surface-card p-card-padding text-left hover:border-brand-primary hover:shadow-sm transition-all block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-green-bg"><i data-lucide="users" class="text-tint-green-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[15px] font-semibold text-text-primary mb-1">User-wise Report</h3><p class="text-[12px] text-text-secondary">Every user's requisition activity, drill into full history — Admin only</p>
    </a>
    <a href="reports/requisition-analytics.php" class="rounded-card border border-border bg-surface-card p-card-padding text-left hover:border-brand-primary hover:shadow-sm transition-all block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-amber-bg"><i data-lucide="trending-up" class="text-tint-amber-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[15px] font-semibold text-text-primary mb-1">Requisition Analytics</h3><p class="text-[12px] text-text-secondary">Item demand patterns and per-user statistics — Admin only</p>
    </a>
    <?php endif; ?>
</div>

<?php elseif ($view === 'users' && $user['role'] === 'ADMIN'):
    $deptFilter = $_GET['department'] ?? '';
    $roleFilter = $_GET['role'] ?? '';
    $userSearch = trim($_GET['user_q'] ?? '');

    $sql = "SELECT u.*, d.name AS department_name, COUNT(r.id) AS requisition_count,
            COALESCE(SUM(r.total_amount), 0) AS total_value, MAX(r.created_at) AS last_requisition_at
            FROM users u LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN requisitions r ON r.user_id = u.id WHERE 1=1";
    $params = [];
    if ($deptFilter) { $sql .= ' AND u.department_id = ?'; $params[] = $deptFilter; }
    if ($roleFilter) { $sql .= ' AND u.role = ?'; $params[] = $roleFilter; }
    if ($userSearch) { $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $like = "%$userSearch%"; array_push($params, $like, $like); }
    $sql .= ' GROUP BY u.id ORDER BY u.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $userRows = $stmt->fetchAll();

    $departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();
    $roleLabels = ['ADMIN' => 'Administrator', 'USER' => 'Employee', 'APPROVER' => 'Approver', 'INVENTORY_MGR' => 'Inventory Manager'];
?>
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">User-wise Report</h1><p class="text-page-subtitle text-text-secondary mt-1">Requisition activity by user — click a name for their full history</p></div>
    <a href="?" class="flex items-center gap-1.5 rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
</div>
<div class="rounded-card border border-border bg-surface-card">
    <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
        <input type="hidden" name="view" value="users">
        <div class="relative flex-1 min-w-[180px]">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:14px;height:14px"></i>
            <input type="text" name="user_q" value="<?= e($userSearch) ?>" placeholder="Search name or email..." class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none">
        </div>
        <select name="department" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary focus:border-brand-primary focus:outline-none">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>" <?= $deptFilter === $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <select name="role" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary focus:border-brand-primary focus:outline-none">
            <option value="">All Roles</option>
            <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $roleFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="hidden"></button>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">User</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Role</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary"># Requisitions</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Total Value</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Last Activity</th>
            </tr></thead>
            <tbody>
                <?php if (empty($userRows)): ?>
                <tr><td colspan="6" class="px-4 py-12 text-center text-[14px] text-text-muted">No matching users</td></tr>
                <?php else: foreach ($userRows as $u): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50 cursor-pointer" onclick="window.location.href='?view=user-detail&amp;user_id=<?= e($u['id']) ?>'">
                    <td class="px-4 py-3"><span class="text-[13px] font-medium text-brand-primary hover:underline"><?= e($u['name']) ?></span><div class="text-[11px] text-text-muted"><?= e($u['email']) ?></div></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($u['department_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-primary"><?= (int) $u['requisition_count'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $u['total_value']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= $u['last_requisition_at'] ? formatDate($u['last_requisition_at']) : '—' ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($view === 'user-detail' && $user['role'] === 'ADMIN'):
    $targetUserId = $_GET['user_id'] ?? '';
    $targetStmt = $pdo->prepare('SELECT u.*, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?');
    $targetStmt->execute([$targetUserId]);
    $targetUser = $targetStmt->fetch();

    if (!$targetUser) {
        echo '<div class="rounded-card border border-border bg-surface-card p-8 text-center"><p class="text-[14px] text-text-muted">User not found.</p><a href="?view=users" class="mt-3 inline-block text-[13px] text-brand-primary hover:underline">Back to User-wise Report</a></div>';
    } else {
        $dateFrom2 = $_GET['from'] ?? ''; $dateTo2 = $_GET['to'] ?? '';
        $statusFilter2 = $_GET['status'] ?? '';
        $sql = 'SELECT r.*, d.name AS department_name FROM requisitions r LEFT JOIN departments d ON d.id = r.department_id WHERE r.user_id = ?';
        $params = [$targetUserId];
        if ($dateFrom2) { $sql .= ' AND r.created_at >= ?'; $params[] = $dateFrom2; }
        if ($dateTo2) { $sql .= ' AND r.created_at <= ?'; $params[] = $dateTo2 . ' 23:59:59'; }
        if ($statusFilter2) { $sql .= ' AND r.status = ?'; $params[] = $statusFilter2; }
        $sql .= ' ORDER BY r.created_at DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $userReqs = $stmt->fetchAll();
        $userTotalValue = array_sum(array_column($userReqs, 'total_amount'));
?>
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary"><?= e($targetUser['name']) ?></h1><p class="text-page-subtitle text-text-secondary mt-1"><?= e($targetUser['email']) ?> · <?= e($targetUser['department_name']) ?></p></div>
    <a href="?view=users" class="flex items-center gap-1.5 rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back to Users</a>
</div>

<div class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-3">
    <div class="rounded-card border border-border bg-surface-card p-4"><p class="text-card-label text-text-secondary">Total Requisitions</p><p class="mt-1 text-card-value text-text-primary"><?= count($userReqs) ?></p></div>
    <div class="rounded-card border border-border bg-surface-card p-4"><p class="text-card-label text-text-secondary">Total Value</p><p class="mt-1 text-card-value text-text-primary"><?= formatCurrency($userTotalValue) ?></p></div>
    <div class="rounded-card border border-border bg-surface-card p-4"><p class="text-card-label text-text-secondary">Role</p><p class="mt-1 text-[16px] font-bold text-text-primary"><?= e($roleLabels[$targetUser['role']] ?? $targetUser['role']) ?></p></div>
</div>

<form method="GET" class="mb-4 flex flex-wrap gap-3">
    <input type="hidden" name="view" value="user-detail"><input type="hidden" name="user_id" value="<?= e($targetUserId) ?>">
    <div><label class="mb-1 block text-[12px] font-medium text-text-primary">From</label><input type="date" name="from" value="<?= e($dateFrom2) ?>" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none"></div>
    <div><label class="mb-1 block text-[12px] font-medium text-text-primary">To</label><input type="date" name="to" value="<?= e($dateTo2) ?>" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none"></div>
    <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Status</label>
        <select name="status" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none">
            <option value="">All</option>
            <?php foreach (['DRAFT', 'PENDING', 'APPROVED', 'REJECTED', 'ISSUED', 'PARTIAL'] as $s): ?>
            <option value="<?= $s ?>" <?= $statusFilter2 === $s ? 'selected' : '' ?>><?= ucfirst(strtolower($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Date</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Purpose</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
            </tr></thead>
            <tbody>
                <?php if (empty($userReqs)): ?>
                <tr><td colspan="6" class="px-4 py-12 text-center text-[14px] text-text-muted">No requisitions in this range</td></tr>
                <?php else: foreach ($userReqs as $r): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3"><button type="button" onclick="openReqModal('<?= e($r['id']) ?>')" class="text-[13px] font-medium text-brand-primary hover:underline"><?= e($r['id']) ?></button></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($r['department_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDate($r['created_at']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($r['purpose']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $r['total_amount']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill(requisitionStatusVariant($r['status'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
require_once __DIR__ . '/includes/requisition-modal.php';
foreach ($userReqs as $r) { renderRequisitionModal($pdo, array_merge($r, ['user_name' => $targetUser['name']])); }
    }
?>

<?php elseif ($view === 'inventory'):
    $items = $pdo->query('SELECT i.*, c.name AS category_name FROM items i LEFT JOIN categories c ON c.id = i.category_id ORDER BY i.name')->fetchAll();
    $totalValue = array_sum(array_map(fn($i) => $i['current_stock'] * $i['unit_price'], $items));
?>
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Inventory Reports</h1><p class="text-page-subtitle text-text-secondary mt-1">Stock status, valuation, and threshold breakdown</p></div>
    <div class="flex gap-2">
        <a href="?" class="flex items-center gap-1.5 rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
        <a href="?export=inventory" class="flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="download" style="width:14px;height:14px"></i> Export CSV</a>
    </div>
</div>
<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Category</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Stock</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Min Level</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Value</th>
            </tr></thead>
            <tbody>
                <?php foreach ($items as $i): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="px-4 py-3 text-[13px] font-medium text-text-primary"><?= e($i['name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($i['category_name']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-primary"><?= (int) $i['current_stock'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-secondary"><?= (int) $i['min_stock_level'] ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill(stockStatusVariant(getStockStatus($i))) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency($i['current_stock'] * $i['unit_price']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="border-t-2 border-border bg-gray-50">
                <td colspan="5" class="px-4 py-3 text-right text-[13px] font-semibold text-text-primary">Total Inventory Value</td>
                <td class="px-4 py-3 text-right text-[14px] font-bold text-brand-primary"><?= formatCurrency($totalValue) ?></td>
            </tr></tfoot>
        </table>
    </div>
</div>

<?php elseif ($view === 'requisitions'):
    $sql = 'SELECT r.*, u.name AS user_name, d.name AS department_name FROM requisitions r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=r.department_id WHERE 1=1';
    $params = [];
    if ($dateFrom) { $sql .= ' AND r.created_at >= ?'; $params[] = $dateFrom; }
    if ($dateTo) { $sql .= ' AND r.created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
    $sql .= ' ORDER BY r.created_at DESC';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $filteredRequisitions = $stmt->fetchAll();
?>
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Requisition History</h1><p class="text-page-subtitle text-text-secondary mt-1">Filterable record of all requisitions</p></div>
    <div class="flex gap-2">
        <a href="?" class="flex items-center gap-1.5 rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
        <a href="?view=requisitions&export=requisitions&from=<?= e($dateFrom) ?>&to=<?= e($dateTo) ?>" class="flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="download" style="width:14px;height:14px"></i> Export CSV</a>
    </div>
</div>
<form method="GET" class="mb-4 flex gap-3">
    <input type="hidden" name="view" value="requisitions">
    <div><label class="mb-1 block text-[12px] font-medium text-text-primary">From</label><input type="date" name="from" value="<?= e($dateFrom) ?>" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none"></div>
    <div><label class="mb-1 block text-[12px] font-medium text-text-primary">To</label><input type="date" name="to" value="<?= e($dateTo) ?>" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none"></div>
</form>
<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Requested By</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Date</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
            </tr></thead>
            <tbody>
                <?php if (empty($filteredRequisitions)): ?>
                <tr><td colspan="6" class="px-4 py-12 text-center text-[14px] text-text-muted">No requisitions in this range</td></tr>
                <?php else: foreach ($filteredRequisitions as $r): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="px-4 py-3"><button type="button" onclick="openReqModal('<?= e($r['id']) ?>')" class="text-[13px] font-medium text-brand-primary hover:underline"><?= e($r['id']) ?></button></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($r['user_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($r['department_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDate($r['created_at']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $r['total_amount']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill(requisitionStatusVariant($r['status'])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
require_once __DIR__ . '/includes/requisition-modal.php';
foreach ($filteredRequisitions as $r) { renderRequisitionModal($pdo, $r); }
?>

<?php elseif ($view === 'audit'):
    $logs = $pdo->query('SELECT al.*, u.name AS actor_name FROM audit_logs al JOIN users u ON u.id = al.actor_id ORDER BY al.timestamp DESC LIMIT 200')->fetchAll();
?>
<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Audit Trail</h1><p class="text-page-subtitle text-text-secondary mt-1">Complete history of system actions</p></div>
    <div class="flex gap-2">
        <a href="?" class="flex items-center gap-1.5 rounded-button border border-border px-3 py-2 text-[13px] text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
        <?php if ($user['role'] === 'ADMIN'): ?>
        <a href="?view=audit&export=audit" class="flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="download" style="width:14px;height:14px"></i> Export CSV</a>
        <?php endif; ?>
    </div>
</div>
<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Timestamp</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Actor</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Entity</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Details</th>
            </tr></thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="px-4 py-3 text-[13px] text-text-secondary whitespace-nowrap"><?= formatDateTime($log['timestamp']) ?></td>
                    <td class="px-4 py-3 text-[13px] font-medium text-text-primary"><?= e($log['actor_name']) ?></td>
                    <td class="px-4 py-3 text-[12px] text-text-secondary"><?= e(str_replace('_', ' ', $log['action'])) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($log['entity']) ?> #<?= e($log['entity_id']) ?></td>
                    <td class="px-4 py-3 text-[12px] text-text-secondary"><?= e($log['after_json']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/app-shell-end.php'; ?>
