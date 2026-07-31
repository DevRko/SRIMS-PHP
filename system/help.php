<?php
require_once __DIR__ . '/../config.php';
requireLogin();
$user = currentUser();
$currentPath = '/system/help';

$faqs = [
    ['q' => 'How do I create a new requisition?', 'a' => 'Go to Requisitions → New Requisition, add items to your cart from the catalog, fill in the required date and purpose, then review and submit. You can also save it as a draft and finish later.'],
    ['q' => 'Who approves my requisition?', 'a' => "Requisitions are routed to your department's assigned Approver. You can track the status (Pending, Approved, Rejected) under My Requisitions."],
    ['q' => 'What happens after a requisition is approved?', 'a' => 'It moves to the Issue Queue, where an Inventory Manager processes the physical handover of items. If stock is insufficient, you may receive a Partial issuance.'],
    ['q' => 'How are low stock items identified?', 'a' => 'Every item has a minimum stock threshold set by an Inventory Manager. Items below that threshold appear under Inventory → Low Stock Alerts, color-coded by severity.'],
    ['q' => 'I forgot my password — what do I do?', 'a' => 'Use the "Forgot Password" link on the login screen, or reach out to your system administrator using the contact details below.'],
];

$pageTitle = 'Help & Support';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Help &amp; Support</h1><p class="text-page-subtitle text-text-secondary mt-1">Get in touch with the SRIMS support team or browse common questions</p></div>

<div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <a href="mailto:contacts@eduplex.in" class="rounded-card border border-border bg-surface-card p-card-padding transition-colors hover:border-brand-primary hover:shadow-sm block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-blue-bg"><i data-lucide="mail" class="text-tint-blue-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[14px] font-semibold text-text-primary">General Support</h3><p class="mt-1 text-[13px] text-brand-primary">contacts@eduplex.in</p>
    </a>
    <a href="mailto:csr@eduplex.in" class="rounded-card border border-border bg-surface-card p-card-padding transition-colors hover:border-brand-primary hover:shadow-sm block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-purple-bg"><i data-lucide="mail" class="text-tint-purple-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[14px] font-semibold text-text-primary">Customer Service</h3><p class="mt-1 text-[13px] text-brand-primary">csr@eduplex.in</p>
    </a>
    <a href="tel:+918337056594" class="rounded-card border border-border bg-surface-card p-card-padding transition-colors hover:border-brand-primary hover:shadow-sm block">
        <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-tint-green-bg"><i data-lucide="phone" class="text-tint-green-icon" style="width:20px;height:20px"></i></div>
        <h3 class="text-[14px] font-semibold text-text-primary">Phone Support</h3><p class="mt-1 text-[13px] text-brand-primary">+91 83370 56594</p>
    </a>
</div>

<div class="mb-8 flex items-center gap-2 rounded-md bg-tint-blue-bg px-4 py-2.5 text-[13px] text-tint-blue-icon">
    <i data-lucide="clock" style="width:14px;height:14px"></i> Support is typically available Monday–Saturday, 9:00 AM – 6:30 PM IST.
</div>

<div class="rounded-card border border-border bg-surface-card p-card-padding">
    <h3 class="mb-4 flex items-center gap-2 text-[15px] font-semibold text-text-primary"><i data-lucide="message-circle-question" class="text-brand-primary" style="width:18px;height:18px"></i> Frequently Asked Questions</h3>
    <div class="divide-y divide-border">
        <?php foreach ($faqs as $item): ?>
        <div class="py-3 first:pt-0 last:pb-0">
            <h4 class="text-[13px] font-semibold text-text-primary"><?= e($item['q']) ?></h4>
            <p class="mt-1 text-[13px] text-text-secondary"><?= e($item['a']) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
