<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN']);
$user = currentUser();
$currentPath = '/masters/departments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_department') {
        $name = trim($_POST['name'] ?? '');
        $editingId = $_POST['editing_id'] ?? '';
        if ($name) {
            if ($editingId) {
                $old = $pdo->prepare('SELECT name FROM departments WHERE id=?'); $old->execute([$editingId]); $oldName = $old->fetchColumn();
                $pdo->prepare('UPDATE departments SET name=? WHERE id=?')->execute([$name, $editingId]);
                addAuditLog($pdo, $user['id'], 'UPDATE', 'Department', $editingId, "Renamed department from \"{$oldName}\" to \"{$name}\"");
                flash('success', "Department renamed to {$name}.");
            } else {
                $newId = generateId('dept');
                $pdo->prepare('INSERT INTO departments (id, name) VALUES (?, ?)')->execute([$newId, $name]);
                addAuditLog($pdo, $user['id'], 'CREATE', 'Department', $newId, "Created department: {$name}");
                flash('success', "{$name} added.");
            }
        }
        header('Location: departments.php');
        exit;
    }
    if ($action === 'delete_department') {
        $id = $_POST['department_id'] ?? '';
        $hasUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE department_id=' . $pdo->quote($id))->fetchColumn() > 0;
        if (!$hasUsers) {
            $name = $pdo->query('SELECT name FROM departments WHERE id=' . $pdo->quote($id))->fetchColumn();
            $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            addAuditLog($pdo, $user['id'], 'DELETE', 'Department', $id, "Deleted department: {$name}");
            flash('success', 'Department deleted.');
        } else {
            flash('error', 'Cannot delete a department that still has users assigned to it.');
        }
        header('Location: departments.php');
        exit;
    }
}

$departments = $pdo->query('SELECT d.*, (SELECT COUNT(*) FROM users WHERE department_id = d.id) AS user_count FROM departments d ORDER BY d.name')->fetchAll();

$pageTitle = 'Departments';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Departments</h1><p class="text-page-subtitle text-text-secondary mt-1">Manage organizational departments</p></div>
    <button type="button" onclick="document.getElementById('dept-modal-add').classList.remove('hidden'); document.getElementById('dept-modal-add').classList.add('flex');" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit"><i data-lucide="plus" style="width:16px;height:16px"></i> Add Department</button>
</div>

<?php renderFlash(); ?>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Users</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Actions</th>
            </tr></thead>
            <tbody>
                <?php foreach ($departments as $d): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3 text-[13px] font-medium text-text-primary"><?= e($d['name']) ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-secondary"><?= (int) $d['user_count'] ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" onclick="document.getElementById('dept-modal-<?= e($d['id']) ?>').classList.remove('hidden'); document.getElementById('dept-modal-<?= e($d['id']) ?>').classList.add('flex');" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="pencil" style="width:14px;height:14px"></i></button>
                            <button type="button" onclick="document.getElementById('dept-del-<?= e($d['id']) ?>').classList.remove('hidden'); document.getElementById('dept-del-<?= e($d['id']) ?>').classList.add('flex');" <?= $d['user_count'] > 0 ? 'disabled title="Cannot delete a department with users assigned"' : '' ?> class="rounded p-1.5 text-text-secondary hover:bg-red-50 hover:text-red-600 disabled:opacity-30"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ([['id' => '', 'name' => ''], ...$departments] as $i => $d): $isAdd = $i === 0; $modalId = $isAdd ? 'dept-modal-add' : 'dept-modal-' . $d['id']; ?>
<div id="<?= $modalId ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-[16px] font-semibold text-text-primary"><?= $isAdd ? 'Add Department' : 'Edit Department' ?></h3>
            <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="save_department"><input type="hidden" name="editing_id" value="<?= e($d['id']) ?>">
        <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Department Name *</label>
        <input type="text" name="name" value="<?= e($d['name']) ?>" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none">
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
            <button type="submit" class="rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"><?= $isAdd ? 'Add Department' : 'Save Changes' ?></button>
        </div>
        </form>
    </div>
</div>
<?php if (!$isAdd): ?>
<div id="dept-del-<?= e($d['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
        <h3 class="mb-2 text-[15px] font-semibold text-text-primary">Delete department?</h3>
        <p class="mb-4 text-[13px] text-text-secondary">This can't be undone. Departments with assigned users cannot be deleted.</p>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('dept-del-<?= e($d['id']) ?>').classList.add('hidden'); document.getElementById('dept-del-<?= e($d['id']) ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
            <form method="POST"><input type="hidden" name="action" value="delete_department"><input type="hidden" name="department_id" value="<?= e($d['id']) ?>">
                <button type="submit" class="rounded-button bg-red-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-red-700">Delete</button>
            </form>
        </div>
    </div>
</div>
<?php endif; endforeach; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
