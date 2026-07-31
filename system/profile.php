<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user = currentUser();
$currentPath = '/system/profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
            $_SESSION['user']['name'] = $name;
            addAuditLog($pdo, $user['id'], 'UPDATE', 'User', $user['id'], 'Updated user details');
            flash('success', 'Profile updated.');
        }
        header('Location: profile.php');
        exit;
    }

    if ($action === 'upload_avatar') {
        if (!empty($_FILES['avatar']['tmp_name'])) {
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            $mime = mime_content_type($_FILES['avatar']['tmp_name']);
            if (in_array($mime, $allowed, true) && $_FILES['avatar']['size'] <= 2 * 1024 * 1024) {
                $data = base64_encode(file_get_contents($_FILES['avatar']['tmp_name']));
                $dataUrl = "data:{$mime};base64,{$data}";
                $pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$dataUrl, $user['id']]);
                $_SESSION['user']['avatarUrl'] = $dataUrl;
                flash('success', 'Profile photo updated.');
            } else {
                flash('error', 'Please choose a JPG, PNG, or WEBP image under 2MB.');
            }
        }
        header('Location: profile.php');
        exit;
    }

    if ($action === 'remove_avatar') {
        $pdo->prepare('UPDATE users SET avatar_url = NULL WHERE id = ?')->execute([$user['id']]);
        $_SESSION['user']['avatarUrl'] = null;
        header('Location: profile.php');
        exit;
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'Passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            addAuditLog($pdo, $user['id'], 'UPDATE', 'User', $user['id'], 'Changed password');
            flash('success', 'Password updated.');
        }
        header('Location: profile.php');
        exit;
    }
}

$roleLabels = ['ADMIN' => 'Administrator', 'USER' => 'Employee', 'APPROVER' => 'Manager / Approver', 'INVENTORY_MGR' => 'Inventory Manager'];

$pageTitle = 'Profile';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Profile</h1><p class="text-page-subtitle text-text-secondary mt-1">Manage your account information and password</p></div>

<?php renderFlash(); ?>

<div class="max-w-2xl space-y-6">
    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <div class="mb-6 flex items-center gap-4">
            <div class="group relative">
                <?php if (!empty($user['avatarUrl'])): ?>
                <img src="<?= e($user['avatarUrl']) ?>" alt="<?= e($user['name']) ?>" class="h-16 w-16 rounded-full object-cover">
                <?php else: ?>
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-brand-primary text-[22px] font-bold text-white"><?= e(userInitials($user['name'])) ?></div>
                <?php endif; ?>
                <button type="button" onclick="document.getElementById('avatarFileInput').click()" class="absolute inset-0 flex items-center justify-center rounded-full bg-black/0 text-white opacity-0 transition-opacity hover:bg-black/40 hover:opacity-100"><i data-lucide="camera" style="width:18px;height:18px"></i></button>
            </div>
            <div>
                <h3 class="text-[16px] font-semibold text-text-primary"><?= e($user['name']) ?></h3>
                <p class="text-[13px] text-text-secondary"><?= e($roleLabels[$user['role']] ?? $user['role']) ?> · <?= e($user['departmentName']) ?></p>
                <div class="mt-1.5 flex items-center gap-3">
                    <button type="button" onclick="document.getElementById('avatarFileInput').click()" class="text-[12px] font-medium text-brand-primary hover:underline">Upload photo</button>
                    <?php if (!empty($user['avatarUrl'])): ?>
                    <form method="POST" class="inline"><input type="hidden" name="action" value="remove_avatar"><button type="submit" class="flex items-center gap-1 text-[12px] font-medium text-red-600 hover:underline"><i data-lucide="trash-2" style="width:12px;height:12px"></i> Remove</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <form method="POST" enctype="multipart/form-data" id="avatarForm">
            <input type="hidden" name="action" value="upload_avatar">
            <input type="file" name="avatar" id="avatarFileInput" accept="image/jpeg,image/jpg,image/png,image/webp" class="hidden" onchange="document.getElementById('avatarForm').submit()">
        </form>

        <form method="POST">
        <input type="hidden" name="action" value="update_profile">
        <div class="space-y-4">
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Full Name</label><input type="text" name="name" value="<?= e($user['name']) ?>" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Email Address</label><input type="email" value="<?= e($user['email']) ?>" readonly class="w-full rounded-button border border-border bg-gray-50 px-3 py-2 text-[14px] text-text-secondary"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Department</label><input type="text" value="<?= e($user['departmentName']) ?>" readonly class="w-full rounded-button border border-border bg-gray-50 px-3 py-2 text-[14px] text-text-secondary"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Role</label><input type="text" value="<?= e($roleLabels[$user['role']] ?? $user['role']) ?>" readonly class="w-full rounded-button border border-border bg-gray-50 px-3 py-2 text-[14px] text-text-secondary"></div>
        </div>
        <div class="mt-4"><button type="submit" class="flex items-center gap-2 rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="save" style="width:16px;height:16px"></i> Save Changes</button></div>
        </form>
    </div>

    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <h3 class="mb-4 text-[15px] font-semibold text-text-primary">Change Password</h3>
        <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="space-y-4">
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Current Password</label><input type="password" name="current_password" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">New Password</label><input type="password" name="new_password" minlength="8" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Confirm New Password</label><input type="password" name="confirm_password" minlength="8" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary"></div>
        </div>
        <div class="mt-4"><button type="submit" class="flex items-center gap-2 rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="save" style="width:16px;height:16px"></i> Update Password</button></div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
