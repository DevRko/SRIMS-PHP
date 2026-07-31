<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user = currentUser();
$currentPath = '/masters/roles';

$permissionMatrix = [
    ['label' => 'Browse catalog & raise requisitions', 'ADMIN' => true, 'USER' => true, 'APPROVER' => true, 'INVENTORY_MGR' => false],
    ['label' => 'View own requisitions only', 'ADMIN' => false, 'USER' => true, 'APPROVER' => false, 'INVENTORY_MGR' => false],
    ['label' => 'View all requisitions (system-wide)', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => false],
    ['label' => 'Approve / Reject / Send Back requisitions', 'ADMIN' => true, 'USER' => false, 'APPROVER' => true, 'INVENTORY_MGR' => false],
    ['label' => 'Issue approved requisitions', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => true],
    ['label' => 'Manage stock (inward / outward / adjust)', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => true],
    ['label' => 'Set stock thresholds', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => true],
    ['label' => 'Manage Categories / Items / Suppliers', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => true],
    ['label' => 'Manage Users', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => false],
    ['label' => 'View Audit Logs', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => false],
    ['label' => 'Modify system Settings', 'ADMIN' => true, 'USER' => false, 'APPROVER' => false, 'INVENTORY_MGR' => false],
];
$roleColumns = ['ADMIN' => 'Admin', 'USER' => 'Normal User', 'APPROVER' => 'Approver', 'INVENTORY_MGR' => 'Inventory Manager'];

$pageTitle = 'Roles & Permissions';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Roles &amp; Permissions</h1><p class="text-page-subtitle text-text-secondary mt-1">Fixed system role definitions — RBAC is enforced server-side on every route</p></div>

<?php if ($user['role'] !== 'ADMIN'): ?>
<div class="rounded-card border border-border bg-surface-card p-8 text-center">
    <i data-lucide="shield-alert" class="mx-auto mb-3 text-text-muted" style="width:36px;height:36px"></i>
    <h3 class="text-[16px] font-semibold text-text-primary mb-1">Access restricted</h3>
    <p class="text-[13px] text-text-secondary">Only Administrators can view role permissions.</p>
</div>
<?php else: ?>
<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Permission</th>
                <?php foreach ($roleColumns as $label): ?><th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary"><?= e($label) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($permissionMatrix as $row): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($row['label']) ?></td>
                    <?php foreach (array_keys($roleColumns) as $key): ?>
                    <td class="px-4 py-3 text-center"><?php if ($row[$key]): ?><i data-lucide="check" class="mx-auto text-green-600" style="width:16px;height:16px"></i><?php else: ?><i data-lucide="x" class="mx-auto text-gray-300" style="width:16px;height:16px"></i><?php endif; ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4 rounded-md bg-tint-blue-bg p-3 text-[12px] text-tint-blue-icon">Roles are fixed in this version of SRIMS and cannot be customized. Every page enforces the session role server-side — no role information is ever trusted from the client.</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
