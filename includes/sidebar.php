<?php
/**
 * SRIMS - Sidebar (ported 1:1 from src/components/layout/Sidebar.tsx +
 * src/lib/navigation.ts). Icons rendered via the Lucide CDN (same icon
 * set as lucide-react) so every glyph matches the original pixel-for-pixel.
 *
 * Expects: $user (session array), $currentPath (e.g. "/requisitions/new")
 * set by the including page.
 */

$navigation = [
    [
        'title' => 'MAIN',
        'items' => [
            ['label' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'layout-dashboard', 'roles' => ['ADMIN', 'APPROVER', 'INVENTORY_MGR']],
            ['label' => 'Requisitions', 'href' => '/requisitions', 'icon' => 'file-text', 'children' => [
                ['label' => 'New Requisition', 'href' => '/requisitions/new', 'icon' => 'plus'],
                ['label' => 'My Requisitions', 'href' => '/requisitions/my', 'icon' => 'file-text'],
                ['label' => 'Drafts', 'href' => '/requisitions/drafts', 'icon' => 'file-text'],
            ]],
            ['label' => 'Approvals', 'href' => '/approvals', 'icon' => 'check-square', 'roles' => ['ADMIN', 'APPROVER'], 'children' => [
                ['label' => 'Pending Approvals', 'href' => '/approvals/pending', 'icon' => 'check-square'],
                ['label' => 'Approved', 'href' => '/approvals/approved', 'icon' => 'check-square'],
                ['label' => 'Rejected', 'href' => '/approvals/rejected', 'icon' => 'check-square'],
            ]],
            ['label' => 'Inventory', 'href' => '/inventory', 'icon' => 'package', 'roles' => ['ADMIN', 'INVENTORY_MGR'], 'children' => [
                ['label' => 'Stock Overview', 'href' => '/inventory/overview', 'icon' => 'package'],
                ['label' => 'Stock Inward', 'href' => '/inventory/inward', 'icon' => 'arrow-down-to-line'],
                ['label' => 'Stock Outward', 'href' => '/inventory/outward', 'icon' => 'arrow-up-from-line'],
                ['label' => 'Adjust Stock', 'href' => '/inventory/adjust', 'icon' => 'package'],
                ['label' => 'All Transactions', 'href' => '/inventory/transactions', 'icon' => 'history'],
                ['label' => 'Low Stock Alerts', 'href' => '/inventory/low-stock', 'icon' => 'alert-triangle'],
            ]],
            ['label' => 'Reports', 'href' => '/reports', 'icon' => 'bar-chart-3', 'roles' => ['ADMIN', 'APPROVER', 'INVENTORY_MGR'], 'children' => [
                ['label' => 'Overview', 'href' => '/reports', 'icon' => 'bar-chart-3'],
                ['label' => 'Requisition Analytics', 'href' => '/reports/requisition-analytics', 'icon' => 'trending-up', 'roles' => ['ADMIN']],
            ]],
            ['label' => 'Issue Items', 'href' => '/issue/items', 'icon' => 'truck', 'roles' => ['ADMIN', 'INVENTORY_MGR']],
            ['label' => 'Issue Queue', 'href' => '/issue/queue', 'icon' => 'list-checks', 'roles' => ['ADMIN', 'INVENTORY_MGR']],
            ['label' => 'Issued History', 'href' => '/issue/history', 'icon' => 'history', 'roles' => ['ADMIN', 'INVENTORY_MGR']],
        ],
    ],
    [
        'title' => 'MASTERS',
        'items' => [
            ['label' => 'Categories', 'href' => '/masters/categories', 'icon' => 'folder-tree', 'roles' => ['ADMIN', 'INVENTORY_MGR']],
            ['label' => 'Items', 'href' => '/masters/items', 'icon' => 'box', 'roles' => ['ADMIN', 'INVENTORY_MGR']],
            ['label' => 'Departments', 'href' => '/masters/departments', 'icon' => 'building-2', 'roles' => ['ADMIN']],
            ['label' => 'Suppliers', 'href' => '/masters/suppliers', 'icon' => 'building-2', 'roles' => ['ADMIN', 'INVENTORY_MGR']],
            ['label' => 'Auto-Approval Rules', 'href' => '/masters/auto-approval', 'icon' => 'zap', 'roles' => ['ADMIN', 'INVENTORY_MGR']],
            ['label' => 'Users', 'href' => '/masters/users', 'icon' => 'users', 'roles' => ['ADMIN']],
            ['label' => 'Roles & Permissions', 'href' => '/masters/roles', 'icon' => 'shield-check', 'roles' => ['ADMIN']],
        ],
    ],
    [
        'title' => 'SYSTEM',
        'items' => [
            ['label' => 'Profile', 'href' => '/system/profile', 'icon' => 'user-circle'],
            ['label' => 'Settings', 'href' => '/system/settings', 'icon' => 'settings', 'roles' => ['ADMIN']],
            ['label' => 'Audit Logs', 'href' => '/system/audit-logs', 'icon' => 'scroll-text', 'roles' => ['ADMIN']],
            ['label' => 'Help & Support', 'href' => '/system/help', 'icon' => 'help-circle'],
        ],
    ],
];

