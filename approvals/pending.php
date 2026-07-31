<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'APPROVER']);
$user = currentUser();
$currentPath = '/approvals/pending';

// ─── POST actions ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reqId = $_POST['req_id'] ?? '';
    $reqStmt = $pdo->prepare('SELECT * FROM requisitions WHERE id = ? AND status = \'PENDING\'');
    $reqStmt->execute([$reqId]);
    $req = $reqStmt->fetch();

    if ($req && $action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if (mb_strlen($reason) < 10) {
            flash('error', 'Rejection reason must be at least 10 characters.');
            header('Location: pending.php?selected=' . urlencode($reqId));
            exit;
        }
        $pdo->prepare('UPDATE requisitions SET status=\'REJECTED\', approved_by_id=?, rejected_reason=?, updated_at=NOW() WHERE id=?')
            ->execute([$user['id'], $reason, $reqId]);
        addAuditLog($pdo, $user['id'], 'REJECT', 'Requisition', $reqId, "Rejected by {$user['name']}: {$reason}");
        addNotification($pdo, $req['user_id'], "Your requisition {$reqId} was rejected — see Rejected Approvals for reason", '/approvals/rejected');
        flash('success', "{$reqId} has been rejected.");
        header('Location: pending.php');
        exit;
    }

    if ($req && $action === 'send_back') {
        $pdo->prepare('UPDATE requisitions SET status=\'DRAFT\', updated_at=NOW() WHERE id=?')->execute([$reqId]);
        addAuditLog($pdo, $user['id'], 'SEND_BACK', 'Requisition', $reqId, "Sent back to requester by {$user['name']}");
        flash('success', "{$reqId} sent back to requester as draft.");
        header('Location: pending.php');
        exit;
    }

    if ($req && $action === 'confirm_approve_only') {
        $itemsStmt = $pdo->prepare('SELECT * FROM requisition_items WHERE requisition_id = ?');
        $itemsStmt->execute([$reqId]);
        $lineItems = $itemsStmt->fetchAll();
        $approvedTotal = 0;
        $upd = $pdo->prepare('UPDATE requisition_items SET approved_qty = ? WHERE id = ?');
        foreach ($lineItems as $li) {
            $qty = max(0, min((int) $li['requested_qty'], (int) ($_POST['approved_qty'][$li['item_id']] ?? $li['requested_qty'])));
            $upd->execute([$qty, $li['id']]);
            $approvedTotal += $qty * $li['unit_price'];
        }
        $pdo->prepare('UPDATE requisitions SET status=\'APPROVED\', approved_by_id=?, approved_at=NOW(), total_amount=?, updated_at=NOW() WHERE id=?')
            ->execute([$user['id'], $approvedTotal, $reqId]);
        $requesterName = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $requesterName->execute([$req['user_id']]);
        addAuditLog($pdo, $user['id'], 'APPROVE', 'Requisition', $reqId, "Approved by {$user['name']} for " . ($requesterName->fetchColumn() ?: $req['user_id']));
        addNotificationToRoles($pdo, ['ADMIN', 'INVENTORY_MGR'], "{$reqId} approved — ready to issue", '/issue/queue');
        addNotification($pdo, $req['user_id'], "Your requisition {$reqId} has been approved", '/requisitions/my');
        flash('success', "{$reqId} approved. Ready for issuance.");
        header('Location: pending.php');
        exit;
    }

    if ($req && $action === 'confirm_approve_issue') {
        $itemsStmt = $pdo->prepare(
            'SELECT ri.*, i.current_stock, i.name AS item_name FROM requisition_items ri JOIN items i ON i.id = ri.item_id WHERE ri.requisition_id = ?'
        );
        $itemsStmt->execute([$reqId]);
        $lineItems = $itemsStmt->fetchAll();

        $lines = [];
        $actualIssuedAmount = 0;
        $isFullyIssued = true;
        foreach ($lineItems as $li) {
            $available = (int) $li['current_stock'];
            $requested = (int) $li['requested_qty'];
            // Cap to BOTH available stock AND the originally requested
            // quantity — an approver should never be able to issue more
            // than what was actually asked for.
            $issueQty = max(0, min($available, $requested, (int) ($_POST['issue_qty'][$li['item_id']] ?? 0)));
            $lines[] = ['item_id' => $li['item_id'], 'ri_id' => $li['id'], 'issue_qty' => $issueQty, 'unit_price' => (float) $li['unit_price'], 'requested_qty' => $requested];
            $actualIssuedAmount += $issueQty * $li['unit_price'];
            if ($issueQty < $requested) $isFullyIssued = false;
        }

        $referenceNo = generateIssuanceId();
        if ($isFullyIssued) {
            $status = 'ISSUED';
        } else {
            // Not everything requested could be issued — honor the
            // approver's explicit choice of what that means for this
            // requisition (defaults to "pending" if somehow missing).
            $completionChoice = $_POST['completion_choice'] ?? 'pending';
            $status = $completionChoice === 'complete' ? 'ISSUED' : 'PARTIAL';
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE requisitions SET status=?, approved_by_id=?, approved_at=NOW(), total_amount=?, updated_at=NOW() WHERE id=?')
                ->execute([$status, $user['id'], $actualIssuedAmount, $reqId]);

            $updLine = $pdo->prepare('UPDATE requisition_items SET approved_qty=?, issued_qty=? WHERE id=?');
            foreach ($lines as $l) { $updLine->execute([$l['issue_qty'], $l['issue_qty'], $l['ri_id']]); }

            $pdo->prepare('INSERT INTO issuances (id, requisition_id, issued_by_id, issued_to_id, received_by, issue_date, reference_no, remarks) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)')
                ->execute([$referenceNo, $reqId, $user['id'], $req['user_id'], null, $referenceNo, "Approved and auto-issued by {$user['name']}"]);

            $insIssItem = $pdo->prepare('INSERT INTO issuance_items (id, issuance_id, item_id, issued_qty, unit_price) VALUES (?, ?, ?, ?, ?)');
            $insTxn = $pdo->prepare('INSERT INTO stock_transactions (id, type, item_id, quantity, unit_price, reference_no, reference_type, date, user_id) VALUES (?, \'OUTWARD\', ?, ?, ?, ?, \'ISSUANCE\', CURDATE(), ?)');
            $updStock = $pdo->prepare('UPDATE items SET current_stock = GREATEST(0, current_stock - ?) WHERE id = ?');
            foreach ($lines as $l) {
                if ($l['issue_qty'] <= 0) continue;
                $insIssItem->execute([generateId('issi'), $referenceNo, $l['item_id'], $l['issue_qty'], $l['unit_price']]);
                $insTxn->execute([generateId('st'), $l['item_id'], $l['issue_qty'], $l['unit_price'], $referenceNo, $user['id']]);
                $updStock->execute([$l['issue_qty'], $l['item_id']]);
            }
            $pdo->commit();
        } catch (Exception $ex) {
            $pdo->rollBack();
            flash('error', 'Could not process approval: ' . $ex->getMessage());
            header('Location: pending.php?selected=' . urlencode($reqId));
            exit;
        }

        $completionNote = !$isFullyIssued ? (($status === 'ISSUED') ? ' Marked complete despite shortfall.' : ' Kept pending — remainder to be issued later.') : '';
        addAuditLog($pdo, $user['id'], 'APPROVE_AND_ISSUE', 'Requisition', $reqId, "Approved and auto-issued by {$user['name']}, ref {$referenceNo}. Issued amount: " . formatCurrency($actualIssuedAmount) . $completionNote);
        addNotification($pdo, $req['user_id'], "Your requisition {$reqId} has been " . ($isFullyIssued ? 'fully issued' : 'partially issued') . " ({$referenceNo})", '/requisitions/my');
        flash('success', "{$reqId} approved and stock issued ({$referenceNo}).");
        header('Location: pending.php');
        exit;
    }
}

