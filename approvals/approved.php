<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'APPROVER']);
$user = currentUser();
$currentPath = '/approvals/approved';

$searchQuery = trim($_GET['q'] ?? '');
$sql = "SELECT r.*, u.name AS user_name, a.name AS approved_by_name FROM requisitions r
        JOIN users u ON u.id = r.user_id LEFT JOIN users a ON a.id = r.approved_by_id
        WHERE r.status IN ('APPROVED','ISSUED','PARTIAL')";
$params = [];
if ($searchQuery) {
    $sql .= ' AND (r.id LIKE ? OR u.name LIKE ?)';
    $like = '%' . $searchQuery . '%';
    array_push($params, $like, $like);
}
$sql .= ' ORDER BY r.approved_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$approved = $stmt->fetchAll();
// approved_by_name falls back to the synthetic Auto-Approval System actor
foreach ($approved as &$r) {
    if ($r['approved_by_id'] === AUTO_APPROVAL_ACTOR_ID && !$r['approved_by_name']) $r['approved_by_name'] = AUTO_APPROVAL_ACTOR_NAME;
}
unset($r);

$pageTitle = 'Approved Requisitions';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6">
    <h1 class="text-page-title text-text-primary">Approved Requisitions</h1>
    <p class="text-page-subtitle text-text-secondary mt-1">Read-only record of all approved requisitions</p>
</div>

<div class="rounded-card border border-border bg-surface-card">
    <div class="border-b border-border px-4 py-3">
        <form method="GET" class="relative max-w-sm">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:16px;height:16px"></i>
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search requisitions..."
                class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Requested By</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Approved By</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Approved On</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
            </tr></thead>
            <tbody>
                <?php if (empty($approved)): ?>
                <tr><td colspan="7" class="px-4 py-12 text-center text-[14px] text-text-muted">No approved requisitions found</td></tr>
                <?php else: foreach ($approved as $req): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3"><button type="button" onclick="openReqModal('<?= e($req['id']) ?>')" class="text-[13px] font-medium text-brand-primary hover:underline"><?= e($req['id']) ?></button></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($req['user_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($req['approved_by_name'] ?: '—') ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= $req['approved_at'] ? formatDate($req['approved_at']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $req['total_amount']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill($req['status'] === 'ISSUED' ? 'issued' : ($req['status'] === 'PARTIAL' ? 'partial' : 'approved')) ?></td>
                    <td class="px-4 py-3 text-center"><button type="button" onclick="openReqModal('<?= e($req['id']) ?>')" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="eye" style="width:16px;height:16px"></i></button></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/requisition-modal.php';
foreach ($approved as $req) { renderRequisitionModal($pdo, $req); }
include __DIR__ . '/../includes/app-shell-end.php';
