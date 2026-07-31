<?php
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT u.*, d.name AS department_name FROM users u
         JOIN departments d ON d.id = u.department_id
         WHERE LOWER(u.email) = LOWER(?)'
    );
    $stmt->execute([$emailValue]);
    $dbUser = $stmt->fetch();

    if (!$dbUser || !$dbUser['is_active'] || !password_verify($password, $dbUser['password_hash'])) {
        $error = 'Invalid email or password';
    } else {
        login($dbUser);
        addAuditLog($pdo, $dbUser['id'], 'LOGIN', 'User', $dbUser['id'], "User {$dbUser['name']} logged in ({$dbUser['role']})");
        $redirect = $_GET['redirect'] ?? ($_POST['redirect'] ?? '');
        header('Location: ' . BASE_URL . ($redirect ? urldecode($redirect) : '/dashboard.php'));
        exit;
    }
}

$pageTitle = 'Sign In';
include __DIR__ . '/includes/header.php';
?>
<div class="flex min-h-screen items-center justify-center bg-surface-app p-4">
    <div class="w-full max-w-[400px] rounded-card border border-border bg-surface-card p-8 shadow-sm">
        <div class="mb-8 flex flex-col items-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-primary">
                <i data-lucide="shopping-cart" class="text-white" style="width:24px;height:24px"></i>
            </div>
            <h1 class="text-[20px] font-bold text-text-primary">SRIMS</h1>
            <p class="mt-1 text-center text-[13px] text-text-secondary">Stationery Requisition &amp; Inventory Management System</p>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="redirect" value="<?= e($_GET['redirect'] ?? '') ?>">
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Email Address</label>
                <input type="email" name="email" value="<?= e($emailValue) ?>" placeholder="you@srims.com" required
                    class="w-full rounded-button border border-border px-3 py-2 text-[14px] text-text-primary placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Password</label>
                <div class="relative">
                    <input type="password" name="password" id="passwordInput" placeholder="••••••••" required
                        class="w-full rounded-button border border-border px-3 py-2 pr-10 text-[14px] text-text-primary placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
                    <button type="button" id="togglePasswordBtn" class="absolute right-3 top-1/2 -translate-y-1/2 text-text-muted hover:text-text-secondary">
                        <i data-lucide="eye" id="togglePasswordIcon" style="width:16px;height:16px"></i>
                    </button>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="rounded-md bg-status-rejected-bg px-3 py-2 text-[13px] text-status-rejected-text"><?= e($error) ?></div>
            <?php endif; ?>

            <button type="submit" id="submitBtn" class="w-full rounded-button bg-brand-primary px-4 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60 transition-colors">
                Sign In
            </button>
        </form>
     
<!--
        <div class="mt-4 text-center">
            <a href="<?= BASE_URL ?>/forgot-password.php" class="text-[13px] text-brand-primary hover:text-brand-primary-hover">Forgot Password?</a>
        </div>
-->

        <div class="mt-6 rounded-md bg-tint-blue-bg p-3">
            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-tint-blue-icon">Demo Credentials</p>
            <div class="space-y-0.5 text-[11px] text-text-secondary">
                <p>Admin: rahul@srims.com / Admin@123</p>
                <p>User: priya@srims.com / User@123</p>
                <p>Approver: amit@srims.com / Approver@123</p>
                <p>Inventory: sandeep@srims.com / Inventory@123</p>
            </div>
        </div>
    </div>


</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>
if (window.lucide) lucide.createIcons();
document.getElementById('togglePasswordBtn').addEventListener('click', function () {
    var input = document.getElementById('passwordInput');
    var icon = document.getElementById('togglePasswordIcon');
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    icon.setAttribute('data-lucide', showing ? 'eye' : 'eye-off');
    if (window.lucide) lucide.createIcons();
});
document.querySelector('form').addEventListener('submit', function () {
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Signing in...';
});
</script>
</body>
</html>
