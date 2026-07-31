<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/issue/queue';

$sql = "SELECT r.*, u.name AS user_name, d.name AS department_name, (SELECT COUNT(*) FROM requisition_items WHERE requisition_id=r.id) AS item_count
        FROM requisitions r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=r.department_id
        WHERE r.status IN ('APPROVED', 'PARTIAL') ORDER BY r.approved_at ASC";
$queue = $pdo->query($sql)->fetchAll();

$pageTitle = 'Issue Queue';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Issue Queue</h1><p class="text-page-subtitle text-text-secondary mt-1">Approved requisitions awaiting stock issuance, including partials still waiting on the remainder</p></div>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Requested By</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Approved On</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Items</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
            </tr></thead>
            <tbody>
                <?php if (empty($queue)): ?>
                <tr><td colspan="8" class="px-4 py-12 text-center text-[14px] text-text-muted">No requisitions in the issue queue</td></tr>
                <?php else: foreach ($queue as $req): $isPartial = $req['status'] === 'PARTIAL'; ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3 text-[13px] font-medium text-brand-primary"><?= e($req['id']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($req['user_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($req['department_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= $req['approved_at'] ? formatDate($req['approved_at']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-primary"><?= (int) $req['item_count'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $req['total_amount']) ?></td>
                    <td class="px-4 py-3 text-center"><?= $isPartial ? statusPill('partial', 'Pending Completion') : statusPill('approved') ?></td>
                    <td class="px-4 py-3 text-center"><a href="items.php?req=<?= e($req['id']) ?>" class="inline-flex items-center gap-1 rounded-button bg-brand-primary px-3 py-1.5 text-[12px] font-medium text-white hover:bg-brand-primary-hover"><?= $isPartial ? 'Issue Remaining' : 'Issue' ?> <i data-lucide="arrow-right" style="width:12px;height:12px"></i></a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
