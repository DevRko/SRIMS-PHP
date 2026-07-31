<?php
require_once __DIR__ . '/config.php';

$submitted = false;
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailValue = trim($_POST['email'] ?? '');
    $submitted = true;

    if ($emailValue !== '') {
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE LOWER(email) = LOWER(?)');
        $stmt->execute([$emailValue]);
        $dbUser = $stmt->fetch();

        // Always behave the same whether the email exists or not (matches
        // the original API route's anti-enumeration behaviour).
        if ($dbUser) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
            $ins = $pdo->prepare('INSERT INTO password_reset_tokens (id, user_id, token, expires_at) VALUES (?, ?, ?, ?)');
            $ins->execute([generateId('prt'), $dbUser['id'], $token, $expiresAt]);

            $resetUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . BASE_URL . '/reset-password.php?token=' . $token;
            // No SMTP/email provider is configured in this build — log the
            // reset link server-side instead of sending an email. Wire a
            // real provider later by replacing this error_log() call.
            error_log("[forgot-password] Reset link for {$dbUser['email']}: {$resetUrl}");
        }
    }
}

$pageTitle = 'Reset your password';
include __DIR__ . '/includes/header.php';
?>
<div class="flex min-h-screen items-center justify-center bg-surface-app p-4">
    <div class="w-full max-w-[400px] rounded-card border border-border bg-surface-card p-8 shadow-sm">
        <div class="mb-8 flex flex-col items-center">
            <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-primary">
                <i data-lucide="shopping-cart" class="text-white" style="width:24px;height:24px"></i>
            </div>
            <h1 class="text-[20px] font-bold text-text-primary">Reset your password</h1>
            <p class="mt-1 text-center text-[13px] text-text-secondary">Enter your email and we'll send you a reset link</p>
        </div>

        <?php if ($submitted): ?>
        <div class="rounded-md bg-tint-green-bg p-4 text-center">
            <i data-lucide="mail" class="mx-auto mb-2 text-tint-green-icon" style="width:24px;height:24px"></i>
            <p class="text-[13px] text-text-primary">If an account exists for <span class="font-medium"><?= e($emailValue) ?></span>, a reset link has been sent.</p>
            <p class="mt-2 text-[12px] text-text-secondary">Running this app without a connected mail server? There's nowhere to send a real email yet — use the demo credentials shown on the login screen instead.</p>
        </div>
        <?php else: ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Email Address</label>
                <input type="email" name="email" placeholder="you@srims.com" required
                    class="w-full rounded-button border border-border px-3 py-2 text-[14px] text-text-primary placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
            </div>
            <button type="submit" class="w-full rounded-button bg-brand-primary px-4 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover disabled:opacity-60">Send Reset Link</button>
        </form>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/login.php" class="mt-6 flex items-center justify-center gap-1.5 text-[13px] text-brand-primary hover:underline">
            <i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back to Sign In
        </a>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
