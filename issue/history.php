<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/issue/history';

$issuances = $pdo->query(
    "SELECT iss.*, r.status AS req_status, r.total_amount, (SELECT COUNT(*) FROM issuance_items WHERE issuance_id = iss.id) AS line_count,
     (SELECT COALESCE(SUM(issued_qty * unit_price),0) FROM issuance_items WHERE issuance_id = iss.id) AS value
     FROM issuances iss LEFT JOIN requisitions r ON r.id = iss.requisition_id ORDER BY iss.issue_date DESC"
)->fetchAll();

$pageTitle = 'Issued History';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Issued History</h1><p class="text-page-subtitle text-text-secondary mt-1">Complete record of all stock issuances</p></div>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Reference No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Issued To</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Issued On</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Items</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Value</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
            </tr></thead>
            <tbody>
                <?php if (empty($issuances)): ?>
                <tr><td colspan="8" class="px-4 py-12 text-center text-[14px] text-text-muted">No issuances recorded yet. Process requisitions from Issue Queue.</td></tr>
                <?php else: foreach ($issuances as $iss):
                    $toName = $pdo->prepare('SELECT name FROM users WHERE id=?'); $toName->execute([$iss['issued_to_id']]); $toNameVal = $toName->fetchColumn();
                ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3 text-[13px] font-medium text-brand-primary"><?= e($iss['reference_no']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($iss['requisition_id']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($toNameVal) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDateTime($iss['issue_date']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-primary"><?= (int) $iss['line_count'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $iss['value']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill($iss['req_status'] === 'PARTIAL' ? 'partial' : 'issued') ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($iss['requisition_id']): ?>
                        <button type="button" onclick="openReqModal('<?= e($iss['requisition_id']) ?>')" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="eye" style="width:16px;height:16px"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/requisition-modal.php';
$reqIds = array_unique(array_filter(array_column($issuances, 'requisition_id')));
if ($reqIds) {
    $ph = implode(',', array_fill(0, count($reqIds), '?'));
    $rs = $pdo->prepare("SELECT r.*, u.name AS user_name FROM requisitions r JOIN users u ON u.id=r.user_id WHERE r.id IN ($ph)");
    $rs->execute(array_values($reqIds));
    foreach ($rs->fetchAll() as $req) { renderRequisitionModal($pdo, $req); }
}
include __DIR__ . '/../includes/app-shell-end.php';