// ─── List data ──────────────────────────────────────────────────────────────
$searchQuery = trim($_GET['q'] ?? '');
$departmentFilter = $_GET['department'] ?? '';
$priorityFilter = $_GET['priority'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;
$selectedId = $_GET['selected'] ?? null;

$sql = "SELECT r.*, u.name AS user_name, d.name AS department_name FROM requisitions r
        JOIN users u ON u.id = r.user_id JOIN departments d ON d.id = r.department_id WHERE r.status = 'PENDING'";
$params = [];
if ($departmentFilter) { $sql .= ' AND d.name = ?'; $params[] = $departmentFilter; }
if ($priorityFilter) { $sql .= ' AND r.priority = ?'; $params[] = $priorityFilter; }
if ($searchQuery) {
    $sql .= ' AND (r.id LIKE ? OR u.name LIKE ?)';
    $like = '%' . $searchQuery . '%';
    array_push($params, $like, $like);
}
$sql .= ' ORDER BY r.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pendingReqs = $stmt->fetchAll();

$totalPages = max(1, (int) ceil(count($pendingReqs) / $perPage));
$page = min($page, $totalPages);
$paginated = array_slice($pendingReqs, ($page - 1) * $perPage, $perPage);

$todayReqs = count(array_filter($pendingReqs, fn($r) => date('Y-m-d', strtotime($r['created_at'])) === date('Y-m-d')));
$approvedThisMonth = (int) $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status IN ('APPROVED','ISSUED','PARTIAL')")->fetchColumn();
$rejectedCount = (int) $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'REJECTED'")->fetchColumn();

$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();

$selectedReq = null;
$selectedItems = [];
if ($selectedId) {
    $s = $pdo->prepare("SELECT r.*, u.name AS user_name, d.name AS department_name FROM requisitions r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=r.department_id WHERE r.id = ?");
    $s->execute([$selectedId]);
    $selectedReq = $s->fetch();
    if ($selectedReq) {
        $si = $pdo->prepare('SELECT ri.*, i.current_stock, i.icon_key, i.name AS item_name FROM requisition_items ri JOIN items i ON i.id = ri.item_id WHERE ri.requisition_id = ?');
        $si->execute([$selectedId]);
        $selectedItems = $si->fetchAll();
    }
}

$qs = function (array $overrides = []) use ($searchQuery, $departmentFilter, $priorityFilter, $selectedId) {
    $q = array_merge(['q' => $searchQuery, 'department' => $departmentFilter, 'priority' => $priorityFilter, 'selected' => $selectedId], $overrides);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '';
};

$pageTitle = 'Pending Approvals';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Pending Approvals</h1><p class="text-page-subtitle text-text-secondary mt-1">Review and approve or reject requisitions</p></div>

<?php renderFlash(); ?>

<div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-amber-bg"><i data-lucide="hourglass" class="text-tint-amber-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Pending Now</p><p class="mt-1 text-card-value text-text-primary"><?= count($pendingReqs) ?></p></div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-amber-bg"><i data-lucide="calendar" class="text-tint-amber-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Received Today</p><p class="mt-1 text-card-value text-text-primary"><?= $todayReqs ?></p></div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-green-bg"><i data-lucide="check-circle-2" class="text-tint-green-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Approved / Issued</p><p class="mt-1 text-card-value text-text-primary"><?= $approvedThisMonth ?></p></div>
    </div>
    <div class="flex items-start gap-4 rounded-card border border-border bg-surface-card p-card-padding">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-red-bg"><i data-lucide="x-circle" class="text-tint-red-icon" style="width:20px;height:20px"></i></div>
        <div><p class="text-card-label text-text-secondary">Rejected</p><p class="mt-1 text-card-value text-text-primary"><?= $rejectedCount ?></p></div>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
    <div class="<?= $selectedReq ? 'lg:col-span-3' : 'lg:col-span-5' ?>">
        <div class="rounded-card border border-border bg-surface-card">
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                <?php if ($selectedId): ?><input type="hidden" name="selected" value="<?= e($selectedId) ?>"><?php endif; ?>
                <div class="relative flex-1 min-w-[180px]">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:15px;height:15px"></i>
                    <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search..." class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none">
                </div>
                <select name="department" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[12px] text-text-secondary focus:outline-none">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?><option value="<?= e($d['name']) ?>" <?= $departmentFilter === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
                <select name="priority" onchange="this.form.submit()" class="rounded-button border border-border px-3 py-2 text-[12px] text-text-secondary focus:outline-none">
                    <option value="">All Priorities</option>
                    <option value="URGENT" <?= $priorityFilter === 'URGENT' ? 'selected' : '' ?>>Urgent</option>
                    <option value="NORMAL" <?= $priorityFilter === 'NORMAL' ? 'selected' : '' ?>>Normal</option>
                    <option value="LOW" <?= $priorityFilter === 'LOW' ? 'selected' : '' ?>>Low</option>
                </select>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead><tr class="border-b border-border">
                        <?php foreach (['REQ No.', 'Requested By', 'Department', 'Date', 'Items', 'Amount', 'Priority', 'Action'] as $h): ?>
                        <th class="px-4 py-3 text-table-header uppercase tracking-table-header text-text-secondary <?= in_array($h, ['Items', 'Amount']) ? 'text-right' : ($h === 'Action' ? 'text-center' : 'text-left') ?>"><?= $h ?></th>
                        <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                        <?php if (empty($paginated)): ?>
                        <tr><td colspan="8" class="px-4 py-12 text-center text-[14px] text-text-muted">No pending requisitions</td></tr>
                        <?php else: foreach ($paginated as $req):
                            $itemCount = (int) $pdo->query('SELECT COUNT(*) FROM requisition_items WHERE requisition_id=' . $pdo->quote($req['id']))->fetchColumn();
                            $isSel = $selectedReq && $selectedReq['id'] === $req['id'];
                        ?>
                        <tr class="border-b border-border last:border-0 cursor-pointer transition-colors <?= $isSel ? 'bg-blue-50' : 'hover:bg-gray-50' ?>" onclick="window.location.href='<?= $qs(['selected' => $req['id']]) ?>'">
                            <td class="px-4 py-3 text-[13px] font-medium text-brand-primary"><?= e($req['id']) ?></td>
                            <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($req['user_name']) ?></td>
                            <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($req['department_name']) ?></td>
                            <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDate($req['created_at']) ?></td>
                            <td class="px-4 py-3 text-right text-[13px]"><?= $itemCount ?></td>
                            <td class="px-4 py-3 text-right text-[13px] font-medium"><?= formatCurrency((float) $req['total_amount']) ?></td>
                            <td class="px-4 py-3"><span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $req['priority'] === 'URGENT' ? 'bg-red-100 text-red-700' : ($req['priority'] === 'NORMAL' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600') ?>"><?= e($req['priority']) ?></span></td>
                            <td class="px-4 py-3 text-center"><button type="button" onclick="event.stopPropagation(); window.location.href='<?= $qs(['selected' => $req['id']]) ?>'" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="eye" style="width:16px;height:16px"></i></button></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
            <div class="flex items-center justify-between border-t border-border px-4 py-3">
                <span class="text-[12px] text-text-secondary"><?= ($page - 1) * $perPage + 1 ?>–<?= min($page * $perPage, count($pendingReqs)) ?> of <?= count($pendingReqs) ?></span>
                <div class="flex gap-1">
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="<?= $qs(['page' => $p]) ?>" class="min-w-[28px] rounded px-1.5 py-0.5 text-center text-[12px] font-medium <?= $p === $page ? 'bg-brand-primary text-white' : 'text-text-secondary hover:bg-gray-100' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selectedReq): ?>
    <div class="lg:col-span-2">
        <div class="sticky top-[88px] rounded-card border border-border bg-surface-card p-4">
            <div class="mb-3 flex items-start justify-between">
                <div><h3 class="text-[15px] font-bold text-text-primary"><?= e($selectedReq['id']) ?></h3><?= statusPill('pending') ?></div>
                <a href="<?= $qs(['selected' => null]) ?>" class="rounded p-1 text-text-muted hover:bg-gray-100"><i data-lucide="x" style="width:16px;height:16px"></i></a>
            </div>
            <div class="mb-3 grid grid-cols-2 gap-2 text-[12px]">
                <div><span class="text-[10px] uppercase text-text-muted">Requested By</span><p class="font-medium text-text-primary"><?= e($selectedReq['user_name']) ?></p></div>
                <div><span class="text-[10px] uppercase text-text-muted">Department</span><p class="font-medium text-text-primary"><?= e($selectedReq['department_name']) ?></p></div>
                <div><span class="text-[10px] uppercase text-text-muted">Submitted</span><p class="font-medium text-text-primary"><?= formatDate($selectedReq['created_at']) ?></p></div>
                <div><span class="text-[10px] uppercase text-text-muted">Required By</span><p class="font-medium text-text-primary"><?= $selectedReq['required_date'] ? formatDate($selectedReq['required_date']) : '—' ?></p></div>
                <div><span class="text-[10px] uppercase text-text-muted">Purpose</span><p class="font-medium text-text-primary"><?= e($selectedReq['purpose']) ?></p></div>
                <div><span class="text-[10px] uppercase text-text-muted">Priority</span><p class="font-medium text-text-primary"><?= priorityLabel($selectedReq['priority']) ?></p></div>
            </div>

            <div class="mb-3 overflow-hidden rounded-md border border-border">
                <table class="w-full text-[11px]">
                    <thead><tr class="bg-gray-50 border-b border-border">
                        <th class="px-2 py-1.5 text-left font-semibold text-text-secondary uppercase tracking-wide">Item</th>
                        <th class="px-2 py-1.5 text-right font-semibold text-text-secondary uppercase tracking-wide">Req.</th>
                        <th class="px-2 py-1.5 text-right font-semibold text-text-secondary uppercase tracking-wide">In Stock</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($selectedItems as $item): $shortage = $item['current_stock'] < $item['requested_qty']; ?>
                        <tr class="border-b border-border last:border-0">
                            <td class="px-2 py-1.5"><div class="flex items-center gap-1.5"><?= itemIconSvg($item['icon_key'], $item['item_id'], 16) ?><span class="text-text-primary"><?= e($item['item_name']) ?></span></div></td>
                            <td class="px-2 py-1.5 text-right"><?= (int) $item['requested_qty'] ?></td>
                            <td class="px-2 py-1.5 text-right font-semibold <?= $shortage ? 'text-red-600' : 'text-green-600' ?>"><?= (int) $item['current_stock'] ?><?php if ($shortage): ?><i data-lucide="alert-triangle" class="ml-1 inline" style="width:10px;height:10px"></i><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-2">
                <button type="button" onclick="document.getElementById('reject-modal').classList.remove('hidden')" class="flex flex-1 items-center justify-center gap-1.5 rounded-button border border-red-300 px-3 py-2 text-[12px] font-medium text-red-600 hover:bg-red-50"><i data-lucide="x" style="width:13px;height:13px"></i> Reject</button>
                <button type="button" onclick="document.getElementById('approve-only-modal').classList.remove('hidden')" class="flex flex-1 items-center justify-center gap-1.5 rounded-button border border-green-400 px-3 py-2 text-[12px] font-medium text-green-700 hover:bg-green-50"><i data-lucide="check" style="width:13px;height:13px"></i> Approve</button>
                <button type="button" onclick="document.getElementById('approve-issue-modal').classList.remove('hidden')" class="flex flex-1 items-center justify-center gap-1.5 rounded-button bg-green-600 px-3 py-2 text-[12px] font-semibold text-white hover:bg-green-700"><i data-lucide="check" style="width:13px;height:13px"></i> Approve &amp; Issue</button>
            </div>
            <form method="POST" onsubmit="return confirm('Send this requisition back to the requester as a draft?')">
                <input type="hidden" name="action" value="send_back"><input type="hidden" name="req_id" value="<?= e($selectedReq['id']) ?>">
                <button type="submit" class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-button border border-border px-3 py-1.5 text-[11px] font-medium text-text-secondary hover:bg-gray-50"><i data-lucide="undo-2" style="width:12px;height:12px"></i> Send Back to Requester</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($selectedReq):
    $hasStockShortage = false;
    $totalIssueAmount = 0;
    foreach ($selectedItems as $it) { if ($it['current_stock'] < $it['requested_qty']) $hasStockShortage = true; $totalIssueAmount += min($it['current_stock'], $it['requested_qty']) * $it['unit_price']; }
?>
<!-- Approve & Issue modal -->
<div id="approve-issue-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-card bg-surface-card shadow-lg">
        <div class="flex items-center justify-between border-b border-border px-5 py-4">
            <div><h3 class="text-[16px] font-bold text-text-primary">Approve &amp; Issue Stock</h3><p class="text-[12px] text-text-secondary"><?= e($selectedReq['id']) ?> — <?= e($selectedReq['user_name']) ?> (<?= e($selectedReq['department_name']) ?>)</p></div>
            <button type="button" onclick="document.getElementById('approve-issue-modal').classList.add('hidden')" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button>
        </div>
        <form method="POST" id="approveIssueForm">
        <input type="hidden" name="action" value="confirm_approve_issue"><input type="hidden" name="req_id" value="<?= e($selectedReq['id']) ?>">
        <div class="p-5">
            <?php if ($hasStockShortage): ?>
            <div class="mb-4 flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12px] text-amber-800"><i data-lucide="alert-triangle" style="width:15px;height:15px"></i> Some items have insufficient stock. Adjust issue quantities below or approve partially. Issue Qty cannot exceed what was requested.</div>
            <?php endif; ?>
            <table class="w-full text-[12px]">
                <thead><tr class="border-b border-border">
                    <th class="pb-2 text-left font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Item</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Requested</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px]">In Stock</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px] w-24">Issue Qty</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($selectedItems as $item): $shortage = $item['current_stock'] < $item['requested_qty']; $issuable = min($item['requested_qty'], $item['current_stock']); ?>
                    <tr class="border-b border-border last:border-0">
                        <td class="py-2"><div class="flex items-center gap-2"><?= itemIconSvg($item['icon_key'], $item['item_id'], 20) ?><span class="text-text-primary"><?= e($item['item_name']) ?></span></div></td>
                        <td class="py-2 text-right text-text-secondary"><?= (int) $item['requested_qty'] ?></td>
                        <td class="py-2 text-right font-semibold <?= $shortage ? 'text-red-600' : 'text-green-600' ?>"><?= (int) $item['current_stock'] ?></td>
                        <td class="py-2 text-right"><input type="number" name="issue_qty[<?= e($item['item_id']) ?>]" value="<?= $issuable ?>" min="0" max="<?= $issuable ?>" onchange="if(parseInt(this.value||0,10)>this.max)this.value=this.max; updateApproveIssueCompletion();" class="w-20 rounded-button border px-2 py-1 text-right text-[12px] focus:outline-none <?= $shortage ? 'border-amber-300 bg-amber-50 focus:border-amber-500' : 'border-border focus:border-brand-primary' ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="border-t border-border bg-gray-50"><td colspan="3" class="px-0 py-2 text-right font-bold text-text-primary">Est. Issued Amount</td><td class="py-2 text-right font-bold text-brand-primary"><?= formatCurrency($totalIssueAmount) ?></td></tr></tfoot>
            </table>

            <div id="approveIssueCompletionChoice" class="mt-4 rounded-md border border-border p-3 <?= $hasStockShortage ? '' : 'hidden' ?>">
                <p class="mb-2 text-[12px] font-medium text-text-primary">This won't fully cover what was requested. What should happen to this requisition?</p>
                <label class="mb-1.5 flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="completion_choice" value="pending" checked class="mt-0.5">
                    <span class="text-[12px] text-text-secondary"><strong class="text-text-primary">Keep Pending</strong> — the rest will be issued later once more stock arrives (stays visible in the Issue Queue).</span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="completion_choice" value="complete" class="mt-0.5">
                    <span class="text-[12px] text-text-secondary"><strong class="text-text-primary">Mark as Complete</strong> — close this out now; the shortfall won't be issued later.</span>
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('approve-issue-modal').classList.add('hidden')" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <button type="submit" class="flex items-center gap-2 rounded-button bg-green-600 px-5 py-2 text-[13px] font-semibold text-white hover:bg-green-700"><i data-lucide="check" style="width:15px;height:15px"></i> Confirm — Approve &amp; Issue</button>
            </div>
        </div>
        </form>
    </div>
</div>
<script>
function updateApproveIssueCompletion() {
    var form = document.getElementById('approveIssueForm');
    var short = false;
    form.querySelectorAll('input[name^="issue_qty"]').forEach(function (input) {
        if (parseInt(input.value || 0, 10) < parseInt(input.max || 0, 10)) short = true;
    });
    document.getElementById('approveIssueCompletionChoice').classList.toggle('hidden', !short);
}
</script>

<!-- Approve Only modal -->
<div id="approve-only-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-card bg-surface-card shadow-lg">
        <div class="flex items-center justify-between border-b border-border px-5 py-4">
            <div><h3 class="text-[16px] font-bold text-text-primary">Approve Requisition</h3><p class="text-[12px] text-text-secondary"><?= e($selectedReq['id']) ?> — <?= e($selectedReq['user_name']) ?> (<?= e($selectedReq['department_name']) ?>)</p></div>
            <button type="button" onclick="document.getElementById('approve-only-modal').classList.add('hidden')" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="confirm_approve_only"><input type="hidden" name="req_id" value="<?= e($selectedReq['id']) ?>">
        <div class="p-5">
            <div class="mb-4 flex items-start gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2.5 text-[12px] text-blue-800"><i data-lucide="check" class="mt-0.5 flex-shrink-0" style="width:15px;height:15px"></i> Set approved quantities per line. Issuance will be handled separately by the Inventory team.</div>
            <table class="w-full text-[12px]">
                <thead><tr class="border-b border-border">
                    <th class="pb-2 text-left font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Item</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Requested</th>
                    <th class="pb-2 text-left font-semibold text-text-secondary uppercase tracking-wide text-[10px] pl-4">Unit</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px] w-28">Approved Qty</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($selectedItems as $item): ?>
                    <tr class="border-b border-border last:border-0">
                        <td class="py-2"><div class="flex items-center gap-2"><?= itemIconSvg($item['icon_key'], $item['item_id'], 20) ?><span class="text-text-primary"><?= e($item['item_name']) ?></span></div></td>
                        <td class="py-2 text-right text-text-secondary"><?= (int) $item['requested_qty'] ?></td>
                        <td class="py-2 pl-4 text-text-secondary"><?php
                            $unitStmt = $pdo->prepare('SELECT unit FROM items WHERE id = ?'); $unitStmt->execute([$item['item_id']]); echo e($unitStmt->fetchColumn());
                        ?></td>
                        <td class="py-2 text-right"><input type="number" name="approved_qty[<?= e($item['item_id']) ?>]" value="<?= (int) $item['requested_qty'] ?>" min="0" max="<?= (int) $item['requested_qty'] ?>" class="w-24 rounded-button border border-border px-2 py-1 text-right text-[12px] focus:border-brand-primary focus:outline-none"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mt-5 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('approve-only-modal').classList.add('hidden')" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <button type="submit" class="flex items-center gap-2 rounded-button bg-green-600 px-5 py-2 text-[13px] font-semibold text-white hover:bg-green-700"><i data-lucide="check" style="width:15px;height:15px"></i> Confirm Approval</button>
            </div>
        </div>
        </form>
    </div>
</div>

<!-- Reject modal -->
<div id="reject-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="w-full max-w-md rounded-card bg-surface-card p-6 shadow-lg">
        <div class="mb-4 flex items-center justify-between"><h3 class="text-[16px] font-semibold text-text-primary">Reject Requisition</h3><button type="button" onclick="document.getElementById('reject-modal').classList.add('hidden')" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button></div>
        <form method="POST">
        <input type="hidden" name="action" value="reject"><input type="hidden" name="req_id" value="<?= e($selectedReq['id']) ?>">
        <p class="mb-3 text-[13px] text-text-secondary">Please provide a reason (min 10 characters).</p>
        <textarea name="reason" rows="4" minlength="10" required placeholder="Enter rejection reason..." class="w-full rounded-button border border-border px-3 py-2 text-[14px] placeholder:text-text-muted focus:border-red-400 focus:outline-none resize-none"></textarea>
        <div class="mt-4 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('reject-modal').classList.add('hidden')" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
            <button type="submit" class="rounded-button bg-red-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-red-700">Confirm Reject</button>
        </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