$userRole = $user['role'] ?? 'USER';

$isItemVisible = function (array $item) use ($userRole): bool {
    if (!isset($item['roles'])) return true;
    return in_array($userRole, $item['roles'], true);
};

$isActive = function (string $href) use ($currentPath): bool {
    $currentPath = $currentPath ?? '';
    return $currentPath === $href || strpos($currentPath, $href . '/') === 0;
};

// A submenu contains the active page if any (visible) child matches the
// current path — that's the one section that should start expanded.
// Every other submenu starts collapsed, instead of every submenu
// defaulting to "expanded" on every single page load.
$containsActive = function (array $item) use ($isActive, $isItemVisible): bool {
    foreach ($item['children'] ?? [] as $child) {
        if (!$isItemVisible($child)) continue;
        if ($isActive($child['href'])) return true;
    }
    return false;
};

// Live pending-approvals badge (only meaningful for ADMIN/APPROVER)
$pendingCount = 0;
if (in_array($userRole, ['ADMIN', 'APPROVER'], true)) {
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM requisitions WHERE status = 'PENDING'")->fetchColumn();
}

// Company branding (Admin can set these under System -> Settings). Falls
// back to the default SRIMS mark when nothing's been configured yet.
$companyName = null; $companyLogo = null;
$brandRows = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('company_name','company_logo')")->fetchAll();
foreach ($brandRows as $r) {
    if ($r['setting_key'] === 'company_name') $companyName = $r['setting_value'];
    if ($r['setting_key'] === 'company_logo') $companyLogo = $r['setting_value'];
}

