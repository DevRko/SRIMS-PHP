<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN']);
$user = currentUser();
$currentPath = '/masters/users';

$roleLabels = ['ADMIN' => 'Administrator', 'USER' => 'Employee', 'APPROVER' => 'Approver', 'INVENTORY_MGR' => 'Inventory Manager'];
$roleBadge = ['ADMIN' => 'bg-purple-100 text-purple-700', 'USER' => 'bg-blue-100 text-blue-700', 'APPROVER' => 'bg-green-100 text-green-700', 'INVENTORY_MGR' => 'bg-amber-100 text-amber-700'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_user') {
        $name = trim($_POST['name'] ?? '');
        $email = mb_strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? 'USER';
        $departmentId = $_POST['department_id'] ?? '';
        $editingId = $_POST['editing_id'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $error = '';

        if (!$name) $error = 'Name is required.';
        elseif (mb_strlen($name) > 80) $error = 'Name must be 80 characters or fewer.';
        elseif (!$email) $error = 'Email is required.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Please enter a valid email address.';
        elseif (!$editingId && strlen($password) < 8) $error = 'Password must be at least 8 characters.';
        elseif (!$editingId && $password !== $confirmPassword) $error = 'Passwords do not match.';
        else {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM users WHERE LOWER(email) = ? AND id != ?');
            $dup->execute([$email, $editingId ?: '']);
            if ($dup->fetchColumn() > 0) $error = "A user with the email \"{$email}\" already exists.";
        }

        if ($error) {
            flash('error', $error);
            header('Location: users.php');
            exit;
        }

        if ($editingId) {
            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, department_id=? WHERE id=?')->execute([$name, $email, $role, $departmentId, $editingId]);
            addAuditLog($pdo, $user['id'], 'UPDATE', 'User', $editingId, 'Updated user details');
            flash('success', "{$name} updated.");
        } else {
            $newId = generateId('user');
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (id, name, email, password_hash, role, department_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
                ->execute([$newId, $name, $email, $hash, $role, $departmentId]);
            addAuditLog($pdo, $user['id'], 'CREATE', 'User', $newId, "Created user {$name} ({$role})");
            addNotificationToRoles($pdo, ['ADMIN'], "New user added: {$name} ({$role})", '/masters/users');
            flash('success', "{$name} added.");
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'admin_reset_password') {
        $targetId = $_POST['target_id'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmNewPassword = $_POST['confirm_new_password'] ?? '';

        $t = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $t->execute([$targetId]);
        $targetName = $t->fetchColumn();

        if (!$targetName) {
            flash('error', 'User not found.');
        } elseif (strlen($newPassword) < 8) {
            flash('error', 'Password must be at least 8 characters.');
        } elseif ($newPassword !== $confirmNewPassword) {
            flash('error', 'Passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $targetId]);
            $note = $targetId === $user['id'] ? "Admin {$user['name']} reset their own password" : "Password reset by admin {$user['name']} for {$targetName}";
            addAuditLog($pdo, $user['id'], 'UPDATE', 'User', $targetId, $note);
            flash('success', "Password reset for {$targetName}.");
        }
        header('Location: users.php');
        exit;
    }

    if ($action === 'delete_user') {
        $id = $_POST['user_id'] ?? '';
        if ($id !== $user['id']) {
            $t = $pdo->prepare('SELECT name FROM users WHERE id=?'); $t->execute([$id]); $name = $t->fetchColumn();
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            addAuditLog($pdo, $user['id'], 'DELETE', 'User', $id, "Deleted user: {$name}");
            flash('success', "{$name} was deleted.");
        }
        header('Location: users.php');
        exit;
    }
}

$allUsers = $pdo->query('SELECT u.*, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id ORDER BY u.name')->fetchAll();
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();

$pageTitle = 'Users';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Users</h1><p class="text-page-subtitle text-text-secondary mt-1">Manage system users and role assignments</p></div>
    <button type="button" onclick="document.getElementById('user-modal-add').classList.remove('hidden'); document.getElementById('user-modal-add').classList.add('flex');" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit"><i data-lucide="plus" style="width:16px;height:16px"></i> Add User</button>
</div>

<?php renderFlash(); ?>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Name</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Email</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Role</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Actions</th>
            </tr></thead>
            <tbody>
                <?php foreach ($allUsers as $u): $isSelf = $u['id'] === $user['id']; ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3"><div class="flex items-center gap-2"><div class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-primary text-[10px] font-semibold text-white"><?= e(userInitials($u['name'])) ?></div><span class="text-[13px] font-medium text-text-primary"><?= e($u['name']) ?></span></div></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($u['email']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($u['department_name']) ?></td>
                    <td class="px-4 py-3 text-center"><span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold <?= $roleBadge[$u['role']] ?>"><?= $roleLabels[$u['role']] ?></span></td>
                    <td class="px-4 py-3">
                        <button type="button" id="toggle-user-<?= e($u['id']) ?>" onclick="handleUserToggleClick('<?= e($u['id']) ?>', <?= $u['is_active'] ? 'true' : 'false' ?>)" <?= $isSelf ? 'disabled title="You cannot deactivate your own account"' : '' ?> class="inline-flex items-center gap-2 cursor-pointer disabled:cursor-not-allowed">
                            <span class="toggle-track relative flex-shrink-0 overflow-hidden h-5 w-9 rounded-full transition-colors <?= $isSelf ? 'opacity-40' : '' ?> <?= $u['is_active'] ? 'bg-green-500' : 'bg-gray-300' ?>"><span class="toggle-thumb absolute left-0 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform <?= $u['is_active'] ? 'translate-x-4' : 'translate-x-0.5' ?>"></span></span>
                            <span class="toggle-label text-[12px] font-medium <?= $isSelf ? 'text-text-muted' : ($u['is_active'] ? 'text-green-600' : 'text-gray-400') ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span>
                        </button>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" onclick="document.getElementById('user-modal-<?= e($u['id']) ?>').classList.remove('hidden'); document.getElementById('user-modal-<?= e($u['id']) ?>').classList.add('flex');" title="Edit" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="pencil" style="width:14px;height:14px"></i></button>
                            <button type="button" onclick="document.getElementById('user-reset-pw-<?= e($u['id']) ?>').classList.remove('hidden'); document.getElementById('user-reset-pw-<?= e($u['id']) ?>').classList.add('flex');" title="Reset Password" class="rounded p-1.5 text-text-secondary hover:bg-amber-50 hover:text-amber-600"><i data-lucide="key-round" style="width:14px;height:14px"></i></button>
                            <button type="button" onclick="document.getElementById('user-del-<?= e($u['id']) ?>').classList.remove('hidden'); document.getElementById('user-del-<?= e($u['id']) ?>').classList.add('flex');" <?= $isSelf ? 'disabled' : '' ?> title="Delete" class="rounded p-1.5 text-text-secondary hover:bg-red-50 hover:text-red-600 disabled:opacity-30"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function renderUserModal(string $modalId, string $title, array $u, array $departments, array $roleLabels, string $editingId): void {
?>
<div id="<?= $modalId ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="w-full max-w-md rounded-card bg-surface-card p-6 shadow-lg">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-[16px] font-semibold text-text-primary"><?= e($title) ?></h3>
            <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="save_user"><input type="hidden" name="editing_id" value="<?= e($editingId) ?>">
        <div class="space-y-4">
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Full Name *</label><input type="text" name="name" value="<?= e($u['name'] ?? '') ?>" maxlength="80" placeholder="e.g. Amit Sharma" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Email *</label><input type="email" name="email" value="<?= e($u['email'] ?? '') ?>" maxlength="120" placeholder="user@srims.com" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Role *</label>
                <select name="role" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none">
                    <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= ($u['role'] ?? 'USER') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Department *</label>
                <select name="department_id" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none">
                    <?php foreach ($departments as $d): ?><option value="<?= e($d['id']) ?>" <?= ($u['department_id'] ?? '') === $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if (!$editingId): ?>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Password *</label><input type="password" name="password" minlength="8" required placeholder="At least 8 characters" class="w-full rounded-button border border-border px-3 py-2 text-[14px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Confirm Password *</label><input type="password" name="confirm_password" minlength="8" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
            <div class="rounded-md bg-tint-blue-bg p-2.5 text-[12px] text-tint-blue-icon">Set this user's initial password. They can change it any time from Profile → Change Password, and an Admin can always reset it later from this page.</div>
            <?php endif; ?>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
            <button type="submit" class="rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"><?= $editingId ? 'Save Changes' : 'Add User' ?></button>
        </div>
        </form>
    </div>
</div>
<?php
}
renderUserModal('user-modal-add', 'Add User', ['department_id' => $departments[0]['id'] ?? ''], $departments, $roleLabels, '');
foreach ($allUsers as $u) {
    renderUserModal('user-modal-' . $u['id'], 'Edit User', $u, $departments, $roleLabels, $u['id']);
    ?>
    <div id="user-reset-pw-<?= e($u['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
        <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-[16px] font-semibold text-text-primary">Reset Password</h3>
                <button type="button" onclick="document.getElementById('user-reset-pw-<?= e($u['id']) ?>').classList.add('hidden'); document.getElementById('user-reset-pw-<?= e($u['id']) ?>').classList.remove('flex');" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button>
            </div>
            <p class="mb-4 text-[13px] text-text-secondary">Set a new password for <strong><?= e($u['name']) ?></strong>. They won't need to know their old one — let them know the new password directly.</p>
            <form method="POST">
            <input type="hidden" name="action" value="admin_reset_password"><input type="hidden" name="target_id" value="<?= e($u['id']) ?>">
            <div class="space-y-3">
                <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">New Password *</label><input type="password" name="new_password" minlength="8" required placeholder="At least 8 characters" class="w-full rounded-button border border-border px-3 py-2 text-[14px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none"></div>
                <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Confirm New Password *</label><input type="password" name="confirm_new_password" minlength="8" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('user-reset-pw-<?= e($u['id']) ?>').classList.add('hidden'); document.getElementById('user-reset-pw-<?= e($u['id']) ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <button type="submit" class="rounded-button bg-amber-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-amber-700">Reset Password</button>
            </div>
            </form>
        </div>
    </div>
    <?php
    if ($u['id'] !== $user['id']) {
    ?>
    <div id="user-deactivate-confirm-<?= e($u['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
        <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
            <h3 class="mb-2 text-[15px] font-semibold text-text-primary">Deactivate user?</h3>
            <p class="mb-1 text-[13px] text-text-secondary"><strong><?= e($u['name']) ?></strong> will immediately lose the ability to log in. Their data and history are preserved.</p>
            <p class="mb-4 text-[12px] text-text-muted">You can re-activate them at any time by toggling the switch again.</p>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('user-deactivate-confirm-<?= e($u['id']) ?>').classList.add('hidden'); document.getElementById('user-deactivate-confirm-<?= e($u['id']) ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <button type="button" onclick="confirmDeactivateUser('<?= e($u['id']) ?>')" class="rounded-button bg-amber-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-amber-700">Yes, Deactivate</button>
            </div>
        </div>
    </div>
    <?php
    ?>
    <div id="user-del-<?= e($u['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
        <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
            <h3 class="mb-2 text-[15px] font-semibold text-text-primary">Delete user?</h3>
            <p class="mb-4 text-[13px] text-text-secondary">This will permanently remove their account access.</p>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('user-del-<?= e($u['id']) ?>').classList.add('hidden'); document.getElementById('user-del-<?= e($u['id']) ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <form method="POST"><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                    <button type="submit" class="rounded-button bg-red-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-red-700">Delete</button>
                </form>
            </div>
        </div>
    </div>
    <?php
    }
}
?>
<script>
function setUserToggleVisual(id, isActive) {
    var btn = document.getElementById('toggle-user-' + id);
    var track = btn.querySelector('.toggle-track');
    var thumb = btn.querySelector('.toggle-thumb');
    var label = btn.querySelector('.toggle-label');
    if (isActive) {
        track.classList.remove('bg-gray-300'); track.classList.add('bg-green-500');
        thumb.classList.remove('translate-x-0.5'); thumb.classList.add('translate-x-4');
        label.classList.remove('text-gray-400'); label.classList.add('text-green-600');
        label.textContent = 'Active';
    } else {
        track.classList.remove('bg-green-500'); track.classList.add('bg-gray-300');
        thumb.classList.remove('translate-x-4'); thumb.classList.add('translate-x-0.5');
        label.classList.remove('text-green-600'); label.classList.add('text-gray-400');
        label.textContent = 'Inactive';
    }
}

function callToggleUserApi(id) {
    return fetch('<?= BASE_URL ?>/api/toggle-active.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'entity=user&id=' + encodeURIComponent(id),
    }).then(function (r) { return r.json(); });
}

// Re-activating → no confirmation needed. Deactivating → confirm first.
function handleUserToggleClick(id, isCurrentlyActive) {
    if (isCurrentlyActive) {
        var modal = document.getElementById('user-deactivate-confirm-' + id);
        modal.classList.remove('hidden'); modal.classList.add('flex');
        return;
    }
    var btn = document.getElementById('toggle-user-' + id);
    btn.disabled = true;
    callToggleUserApi(id).then(function (data) {
        if (!data.ok) { alert(data.error || 'Could not update status'); return; }
        setUserToggleVisual(id, data.is_active);
        btn.setAttribute('onclick', "handleUserToggleClick('" + id + "', " + data.is_active + ")");
    }).catch(function () {
        alert('Network error — please try again.');
    }).finally(function () { btn.disabled = false; });
}

function confirmDeactivateUser(id) {
    var modal = document.getElementById('user-deactivate-confirm-' + id);
    var btn = document.getElementById('toggle-user-' + id);
    btn.disabled = true;
    callToggleUserApi(id).then(function (data) {
        if (!data.ok) { alert(data.error || 'Could not update status'); return; }
        setUserToggleVisual(id, data.is_active);
        btn.setAttribute('onclick', "handleUserToggleClick('" + id + "', " + data.is_active + ")");
        modal.classList.add('hidden'); modal.classList.remove('flex');
    }).catch(function () {
        alert('Network error — please try again.');
    }).finally(function () { btn.disabled = false; });
}
</script>
<?php
include __DIR__ . '/../includes/app-shell-end.php';
