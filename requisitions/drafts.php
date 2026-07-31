<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user = currentUser();
$currentPath = '/requisitions/drafts';

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
        }
    } elseif ($draft && $action === 'delete') {
        $pdo->prepare('DELETE FROM requisitions WHERE id = ?')->execute([$reqId]);
        addAuditLog($pdo, $user['id'], 'DELETE', 'Requisition', $reqId, 'Deleted draft requisition');
        flash('success', "Draft {$reqId} deleted.");
    }
    header('Location: drafts.php');
    exit;
}

$sql = 'SELECT r.*, u.name AS user_name, (SELECT COUNT(*) FROM requisition_items WHERE requisition_id = r.id) AS item_count
        FROM requisitions r JOIN users u ON u.id = r.user_id WHERE r.status = \'DRAFT\''
     . ($user['role'] === 'ADMIN' ? '' : ' AND r.user_id = ?') . ' ORDER BY r.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($user['role'] === 'ADMIN' ? [] : [$user['id']]);
$drafts = $stmt->fetchAll();

$pageTitle = 'Drafts';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div>
        <h1 class="text-page-title text-text-primary">Drafts</h1>
        <p class="text-page-subtitle text-text-secondary mt-1">Manage your saved requisition drafts</p>
    </div>
    <a href="new.php" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit">
        <i data-lucide="plus" style="width:16px;height:16px"></i> New Requisition
    </a>
</div>

<?php renderFlash(); ?>

<div class="rounded-card border border-border bg-surface-card">
    <?php if (empty($drafts)): ?>
    <div class="p-12 text-center">
        <div class="text-[40px] mb-3">📝</div>
        <h3 class="text-[16px] font-semibold text-text-primary mb-1">No drafts</h3>
        <p class="text-[13px] text-text-secondary mb-4">You don't have any saved drafts. Start a new requisition to save one.</p>
        <a href="new.php" class="inline-flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover">
            <i data-lucide="plus" style="width:14px;height:14px"></i> New Requisition
        </a>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Purpose</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Items</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Created</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Actions</th>
            </tr></thead>
            <tbody>
                <?php foreach ($drafts as $req): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3 text-[13px] font-medium text-brand-primary"><?= e($req['id']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($req['purpose'] ?: '—') ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-primary"><?= (int) $req['item_count'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $req['total_amount']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= formatDate($req['created_at']) ?></td>
                    <td class="px-4 py-3 text-center"><?= statusPill('draft') ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" onclick="openReqModal('<?= e($req['id']) ?>')" title="View" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="eye" style="width:14px;height:14px"></i></button>
                            <a href="new.php?edit=<?= e($req['id']) ?>" title="Edit" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="pencil" style="width:14px;height:14px"></i></a>
                            <form method="POST" class="inline" onsubmit="return confirm('Submit this draft for approval?')">
                                <input type="hidden" name="action" value="submit"><input type="hidden" name="req_id" value="<?= e($req['id']) ?>">
                                <button type="submit" title="Submit" <?= $req['item_count'] == 0 ? 'disabled' : '' ?> class="rounded p-1.5 text-text-secondary hover:bg-green-50 hover:text-green-600 disabled:opacity-30"><i data-lucide="send" style="width:14px;height:14px"></i></button>
                            </form>
                            <button type="button" onclick="document.getElementById('del-confirm-<?= e($req['id']) ?>').classList.remove('hidden')" title="Delete" class="rounded p-1.5 text-text-secondary hover:bg-red-50 hover:text-red-600"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/../includes/requisition-modal.php';
foreach ($drafts as $req) {
    renderRequisitionModal($pdo, $req);
    ?>
    <div id="del-confirm-<?= e($req['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
        <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
            <h3 class="mb-2 text-[15px] font-semibold text-text-primary">Delete draft?</h3>
            <p class="mb-4 text-[13px] text-text-secondary">This draft and its items will be permanently removed. This cannot be undone.</p>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('del-confirm-<?= e($req['id']) ?>').classList.add('hidden')" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="req_id" value="<?= e($req['id']) ?>">
                    <button type="submit" class="rounded-button bg-red-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-red-700">Delete</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}
include __DIR__ . '/../includes/app-shell-end.php';