function renderNavItem(array $item, callable $isItemVisible, callable $isActive, callable $containsActive, int $pendingCount, bool $isChild = false): void
{
    if (!$isItemVisible($item)) return;

    $active = $isActive($item['href']);
    $children = $item['children'] ?? [];
    $visibleChildren = array_values(array_filter($children, $isItemVisible));
    $hasChildren = count($children) > 0;
    if ($hasChildren && count($visibleChildren) === 0) return;
    $expanded = $hasChildren && $containsActive($item);

    $baseCls = 'flex items-center gap-3 rounded-md px-4 py-3 text-sidebar-item transition-colors text-sidebar-text hover:bg-white/[0.04]' . ($isChild ? ' pl-9' : '');
    $activeCls = $active ? ' bg-sidebar-active text-sidebar-text-hi' : '';
    $activeLinkCls = $active ? ' bg-sidebar-active text-sidebar-text-hi border-l-[3px] border-white' : '';

    echo '<div>';
    if ($hasChildren) {
        $showCollapsedBadge = $item['href'] === '/approvals' && $pendingCount > 0;
        echo '<button type="button" class="sidebar-menu-toggle w-full ' . $baseCls . $activeCls . '" data-target="submenu-' . e(md5($item['href'])) . '">';
        echo '<span class="relative flex-shrink-0"><i data-lucide="' . e($item['icon']) . '" style="width:18px;height:18px"></i>';
        if ($showCollapsedBadge) {
            echo '<span class="sidebar-collapsed-badge absolute -right-1.5 -top-1.5 h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[9px] font-semibold text-white">' . $pendingCount . '</span>';
        }
        echo '</span>';
        echo '<span class="flex-1 text-left sidebar-label">' . e($item['label']) . '</span>';
        echo '<i data-lucide="' . ($expanded ? 'chevron-down' : 'chevron-right') . '" class="opacity-60 sidebar-chevron" style="width:14px;height:14px"></i>';
        echo '</button>';
    } else {
        echo '<a href="' . BASE_URL . $item['href'] . '.php" class="' . $baseCls . $activeLinkCls . '">';
        echo '<span class="flex-shrink-0"><i data-lucide="' . e($item['icon']) . '" style="width:18px;height:18px"></i></span>';
        echo '<span class="flex-1 sidebar-label">' . e($item['label']) . '</span>';
        if ($item['href'] === '/approvals/pending' && $pendingCount > 0) {
            echo '<span class="sidebar-label flex h-5 min-w-[20px] items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-semibold text-white">' . $pendingCount . '</span>';
        }
        echo '</a>';
    }

    if ($hasChildren) {
        echo '<div id="submenu-' . e(md5($item['href'])) . '" class="sidebar-submenu mt-0.5 space-y-0.5' . ($expanded ? '' : ' hidden') . '">';
        foreach ($visibleChildren as $child) {
            renderNavItem($child, $isItemVisible, $isActive, $containsActive, $pendingCount, true);
        }
        echo '</div>';
    }
    echo '</div>';
}
?>
<aside class="app-sidebar flex h-screen flex-col bg-sidebar-bg">
    <!-- Brand Block -->
    <div class="flex items-center gap-3 border-b border-sidebar-border px-4 py-4">
        <?php if ($companyLogo): ?>
        <img src="<?= e($companyLogo) ?>" alt="<?= e($companyName ?: 'Company logo') ?>" class="h-9 w-9 flex-shrink-0 rounded-lg object-contain bg-white">
        <?php else: ?>
        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-sidebar-active">
            <i data-lucide="shopping-cart" class="text-white" style="width:20px;height:20px"></i>
        </div>
        <?php endif; ?>
        <div class="min-w-0 sidebar-brand-text">
            <div class="truncate text-[16px] font-bold text-white"><?= e($companyName ?: 'SRIMS') ?></div>
            <div class="text-[10px] leading-tight text-slate-300">Stationery Requisition &amp;<br>Inventory Management System</div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 overflow-y-auto px-2 py-2">
        <?php foreach ($navigation as $section):
            $visibleItems = array_values(array_filter($section['items'], $isItemVisible));
            if (count($visibleItems) === 0) continue;
        ?>
        <div class="pt-4">
            <div class="sidebar-section-label px-4 pb-2 text-sidebar-section uppercase tracking-sidebar-section text-sidebar-section"><?= e($section['title']) ?></div>
            <div class="space-y-0.5">
                <?php foreach ($visibleItems as $item) renderNavItem($item, $isItemVisible, $isActive, $containsActive, $pendingCount); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </nav>

    <!-- Collapse Toggle -->
    <button type="button" id="sidebarCollapseToggle" class="flex items-center justify-center border-t border-sidebar-border py-3 text-sidebar-text hover:text-white transition-colors">
        <i data-lucide="chevrons-left" id="sidebarCollapseIcon" style="width:18px;height:18px"></i>
    </button>

    <!-- Footer -->
    <div class="border-t border-sidebar-border px-4 py-3 text-center">
        <div class="sidebar-footer-full">
            <div class="text-[12px] text-slate-400">© 2025 SRIMS</div>
            <div class="text-[11px] text-slate-500">Version 1.0.0</div>
        </div>
        <div class="sidebar-footer-collapsed text-[10px] text-slate-500">v1.0</div>
    </div>
</aside>
<div id="mobileSidebarOverlay" class="fixed inset-0 z-30 bg-black/40 lg:hidden" style="display:none" onclick="document.body.classList.remove('mobile-menu-open'); this.style.display='none';"></div>
