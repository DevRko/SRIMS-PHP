<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'APPROVER']);
$user = currentUser();
$currentPath = '/approvals/rejected';

$searchQuery = trim($_GET['q'] ?? '');
$expandedId = $_GET['expand'] ?? null;
$sql = "SELECT r.*, u.name AS user_name, a.name AS approved_by_name FROM requisitions r
        JOIN users u ON u.id = r.user_id LEFT JOIN users a ON a.id = r.approved_by_id
        WHERE r.status = 'REJECTED'";
$params = [];
if ($searchQuery) {
    $sql .= ' AND (r.id LIKE ? OR u.name LIKE ?)';
    $like = '%' . $searchQuery . '%';
    array_push($params, $like, $like);
}
$sql .= ' ORDER BY r.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rejected = $stmt->fetchAll();

$pageTitle = 'Rejected Requisitions';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6">
    <h1 class="text-page-title text-text-primary">Rejected Requisitions</h1>
    <p class="text-page-subtitle text-text-secondary mt-1">Read-only record of all rejected requisitions and reasons</p>
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
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Rejected By</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Date</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
            </tr></thead>
            <tbody>
                <?php if (empty($rejected)): ?>
                <tr><td colspan="7" class="px-4 py-12 text-center text-[14px] text-text-muted">No rejected requisitions found</td></tr>
                <?php else: foreach ($rejected as $req): $isExpanded = $expandedId === $req['id']; ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50 cursor-pointer" onclick="window.location.href='?q=<?= e(urlencode($searchQuery)) ?>&expand=<?= $isExpanded ? '' : e($req['id']) ?>'">
                    <td class="px-4 py-3 text-[13px] font-medium text-brand-primary"><?= e($req['id']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($req['user_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($req['approved_by_name'] ?: '—') ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDate($req['created_at']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $req['total_amount']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill('rejected') ?></td>
                    <td class="px-4 py-3 text-center">
                        <button type="button" onclick="event.stopPropagation(); openReqModal('<?= e($req['id']) ?>')" title="View rejected items" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="eye" style="width:16px;height:16px"></i></button>
                    </td>
                </tr>
                <?php if ($isExpanded && $req['rejected_reason']): ?>
                <tr class="bg-red-50">
                    <td colspan="7" class="px-4 py-3">
                        <div class="flex items-start gap-2 text-[13px] text-red-800">
                            <i data-lucide="message-square-warning" class="mt-0.5 flex-shrink-0 text-red-500" style="width:16px;height:16px"></i>
                            <div><span class="font-medium">Rejection reason: </span><?= e($req['rejected_reason']) ?></div>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/requisition-modal.php';
foreach ($rejected as $req) { renderRequisitionModal($pdo, $req); }
include __DIR__ . '/../includes/app-shell-end.php';
