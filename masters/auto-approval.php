<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user = currentUser();
$currentPath = '/masters/auto-approval';
$canManage = in_array($user['role'], ['ADMIN', 'INVENTORY_MGR'], true);

if ($canManage && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $priorities = array_intersect($_POST['priorities'] ?? [], ['LOW', 'NORMAL', 'URGENT']);
    $pdo->prepare('UPDATE auto_approval_settings SET enabled=?, priorities=? WHERE id=1')->execute([$enabled, implode(',', $priorities)]);
    addAuditLog($pdo, $user['id'], 'UPDATE', 'AutoApprovalSettings', 'global', 'Auto-approval ' . ($enabled ? 'enabled' : 'disabled') . ' for priorities: ' . (implode(', ', $priorities) ?: 'none'));
    flash('success', 'Auto-approval rules updated.');
    header('Location: auto-approval.php');
    exit;
}

$settings = getAutoApprovalSettings($pdo);
$priorityInfo = [
    ['key' => 'LOW', 'label' => 'Low Priority', 'desc' => 'Routine, non-urgent requisitions'],
    ['key' => 'NORMAL', 'label' => 'Normal Priority', 'desc' => 'Standard day-to-day requisitions'],
    ['key' => 'URGENT', 'label' => 'Urgent Priority', 'desc' => 'Time-sensitive requisitions — recommended to keep this manually reviewed'],
];

$pageTitle = 'Auto-Approval Rules';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Auto-Approval Rules</h1><p class="text-page-subtitle text-text-secondary mt-1">Automatically approve requisitions at submission, bypassing manual review</p></div>

<?php if (!$canManage): ?>
<div class="rounded-card border border-border bg-surface-card p-8 text-center">
    <i data-lucide="shield-alert" class="mx-auto mb-3 text-text-muted" style="width:36px;height:36px"></i>
    <h3 class="text-[16px] font-semibold text-text-primary mb-1">Access restricted</h3>
    <p class="text-[13px] text-text-secondary">Only Administrators and Inventory Managers can configure auto-approval rules.</p>
</div>
<?php else: ?>
<?php renderFlash(); ?>
<div class="max-w-2xl space-y-6">
    <form method="POST">
    <input type="hidden" name="action" value="save_settings">
    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <div class="mb-5 flex items-start justify-between">
            <div class="flex items-start gap-3">
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-tint-amber-bg"><i data-lucide="zap" class="text-tint-amber-icon" style="width:20px;height:20px"></i></div>
                <div><h3 class="text-[15px] font-semibold text-text-primary">Enable Auto-Approval</h3><p class="text-[13px] text-text-secondary">When on, requisitions matching the selected priorities below skip Pending Approvals entirely and are marked Approved the moment they're submitted.</p></div>
            </div>
            <label class="relative h-6 w-11 flex-shrink-0 cursor-pointer">
                <input type="checkbox" name="enabled" id="autoApprovalToggle" <?= $settings['enabled'] ? 'checked' : '' ?> class="hidden" onchange="this.nextElementSibling.classList.toggle('bg-brand-primary'); this.nextElementSibling.classList.toggle('bg-gray-300'); this.nextElementSibling.firstElementChild.classList.toggle('translate-x-5'); this.nextElementSibling.firstElementChild.classList.toggle('translate-x-0.5'); document.getElementById('autoApprovalFieldset').classList.toggle('opacity-40'); document.getElementById('autoApprovalFieldset').classList.toggle('pointer-events-none');">
                <span class="absolute inset-0 rounded-full transition-colors <?= $settings['enabled'] ? 'bg-brand-primary' : 'bg-gray-300' ?>"><span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform <?= $settings['enabled'] ? 'translate-x-5' : 'translate-x-0.5' ?>"></span></span>
            </label>
        </div>
        <div id="autoApprovalFieldset" class="space-y-2 <?= $settings['enabled'] ? '' : 'opacity-40 pointer-events-none' ?>">
            <p class="mb-2 text-[12px] font-medium uppercase tracking-wide text-text-muted">Auto-approve these priority levels</p>
            <?php foreach ($priorityInfo as $p): $checked = in_array($p['key'], $settings['priorities'], true); ?>
            <label class="flex items-center gap-3 rounded-lg border border-border p-3 cursor-pointer hover:bg-gray-50">
                <input type="checkbox" name="priorities[]" value="<?= e($p['key']) ?>" <?= $checked ? 'checked' : '' ?> class="h-4 w-4 rounded border-border text-brand-primary focus:ring-brand-primary">
                <div><div class="text-[13px] font-medium text-text-primary"><?= e($p['label']) ?></div><div class="text-[12px] text-text-secondary"><?= e($p['desc']) ?></div></div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="mt-6 rounded-md bg-tint-blue-bg p-3 text-[12px] text-tint-blue-icon">Auto-approved requisitions are attributed to "Auto-Approval System" in Audit Logs and Approved Requisitions, so there's always a clear record of which approvals were automatic versus manual.</div>
    <div class="mt-3 flex items-center gap-3">
        <button type="submit" class="flex items-center gap-2 rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="save" style="width:16px;height:16px"></i> Save Rules</button>
    </div>
    </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
