<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN']);
$user = currentUser();
$currentPath = '/system/settings';

function upsertSetting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $value]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $policy = $_POST['short_supply_policy'] ?? 'pending';
    $emailNotif = isset($_POST['email_notifications']) ? '1' : '0';
    $minStock = (int) ($_POST['default_min_stock'] ?? 20);
    $companyName = trim($_POST['company_name'] ?? '');

    upsertSetting($pdo, 'short_supply_policy', $policy);
    upsertSetting($pdo, 'email_notifications', $emailNotif);
    upsertSetting($pdo, 'default_min_stock', (string) $minStock);
    upsertSetting($pdo, 'company_name', $companyName);
    addAuditLog($pdo, $user['id'], 'UPDATE', 'AppSettings', 'global', 'Updated system settings');
    flash('success', 'Settings saved.');
    header('Location: settings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_logo') {
    if (!empty($_FILES['logo']['tmp_name'])) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'];
        $mime = mime_content_type($_FILES['logo']['tmp_name']);
        if (in_array($mime, $allowed, true) && $_FILES['logo']['size'] <= 1 * 1024 * 1024) {
            $data = base64_encode(file_get_contents($_FILES['logo']['tmp_name']));
            upsertSetting($pdo, 'company_logo', "data:{$mime};base64,{$data}");
            addAuditLog($pdo, $user['id'], 'UPDATE', 'AppSettings', 'global', 'Updated company logo');
            flash('success', 'Company logo updated.');
        } else {
            flash('error', 'Please choose a JPG, PNG, WEBP, or SVG image under 1MB.');
        }
    }
    header('Location: settings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_logo') {
    upsertSetting($pdo, 'company_logo', '');
    header('Location: settings.php');
    exit;
}

$rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
$settings = [];
foreach ($rows as $r) { $settings[$r['setting_key']] = $r['setting_value']; }

$pageTitle = 'Settings';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Settings</h1><p class="text-page-subtitle text-text-secondary mt-1">System-wide configuration and preferences</p></div>

<?php renderFlash(); ?>

