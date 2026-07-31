<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user = currentUser();
$currentPath = '/requisitions/my';

// Drafts can be submitted or deleted right from this page too (not just
// the dedicated Drafts page) — same rules: must be your own draft, or you
// must be Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reqId = $_POST['req_id'] ?? '';

    $ownStmt = $pdo->prepare('SELECT * FROM requisitions WHERE id = ? AND status = \'DRAFT\'' . ($user['role'] === 'ADMIN' ? '' : ' AND user_id = ?'));
    $ownStmt->execute($user['role'] === 'ADMIN' ? [$reqId] : [$reqId, $user['id']]);
    $draft = $ownStmt->fetch();

    if ($draft && $action === 'submit') {
        $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM requisition_items WHERE requisition_id = ' . $pdo->quote($reqId))->fetchColumn();
        if ($itemCount > 0) {
            $autoApproved = shouldAutoApprove($pdo, $draft['priority']);
            addAuditLog($pdo, $user['id'], 'SUBMIT', 'Requisition', $reqId, "Submitted by {$user['name']} (priority: {$draft['priority']})");
            if ($autoApproved) {
                $pdo->prepare('UPDATE requisitions SET status=\'APPROVED\', approved_by_id=?, approved_at=NOW(), updated_at=NOW() WHERE id=?')->execute([AUTO_APPROVAL_ACTOR_ID, $reqId]);
                $pdo->prepare('UPDATE requisition_items SET approved_qty = requested_qty WHERE requisition_id = ?')->execute([$reqId]);
                addAuditLog($pdo, $user['id'], 'AUTO_APPROVE', 'Requisition', $reqId, "Auto-approved (priority: {$draft['priority']}) per Auto-Approval Rules");
                flash('success', "{$reqId} submitted and auto-approved.");
            } else {
                $pdo->prepare('UPDATE requisitions SET status=\'PENDING\', updated_at=NOW() WHERE id=?')->execute([$reqId]);
                addNotificationToRoles($pdo, ['ADMIN', 'APPROVER'], "Requisition {$reqId} submitted by {$user['name']} is awaiting your approval", '/approvals/pending');
                flash('success', "{$reqId} submitted for approval.");
            }
        } else {
            flash('error', 'Add at least one item before submitting.');
        }
    } elseif ($draft && $action === 'delete') {
        $pdo->prepare('DELETE FROM requisitions WHERE id = ?')->execute([$reqId]);
        addAuditLog($pdo, $user['id'], 'DELETE', 'Requisition', $reqId, 'Deleted draft requisition');
        flash('success', "Draft {$reqId} deleted.");
    }
    header('Location: my.php' . (isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

$statusFilters = [
    ['label' => 'All', 'value' => null, 'color' => 'slate'],
    ['label' => 'Draft', 'value' => 'DRAFT', 'color' => 'gray'],
    ['label' => 'Pending', 'value' => 'PENDING', 'color' => 'amber'],
    ['label' => 'Approved', 'value' => 'APPROVED', 'color' => 'green'],
    ['label' => 'Rejected', 'value' => 'REJECTED', 'color' => 'red'],
    ['label' => 'Issued', 'value' => 'ISSUED', 'color' => 'blue'],
    ['label' => 'Partial', 'value' => 'PARTIAL', 'color' => 'amber'],
];
$statusFilterColors = [
    'slate' => ['bg' => 'bg-slate-600', 'border' => 'border-slate-600', 'chip' => 'bg-slate-50 text-slate-700 border-slate-200'],
    'gray' => ['bg' => 'bg-gray-600', 'border' => 'border-gray-600', 'chip' => 'bg-gray-100 text-gray-700 border-gray-200'],
    'amber' => ['bg' => 'bg-amber-500', 'border' => 'border-amber-500', 'chip' => 'bg-amber-50 text-amber-700 border-amber-200'],
    'green' => ['bg' => 'bg-green-600', 'border' => 'border-green-600', 'chip' => 'bg-green-50 text-green-700 border-green-200'],
    'red' => ['bg' => 'bg-red-600', 'border' => 'border-red-600', 'chip' => 'bg-red-50 text-red-700 border-red-200'],
    'blue' => ['bg' => 'bg-blue-600', 'border' => 'border-blue-600', 'chip' => 'bg-blue-50 text-blue-700 border-blue-200'],
];

$activeFilter = $_GET['status'] ?? null;
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;
$isAdmin = $user['role'] === 'ADMIN';

$base = $isAdmin ? 'SELECT r.*, u.name AS user_name, (SELECT COUNT(*) FROM requisition_items WHERE requisition_id = r.id) AS item_count FROM requisitions r JOIN users u ON u.id = r.user_id WHERE 1=1'
                 : 'SELECT r.*, u.name AS user_name, (SELECT COUNT(*) FROM requisition_items WHERE requisition_id = r.id) AS item_count FROM requisitions r JOIN users u ON u.id = r.user_id WHERE r.user_id = ?';
$countParams = $isAdmin ? [] : [$user['id']];

// Status counts (base = all user's / all reqs depending on role)
$counts = ['all' => 0, 'DRAFT' => 0, 'PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0, 'ISSUED' => 0, 'PARTIAL' => 0];
$countStmt = $pdo->prepare(($isAdmin ? 'SELECT status, COUNT(*) c FROM requisitions GROUP BY status' : 'SELECT status, COUNT(*) c FROM requisitions WHERE user_id = ? GROUP BY status'));
$countStmt->execute($countParams);
foreach ($countStmt->fetchAll() as $row) { $counts[$row['status']] = (int) $row['c']; $counts['all'] += (int) $row['c']; }

$sql = $base;
$params = $countParams;
if ($activeFilter) { $sql .= ' AND r.status = ?'; $params[] = $activeFilter; }
if ($searchQuery) {
    $sql .= ' AND (r.id LIKE ? OR u.name LIKE ? OR r.purpose LIKE ?)';
    $like = '%' . $searchQuery . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY r.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$myRequisitions = $stmt->fetchAll();

$totalPages = max(1, (int) ceil(count($myRequisitions) / $perPage));
$page = min($page, $totalPages);
$paginated = array_slice($myRequisitions, ($page - 1) * $perPage, $perPage);

$qs = function (array $overrides = []) use ($activeFilter, $searchQuery) {
    $q = array_merge(['status' => $activeFilter, 'q' => $searchQuery], $overrides);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
};

$pageTitle = 'My Requisitions';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h1 class="text-page-title text-text-primary">My Requisitions</h1>
        <p class="text-page-subtitle text-text-secondary mt-1">Track and manage your stationery requisitions</p>
    </div>
    <a href="new.php" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover transition-colors w-fit">
        <i data-lucide="plus" style="width:16px;height:16px"></i> New Requisition
    </a>
</div>

<?php renderFlash(); ?>

<div class="mb-4 flex gap-2 overflow-x-auto pb-1">
    <?php foreach ($statusFilters as $f):
        $count = $f['value'] ? ($counts[$f['value']] ?? 0) : $counts['all'];
        $isActive = $activeFilter === $f['value'];
        $c = $statusFilterColors[$f['color']];
    ?>
    <a href="<?= $qs(['status' => $f['value'], 'page' => null]) ?>" class="flex items-center gap-1.5 whitespace-nowrap rounded-full border px-3 py-1.5 text-[12px] font-medium transition-colors <?= $isActive ? $c['bg'] . ' ' . $c['border'] . ' text-white' : $c['chip'] . ' hover:opacity-80' ?>">
        <?= e($f['label']) ?>
        <span class="inline-flex h-4 min-w-[16px] items-center justify-center rounded-full px-1 text-[10px] font-semibold <?= $isActive ? 'bg-white/20 text-white' : 'bg-white/60 text-current' ?>"><?= $count ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="rounded-card border border-border bg-surface-card">
    <div class="border-b border-border px-4 py-3">
        <form method="GET" class="relative max-w-sm">
            <?php if ($activeFilter): ?><input type="hidden" name="status" value="<?= e($activeFilter) ?>"><?php endif; ?>
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:16px;height:16px"></i>
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search by REQ No., name, or purpose..."
                class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <?php if ($isAdmin): ?><th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Requested By</th><?php endif; ?>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Date</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Purpose</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
            </tr></thead>
            <tbody>
                <?php if (empty($paginated)): ?>
                <tr><td colspan="8" class="px-4 py-12 text-center text-[14px] text-text-muted">No requisitions found</td></tr>
                <?php else: foreach ($paginated as $req): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3"><button type="button" onclick="openReqModal('<?= e($req['id']) ?>')" class="text-[13px] font-medium text-brand-primary hover:underline"><?= e($req['id']) ?></button></td>
                    <?php if ($isAdmin): ?><td class="px-4 py-3 text-[13px] text-text-primary"><?= e($req['user_name']) ?></td><?php endif; ?>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?php
                        $dstmt = $pdo->prepare('SELECT name FROM departments WHERE id = ?'); $dstmt->execute([$req['department_id']]);
                        echo e($dstmt->fetchColumn() ?: '');
                    ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDate($req['created_at']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($req['purpose']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float)$req['total_amount']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill(requisitionStatusVariant($req['status'])) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" onclick="openReqModal('<?= e($req['id']) ?>')" title="View" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="eye" style="width:16px;height:16px"></i></button>
                            <?php if ($req['status'] === 'DRAFT'): ?>
                            <a href="new.php?edit=<?= e($req['id']) ?>" title="Edit" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="pencil" style="width:16px;height:16px"></i></a>
                            <form method="POST" class="inline" onsubmit="return confirm('Submit this draft for approval?')">
                                <input type="hidden" name="action" value="submit"><input type="hidden" name="req_id" value="<?= e($req['id']) ?>">
                                <button type="submit" title="Submit" <?= $req['item_count'] == 0 ? 'disabled' : '' ?> class="rounded p-1.5 text-text-secondary hover:bg-green-50 hover:text-green-600 disabled:opacity-30"><i data-lucide="send" style="width:16px;height:16px"></i></button>
                            </form>
                            <button type="button" onclick="document.getElementById('my-del-confirm-<?= e($req['id']) ?>').classList.remove('hidden'); document.getElementById('my-del-confirm-<?= e($req['id']) ?>').classList.add('flex');" title="Delete" class="rounded p-1.5 text-text-secondary hover:bg-red-50 hover:text-red-600"><i data-lucide="trash-2" style="width:16px;height:16px"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-between border-t border-border px-4 py-3">
        <span class="text-[12px] text-text-secondary">Showing <?= ($page - 1) * $perPage + 1 ?> to <?= min($page * $perPage, count($myRequisitions)) ?> of <?= count($myRequisitions) ?></span>
        <div class="flex items-center gap-1">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= $qs(['page' => $p]) ?>" class="min-w-[28px] rounded px-1.5 py-0.5 text-center text-[12px] font-medium <?= $p === $page ? 'bg-brand-primary text-white' : 'text-text-secondary hover:bg-gray-100' ?>"><?= $p ?></a>
            <?php endfor; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../includes/requisition-modal.php';
foreach ($paginated as $req) {
    renderRequisitionModal($pdo, $req);
    if ($req['status'] === 'DRAFT') {
    ?>
    <div id="my-del-confirm-<?= e($req['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
        <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
            <h3 class="mb-2 text-[15px] font-semibold text-text-primary">Delete draft?</h3>
            <p class="mb-4 text-[13px] text-text-secondary">This draft and its items will be permanently removed. This cannot be undone.</p>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('my-del-confirm-<?= e($req['id']) ?>').classList.add('hidden'); document.getElementById('my-del-confirm-<?= e($req['id']) ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="req_id" value="<?= e($req['id']) ?>">
                    <button type="submit" class="rounded-button bg-red-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-red-700">Delete</button>
                </form>
            </div>
        </div>
    </div>
    <?php
    }
}
include __DIR__ . '/../includes/app-shell-end.php';
