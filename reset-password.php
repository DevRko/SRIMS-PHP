<?php
require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM password_reset_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $resetToken = $stmt->fetch();

        if (!$resetToken || $resetToken['used_at'] || strtotime($resetToken['expires_at']) < time()) {
            $error = 'This reset link is invalid or has expired';
        } else {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $resetToken['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')->execute([$resetToken['id']]);
            $pdo->commit();
            $success = true;
        }
    }
}

$pageTitle = 'Set a new password';
include __DIR__ . '/includes/header.php';
?>
<div class="flex min-h-screen items-center justify-center bg-surface-app p-4">
<?php if (!$token): ?>
    <div class="w-full max-w-[400px] rounded-card border border-border bg-surface-card p-8 text-center shadow-sm">
        <p class="text-[14px] text-text-primary">This reset link is missing its token.</p>
        <a href="<?= BASE_URL ?>/forgot-password.php" class="mt-3 inline-block text-[13px] text-brand-primary hover:underline">Request a new link</a>
    </div>
<?php else: ?>
    <div class="w-full max-w-[400px] rounded-card border border-border bg-surface-card p-8 shadow-sm">
        <div class="mb-8 flex flex-col items-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-primary">
                <i data-lucide="shopping-cart" class="text-white" style="width:24px;height:24px"></i>
            </div>
            <h1 class="text-[20px] font-bold text-text-primary">Set a new password</h1>
        </div>

        <?php if ($success): ?>
        <div class="rounded-md bg-tint-green-bg p-4 text-center">
            <i data-lucide="check-circle-2" class="mx-auto mb-2 text-tint-green-icon" style="width:24px;height:24px"></i>
            <p class="text-[13px] text-text-primary">Password updated. <a href="<?= BASE_URL ?>/login.php" class="underline">Sign in</a>.</p>
        </div>
        <?php else: ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">New Password</label>
                <div class="relative">
                    <input type="password" name="password" id="passwordInput" class="w-full rounded-button border border-border px-3 py-2 pr-10 text-[14px] focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
                    <button type="button" id="togglePasswordBtn" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-muted hover:text-text-secondary">
                        <i data-lucide="eye" id="togglePasswordIcon" style="width:16px;height:16px"></i>
                    </button>
                </div>
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Confirm New Password</label>
                <input type="password" name="confirm_password" id="confirmInput" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
            </div>
            <?php if ($error): ?>
            <div class="rounded-md bg-status-rejected-bg px-3 py-2 text-[13px] text-status-rejected-text"><?= e($error) ?></div>
            <?php endif; ?>
            <button type="submit" class="w-full rounded-button bg-brand-primary px-4 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60">Update Password</button>
        </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
if (window.lucide) lucide.createIcons();
var toggleBtn = document.getElementById('togglePasswordBtn');
if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
        var input = document.getElementById('passwordInput');
        var confirmInput = document.getElementById('confirmInput');
        var icon = document.getElementById('togglePasswordIcon');
        var showing = input.type === 'text';
        input.type = confirmInput.type = showing ? 'password' : 'text';
        icon.setAttribute('data-lucide', showing ? 'eye' : 'eye-off');
        if (window.lucide) lucide.createIcons();
    });
}
<?php if ($success): ?>
setTimeout(function () { window.location.href = '<?= BASE_URL ?>/login.php'; }, 2500);
<?php endif; ?>
</script>
</body>
</html>