<div class="max-w-2xl space-y-6">

    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <h3 class="mb-1 text-[15px] font-semibold text-text-primary">Company Branding</h3>
        <p class="mb-4 text-[13px] text-text-secondary">Shown in the sidebar and on PDF receipts.</p>
        <div class="flex items-center gap-4">
            <?php if (!empty($settings['company_logo'])): ?>
            <img src="<?= e($settings['company_logo']) ?>" alt="Company logo" class="h-14 w-14 rounded-lg border border-border object-contain bg-white p-1">
            <?php else: ?>
            <div class="flex h-14 w-14 items-center justify-center rounded-lg border border-dashed border-border text-text-muted"><i data-lucide="image" style="width:20px;height:20px"></i></div>
            <?php endif; ?>
            <div class="flex items-center gap-3">
                <form method="POST" enctype="multipart/form-data" id="logoForm">
                    <input type="hidden" name="action" value="upload_logo">
                    <input type="file" name="logo" id="logoFileInput" accept="image/jpeg,image/png,image/webp,image/svg+xml" class="hidden" onchange="document.getElementById('logoForm').submit()">
                </form>
                <button type="button" onclick="document.getElementById('logoFileInput').click()" class="rounded-button border border-border px-3 py-1.5 text-[12px] font-medium text-text-secondary hover:bg-gray-50">Upload Logo</button>
                <?php if (!empty($settings['company_logo'])): ?>
                <form method="POST"><input type="hidden" name="action" value="remove_logo">
                    <button type="submit" class="text-[12px] font-medium text-red-600 hover:underline">Remove</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <p class="mt-3 text-[11px] text-text-muted">Use a PNG, JPG, or WEBP logo — SVG shows fine in the sidebar but can't be embedded in PDF receipts (no image-processing library reads SVG here), so it'll show as text-only there.</p>
    </div>

    <form method="POST">
    <input type="hidden" name="action" value="save_settings">
    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Company Name</label>
        <input type="text" name="company_name" value="<?= e($settings['company_name'] ?? '') ?>" placeholder="e.g. Eduplex Solutions Pvt. Ltd." class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none">
        <p class="mt-1 text-[11px] text-text-muted">Leave blank to keep showing "SRIMS" in the sidebar and on receipts.</p>
    </div>

    <div class="rounded-card border border-border bg-surface-card p-card-padding mt-6">
        <h3 class="mb-1 text-[15px] font-semibold text-text-primary">Short-Supply Policy</h3>
        <p class="mb-4 text-[13px] text-text-secondary">How should partially issued line items be handled when stock is insufficient?</p>
        <div class="space-y-2">
            <label class="flex items-start gap-3 rounded-lg border border-border p-3 cursor-pointer hover:bg-gray-50">
                <input type="radio" name="short_supply_policy" value="pending" <?= ($settings['short_supply_policy'] ?? 'pending') === 'pending' ? 'checked' : '' ?> class="mt-0.5">
                <div><div class="text-[13px] font-medium text-text-primary">Mark Pending Stock</div><div class="text-[12px] text-text-secondary">Short-supplied lines remain open and will be fulfilled when stock is replenished.</div></div>
            </label>
            <label class="flex items-start gap-3 rounded-lg border border-border p-3 cursor-pointer hover:bg-gray-50">
                <input type="radio" name="short_supply_policy" value="closed" <?= ($settings['short_supply_policy'] ?? '') === 'closed' ? 'checked' : '' ?> class="mt-0.5">
                <div><div class="text-[13px] font-medium text-text-primary">Close as Short Supplied</div><div class="text-[12px] text-text-secondary">Short-supplied lines are closed permanently; the user must raise a new requisition.</div></div>
            </label>
        </div>
    </div>

    <div class="rounded-card border border-border bg-surface-card p-card-padding mt-6">
        <h3 class="mb-4 text-[15px] font-semibold text-text-primary">Notifications</h3>
        <label class="flex items-center justify-between cursor-pointer">
            <div><div class="text-[13px] font-medium text-text-primary">Email Notifications</div><div class="text-[12px] text-text-secondary">Send email alerts for approvals, rejections, and low stock</div></div>
            <input type="checkbox" name="email_notifications" <?= ($settings['email_notifications'] ?? '1') === '1' ? 'checked' : '' ?> class="hidden" id="emailNotifToggle" onchange="this.nextElementSibling.classList.toggle('bg-brand-primary'); this.nextElementSibling.classList.toggle('bg-gray-300'); this.nextElementSibling.firstElementChild.classList.toggle('translate-x-5'); this.nextElementSibling.firstElementChild.classList.toggle('translate-x-0.5');">
            <span class="relative h-6 w-11 rounded-full transition-colors <?= ($settings['email_notifications'] ?? '1') === '1' ? 'bg-brand-primary' : 'bg-gray-300' ?>"><span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform <?= ($settings['email_notifications'] ?? '1') === '1' ? 'translate-x-5' : 'translate-x-0.5' ?>"></span></span>
        </label>
    </div>

    <div class="rounded-card border border-border bg-surface-card p-card-padding mt-6">
        <h3 class="mb-1 text-[15px] font-semibold text-text-primary">Threshold Defaults</h3>
        <p class="mb-4 text-[13px] text-text-secondary">Default minimum stock level applied to newly created items</p>
        <div class="flex items-center gap-3">
            <input type="number" name="default_min_stock" value="<?= e($settings['default_min_stock'] ?? '20') ?>" class="w-32 rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none">
            <span class="text-[13px] text-text-secondary">units</span>
        </div>
    </div>

    <div class="mt-6"><button type="submit" class="flex items-center gap-2 rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="save" style="width:16px;height:16px"></i> Save Settings</button></div>
    </form>
</div>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
