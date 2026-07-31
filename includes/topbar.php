<?php
/**
 * SRIMS - Topbar (ported 1:1 from src/components/layout/Topbar.tsx).
 * Expects: $user (session array) set by the including page.
 */

$notifStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$notifStmt->execute([$user['id']]);
$notifications = $notifStmt->fetchAll();
$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));

$today = new DateTime('now');
$rangeFrom = (new DateTime($today->format('Y-m-01')))->format('Y-m-d');
$rangeTo   = (new DateTime($today->format('Y-m-t')))->format('Y-m-d');
$fmtDisplay = fn(string $iso) => (new DateTime($iso))->format('d M Y');
?>
<header class="app-topbar fixed top-0 left-0 right-0 z-30 flex h-topbar items-center justify-between border-b border-border bg-white px-6">
    <div class="flex items-center gap-3">
        <button type="button" id="mobileMenuBtn" class="rounded-md p-2 text-text-secondary hover:bg-gray-100 lg:hidden">
            <i data-lucide="menu" style="width:20px;height:20px"></i>
        </button>
    </div>

    <div class="flex items-center gap-4">

        <!-- Date Range Filter -->
        <div class="relative hidden md:block">
            <button type="button" id="dateRangeBtn" class="flex items-center gap-2 rounded-lg border border-border px-3 py-1.5 text-[13px] text-text-secondary hover:bg-gray-50">
                <i data-lucide="calendar" style="width:14px;height:14px"></i>
                <span id="dateRangeLabel"><?= e($fmtDisplay($rangeFrom)) ?> – <?= e($fmtDisplay($rangeTo)) ?></span>
                <i data-lucide="chevron-down" style="width:14px;height:14px"></i>
            </button>
            <div id="dateRangePanel" class="hidden absolute right-0 top-full z-50 mt-2 w-72 rounded-card border border-border bg-surface-card p-4 shadow-lg">
                <p class="mb-3 text-[12px] font-semibold text-text-primary">Select Date Range</p>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-medium text-text-secondary">From</label>
                        <input type="date" id="dateFrom" value="<?= e($rangeFrom) ?>" max="<?= e($rangeTo) ?>" class="w-full rounded-md border border-border px-3 py-1.5 text-[13px] text-text-primary focus:border-brand-primary focus:outline-none">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium text-text-secondary">To</label>
                        <input type="date" id="dateTo" value="<?= e($rangeTo) ?>" min="<?= e($rangeFrom) ?>" class="w-full rounded-md border border-border px-3 py-1.5 text-[13px] text-text-primary focus:border-brand-primary focus:outline-none">
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-between gap-2">
                    <button type="button" id="dateRangeReset" class="text-[12px] text-text-muted hover:text-text-primary">Reset to current month</button>
                    <button type="button" id="dateRangeApply" class="rounded-md bg-brand-primary px-3 py-1.5 text-[12px] font-medium text-white hover:opacity-90">Apply</button>
                </div>
            </div>
        </div>

        <!-- Notifications Bell -->
        <div class="relative">
            <button type="button" id="notifBellBtn" class="relative rounded-md p-2 text-text-secondary hover:bg-gray-100">
                <i data-lucide="bell" style="width:20px;height:20px"></i>
                <?php if ($unreadCount > 0): ?>
                <span class="absolute right-1 top-1 h-2 w-2 rounded-full bg-red-500"></span>
                <?php endif; ?>
            </button>
            <div id="notifPanel" class="hidden absolute right-0 top-full z-50 mt-2 w-80 rounded-card border border-border bg-surface-card shadow-lg">
                <div class="flex items-center justify-between border-b border-border px-4 py-3">
                    <span class="text-[13px] font-semibold text-text-primary">Notifications</span>
                    <?php if ($unreadCount > 0): ?>
                    <span class="text-[11px] text-text-muted"><?= $unreadCount ?> unread</span>
                    <?php endif; ?>
                </div>
                <div class="max-h-80 overflow-y-auto">
                    <?php if (empty($notifications)): ?>
                    <div class="px-4 py-8 text-center text-[13px] text-text-muted">No notifications</div>
                    <?php else: foreach ($notifications as $n): ?>
                    <a href="<?= BASE_URL . e($n['link'] ?? '#') ?>" data-notif-id="<?= e($n['id']) ?>" class="notif-item flex items-start gap-2 border-b border-border px-4 py-3 last:border-0 hover:bg-gray-50 <?= !$n['is_read'] ? 'bg-blue-50/50' : '' ?>">
                        <span class="mt-1.5 h-1.5 w-1.5 flex-shrink-0 rounded-full <?= $n['is_read'] ? 'bg-transparent' : 'bg-brand-primary' ?>"></span>
                        <div class="flex-1">
                            <p class="text-[13px] text-text-primary leading-snug"><?= e($n['message']) ?></p>
                            <p class="mt-0.5 text-[11px] text-text-muted"><?= e(timeAgo($n['created_at'])) ?></p>
                        </div>
                    </a>
                    <?php endforeach; endif; ?>
                </div>
                <?php if (!empty($notifications)): ?>
                <button type="button" id="markAllReadBtn" class="flex w-full items-center justify-center gap-1.5 border-t border-border px-4 py-2.5 text-[12px] font-medium text-brand-primary hover:bg-gray-50">
                    <i data-lucide="check" style="width:12px;height:12px"></i> Mark all as read
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="hidden h-8 w-px bg-border md:block"></div>

        <!-- User Profile -->
        <div class="relative">
            <button type="button" id="userMenuBtn" class="flex items-center gap-3 rounded-lg px-2 py-1 hover:bg-gray-50">
                <?php if (!empty($user['avatarUrl'])): ?>
                    <img src="<?= e($user['avatarUrl']) ?>" alt="<?= e($user['name']) ?>" class="h-8 w-8 rounded-full object-cover">
                <?php else: ?>
                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-primary text-[13px] font-semibold text-white"><?= e(userInitials($user['name'])) ?></div>
                <?php endif; ?>
                <div class="hidden text-left md:block">
                    <div class="text-[14px] font-semibold text-text-primary"><?= e($user['name']) ?></div>
                    <div class="text-[12px] font-medium text-text-secondary"><?= e(roleLabel($user['role'])) ?><?= $user['departmentName'] ? ' - ' . e($user['departmentName']) : '' ?></div>
                </div>
                <i data-lucide="chevron-down" class="hidden text-text-muted md:block" style="width:14px;height:14px"></i>
            </button>
            <div id="userMenuPanel" class="hidden absolute right-0 top-full z-50 mt-2 w-48 rounded-card border border-border bg-surface-card py-1 shadow-lg">
                <a href="<?= BASE_URL ?>/system/profile.php" class="flex items-center gap-2 px-4 py-2.5 text-[13px] text-text-primary hover:bg-gray-50">
                    <i data-lucide="user-circle" class="text-text-secondary" style="width:16px;height:16px"></i> Profile
                </a>
                <a href="<?= BASE_URL ?>/logout.php" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-[13px] text-red-600 hover:bg-red-50">
                    <i data-lucide="log-out" style="width:16px;height:16px"></i> Sign Out
                </a>
            </div>
        </div>
    </div>
</header>
