<?php
/**
 * SRIMS - Shared helper functions
 * Ported 1:1 from src/lib/utils.ts, src/components/shared/StatusPill.tsx,
 * src/components/shared/StatCard.tsx and src/components/icons/items/ItemIcon.tsx
 */

// ─── Formatting (matches src/lib/utils.ts) ─────────────────────────────────

function formatCurrency(float $amount): string
{
    // en-IN currency formatting: ₹1,23,456.00
    $formatted = number_format($amount, 2);
    // Convert to Indian digit grouping (last 3 digits, then groups of 2)
    if (preg_match('/^(-?)(\d+)(\.\d{2})$/', $formatted === '' ? '0.00' : str_replace(',', '', $formatted), $m)) {
        $sign = $m[1];
        $intPart = $m[2];
        $decPart = $m[3];
        if (strlen($intPart) > 3) {
            $last3 = substr($intPart, -3);
            $rest  = substr($intPart, 0, -3);
            $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $intPart = $rest . ',' . $last3;
        }
        $formatted = $sign . $intPart . $decPart;
    }
    return '₹' . $formatted;
}

function formatDate($date): string
{
    if (empty($date)) return '';
    $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
    if (!$ts) return '';
    return date('d M Y', $ts);
}

function formatDateTime($date): string
{
    if (empty($date)) return '';
    $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
    if (!$ts) return '';
    return date('d M Y, h:i A', $ts);
}

function timeAgo($dateStr): string
{
    if (empty($dateStr)) return '';
    $ts = strtotime((string)$dateStr);
    if (!$ts) return '';
    $diff = time() - $ts;
    $mins = (int) floor($diff / 60);
    if ($mins < 1) return 'just now';
    if ($mins < 60) return $mins . 'm ago';
    $hours = (int) floor($mins / 60);
    if ($hours < 24) return $hours . 'h ago';
    return (int) floor($hours / 24) . 'd ago';
}

// ─── Role labels (matches Topbar.tsx roleLabels) ───────────────────────────

function roleLabel(string $role): string
{
    $labels = [
        'ADMIN'         => 'Administrator',
        'USER'          => 'Employee',
        'APPROVER'      => 'Manager',
        'INVENTORY_MGR' => 'Inventory Manager',
    ];
    return $labels[$role] ?? $role;
}

function userInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') $initials .= mb_substr($p, 0, 1);
    }
    return mb_strtoupper(mb_substr($initials, 0, 2));
}

// ─── Stock status (matches getStockStatus in mock-data.ts) ─────────────────

function getStockStatus(array $item): string
{
    $stock = (int) $item['current_stock'];
    $min   = (int) $item['min_stock_level'];
    if ($stock === 0) return 'OUT_OF_STOCK';
    if ($stock <= $min * 0.2) return 'CRITICAL';
    if ($stock < $min) return 'LOW';
    return 'IN_STOCK';
}

// ─── ID generators (matches generateRequisitionId / generateIssuanceId) ────

function generateRequisitionId(): string
{
    $datePart = date('ymd');
    $rand = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    return "REQ-{$datePart}-{$rand}";
}

function generateIssuanceId(): string
{
    $datePart = date('ymd');
    $rand = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    return "ISS-{$datePart}-{$rand}";
}

function generateId(string $prefix = 'id'): string
{
    return $prefix . '-' . bin2hex(random_bytes(8));
}

// ─── Status pill (matches StatusPill.tsx) ──────────────────────────────────

function statusPill(string $variant, ?string $label = null): string
{
    $styles = [
        'pending'    => 'bg-status-pending-bg text-status-pending-text',
        'approved'   => 'bg-status-approved-bg text-status-approved-text',
        'issued'     => 'bg-status-issued-bg text-status-issued-text',
        'rejected'   => 'bg-status-rejected-bg text-status-rejected-text',
        'new'        => 'bg-status-new-bg text-status-new-text',
        'low'        => 'bg-status-low-bg text-status-low-text',
        'critical'   => 'bg-status-critical-bg text-status-critical-text',
        'inStock'    => 'bg-status-in-stock-bg text-status-in-stock-text',
        'outOfStock' => 'bg-status-out-of-stock-bg text-status-out-of-stock-text',
        'partial'    => 'bg-status-partial-bg text-status-partial-text',
        'draft'      => 'bg-status-draft-bg text-status-draft-text',
        'inward'     => 'bg-status-approved-bg text-status-approved-text',
        'outward'    => 'bg-status-rejected-bg text-status-rejected-text',
    ];
    $labels = [
        'pending'    => 'Pending Approval',
        'approved'   => 'Approved',
        'issued'     => 'Issued',
        'rejected'   => 'Rejected',
        'new'        => 'New',
        'low'        => 'Low Stock',
        'critical'   => 'Critical',
        'inStock'    => 'In Stock',
        'outOfStock' => 'Out of Stock',
        'partial'    => 'Partial',
        'draft'      => 'Draft',
        'inward'     => 'Inward',
        'outward'    => 'Outward',
    ];
    $cls  = $styles[$variant] ?? 'bg-gray-100 text-gray-700';
    $text = $label ?? ($labels[$variant] ?? $variant);
    return '<span class="inline-flex items-center rounded-pill px-2.5 py-1 text-status-pill uppercase tracking-wide ' . $cls . '">'
        . htmlspecialchars($text, ENT_QUOTES) . '</span>';
}

function requisitionStatusVariant(string $status): string
{
    $map = [
        'DRAFT' => 'draft', 'PENDING' => 'pending', 'APPROVED' => 'approved',
        'REJECTED' => 'rejected', 'ISSUED' => 'issued', 'PARTIAL' => 'partial',
    ];
    return $map[$status] ?? 'draft';
}

function stockStatusVariant(string $status): string
{
    $map = [
        'IN_STOCK' => 'inStock', 'LOW' => 'low', 'CRITICAL' => 'critical', 'OUT_OF_STOCK' => 'outOfStock',
    ];
    return $map[$status] ?? 'inStock';
}

function txnTypeVariant(string $type): string
{
    $map = ['INWARD' => 'inward', 'OUTWARD' => 'outward', 'ADJUSTMENT' => 'partial'];
    return $map[$type] ?? 'partial';
}

// ─── Item icons (matches src/components/icons/items/ItemIcon.tsx) ─────────

function itemIconSvg(?string $iconKey, ?string $itemId = null, int $size = 28): string
{
    if ($iconKey && strpos($iconKey, 'data:image') === 0) {
        return '<img src="' . htmlspecialchars($iconKey, ENT_QUOTES) . '" alt="" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:contain;border-radius:4px;" />';
    }

    $legacyMap = [
        'ITM-0001' => 'pen-blue', 'ITM-0002' => 'pen-red', 'ITM-0003' => 'marker',
        'ITM-0004' => 'pencil', 'ITM-0005' => 'highlighter', 'ITM-0006' => 'stapler-pins',
        'ITM-0007' => 'paper-a4', 'ITM-0008' => 'notebook', 'ITM-0009' => 'folder',
        'ITM-0010' => 'eraser', 'ITM-0011' => 'pen-black', 'ITM-0012' => 'marker-wb',
        'ITM-0013' => 'sticky-notes', 'ITM-0014' => 'clips', 'ITM-0015' => 'stapler',
        'ITM-0016' => 'scissors', 'ITM-0017' => 'tape', 'ITM-0018' => 'envelope',
        'ITM-0019' => 'correction', 'ITM-0020' => 'calculator', 'ITM-0021' => 'rubber-bands',
        'ITM-0022' => 'glue', 'ITM-0023' => 'arch-file', 'ITM-0024' => 'organizer', 'ITM-0025' => 'usb',
    ];
    $key = $iconKey ?: ($itemId ? ($legacyMap[$itemId] ?? null) : null);
    $s = $size;

    $icons = [
        'pen-blue' => '<rect x="10" y="2" width="4" height="16" rx="1" fill="#3B82F6"/><polygon points="10,18 14,18 12,23" fill="#1F2937"/><rect x="9.5" y="5" width="5" height="1.5" rx="0.5" fill="#60A5FA" opacity="0.5"/><circle cx="12" cy="3.5" r="0.5" fill="#93C5FD"/>',
        'pen-red' => '<rect x="10" y="2" width="4" height="16" rx="1" fill="#EF4444"/><polygon points="10,18 14,18 12,23" fill="#1F2937"/><rect x="9.5" y="5" width="5" height="1.5" rx="0.5" fill="#FCA5A5" opacity="0.5"/><circle cx="12" cy="3.5" r="0.5" fill="#FECACA"/>',
        'pen-black' => '<rect x="10" y="2" width="4" height="16" rx="1" fill="#1F2937"/><polygon points="10,18 14,18 12,23" fill="#374151"/><rect x="9.5" y="5" width="5" height="1.5" rx="0.5" fill="#6B7280" opacity="0.5"/><circle cx="12" cy="3.5" r="0.5" fill="#9CA3AF"/>',
        'marker' => '<rect x="9" y="2" width="6" height="14" rx="1.5" fill="#1F2937"/><rect x="9" y="8" width="6" height="2" fill="#FFFFFF" opacity="0.3"/><rect x="10.5" y="16" width="3" height="5" rx="0.5" fill="#374151"/><polygon points="10.5,21 13.5,21 12,23.5" fill="#1F2937"/>',
        'marker-wb' => '<rect x="9" y="2" width="6" height="14" rx="1.5" fill="#FFFFFF" stroke="#D1D5DB" stroke-width="0.6"/><rect x="9" y="8" width="6" height="2" fill="#2563EB"/><rect x="10.5" y="16" width="3" height="5" rx="0.5" fill="#2563EB"/><polygon points="10.5,21 13.5,21 12,23.5" fill="#1D4ED8"/>',
        'pencil' => '<rect x="10" y="2" width="4" height="15" rx="0.5" fill="#F59E0B"/><rect x="10" y="2" width="4" height="3" rx="0.5" fill="#78716C"/><polygon points="10,17 14,17 12,22" fill="#FDE68A"/><polygon points="11,20 13,20 12,22" fill="#1F2937"/>',
        'highlighter' => '<rect x="9" y="2" width="6" height="14" rx="2" fill="#FDE047"/><rect x="10.5" y="16" width="3" height="4" rx="0.5" fill="#FBBF24"/><rect x="10.5" y="20" width="3" height="2" rx="0.5" fill="#F59E0B"/><rect x="9" y="2" width="6" height="3" rx="2" fill="#FACC15"/>',
        'correction' => '<rect x="9" y="6" width="6" height="14" rx="1.5" fill="#F3F4F6" stroke="#D1D5DB" stroke-width="0.6"/><rect x="10.5" y="3" width="3" height="4" rx="0.5" fill="#9CA3AF"/><rect x="9" y="11" width="6" height="2" fill="#FFFFFF"/><circle cx="12" cy="15" r="1.6" fill="#E5E7EB"/>',
        'stapler-pins' => '<rect x="4" y="8" width="16" height="4" rx="1" fill="#9CA3AF"/><rect x="6" y="12" width="12" height="2" rx="0.5" fill="#6B7280"/><rect x="7" y="10" width="1" height="4" fill="#D1D5DB"/><rect x="10" y="10" width="1" height="4" fill="#D1D5DB"/><rect x="13" y="10" width="1" height="4" fill="#D1D5DB"/><rect x="16" y="10" width="1" height="4" fill="#D1D5DB"/>',
        'stapler' => '<rect x="3" y="14" width="18" height="4" rx="1" fill="#374151"/><path d="M4 8C4 6.9 4.9 6 6 6H18C19.1 6 20 6.9 20 8V14H4V8Z" fill="#4B5563"/><rect x="4" y="6" width="16" height="2" rx="1" fill="#6B7280"/>',
        'scissors' => '<circle cx="6" cy="6" r="2.5" fill="none" stroke="#6B7280" stroke-width="1.6"/><circle cx="6" cy="18" r="2.5" fill="none" stroke="#6B7280" stroke-width="1.6"/><line x1="8" y1="7.5" x2="20" y2="20" stroke="#9CA3AF" stroke-width="1.6"/><line x1="8" y1="16.5" x2="20" y2="4" stroke="#D1D5DB" stroke-width="1.6"/>',
        'tape' => '<circle cx="12" cy="12" r="10" fill="#FDE68A" opacity="0.6"/><circle cx="12" cy="12" r="10" fill="none" stroke="#D97706" stroke-width="1"/><circle cx="12" cy="12" r="4.5" fill="#F3F4F6" stroke="#9CA3AF" stroke-width="0.6"/>',
        'envelope' => '<rect x="2" y="6" width="20" height="14" rx="1.5" fill="#F3F4F6" stroke="#D1D5DB" stroke-width="0.6"/><path d="M2 7L12 14L22 7" fill="none" stroke="#9CA3AF" stroke-width="1"/>',
        'calculator' => '<rect x="5" y="2" width="14" height="20" rx="1.5" fill="#374151"/><rect x="7" y="4.5" width="10" height="4" rx="0.5" fill="#A7F3D0"/>' . calculatorGrid(),
        'rubber-bands' => '<ellipse cx="12" cy="9" rx="7" ry="4" fill="none" stroke="#FCA5A5" stroke-width="2"/><ellipse cx="12" cy="14" rx="7" ry="4" fill="none" stroke="#F87171" stroke-width="2"/>',
        'glue' => '<rect x="8" y="9" width="8" height="13" rx="1.5" fill="#A855F7"/><rect x="9" y="3" width="6" height="6" rx="1" fill="#D8B4FE"/><rect x="9.5" y="13" width="5" height="2" fill="#FFFFFF" opacity="0.3"/>',
        'arch-file' => '<rect x="4" y="2" width="16" height="20" rx="1" fill="#2563EB"/><rect x="4" y="2" width="5" height="20" fill="#1D4ED8"/><circle cx="14" cy="6" r="1.3" fill="#DBEAFE"/><circle cx="18" cy="6" r="1.3" fill="#DBEAFE"/>',
        'organizer' => '<rect x="3" y="9" width="18" height="12" rx="1" fill="#FB923C"/><rect x="5" y="3" width="4" height="9" rx="0.5" fill="#FDBA74"/><rect x="10" y="5" width="4" height="7" rx="0.5" fill="#FDBA74"/><rect x="15" y="4" width="4" height="8" rx="0.5" fill="#FDBA74"/>',
        'usb' => '<rect x="7" y="8" width="10" height="13" rx="1.5" fill="#475569"/><rect x="10" y="2" width="4" height="7" rx="0.8" fill="#94A3B8"/><rect x="9" y="12" width="6" height="3" rx="0.5" fill="#22D3EE"/>',
        'paper-a4' => '<rect x="5" y="2" width="14" height="20" rx="1" fill="#F3F4F6" stroke="#D1D5DB" stroke-width="0.5"/><line x1="8" y1="7" x2="16" y2="7" stroke="#D1D5DB" stroke-width="0.8"/><line x1="8" y1="10" x2="16" y2="10" stroke="#D1D5DB" stroke-width="0.8"/><line x1="8" y1="13" x2="14" y2="13" stroke="#D1D5DB" stroke-width="0.8"/><line x1="8" y1="16" x2="16" y2="16" stroke="#D1D5DB" stroke-width="0.8"/>',
        'notebook' => '<rect x="6" y="2" width="14" height="20" rx="1" fill="#FEF3C7" stroke="#F59E0B" stroke-width="0.5"/><circle cx="7" cy="5" r="1.2" fill="none" stroke="#9CA3AF" stroke-width="0.8"/><circle cx="7" cy="9" r="1.2" fill="none" stroke="#9CA3AF" stroke-width="0.8"/><circle cx="7" cy="13" r="1.2" fill="none" stroke="#9CA3AF" stroke-width="0.8"/><circle cx="7" cy="17" r="1.2" fill="none" stroke="#9CA3AF" stroke-width="0.8"/><line x1="10" y1="7" x2="17" y2="7" stroke="#E5E7EB" stroke-width="0.6"/><line x1="10" y1="10" x2="17" y2="10" stroke="#E5E7EB" stroke-width="0.6"/><line x1="10" y1="13" x2="17" y2="13" stroke="#E5E7EB" stroke-width="0.6"/>',
        'sticky-notes' => '<rect x="6" y="6" width="13" height="13" rx="1" fill="#FDE047" transform="rotate(-4 12.5 12.5)"/><rect x="5" y="5" width="13" height="13" rx="1" fill="#FACC15"/>',
        'folder' => '<path d="M3 7C3 6.44772 3.44772 6 4 6H9L11 8H20C20.5523 8 21 8.44772 21 9V19C21 19.5523 20.5523 20 20 20H4C3.44772 20 3 19.5523 3 19V7Z" fill="#F59E0B"/><path d="M3 9H21V19C21 19.5523 20.5523 20 20 20H4C3.44772 20 3 19.5523 3 19V9Z" fill="#FBBF24"/>',
        'clips' => '<path d="M8 12V6a4 4 0 118 0v12a3 3 0 11-6 0V9" fill="none" stroke="#9CA3AF" stroke-width="1.8" stroke-linecap="round"/>',
        'eraser' => '<rect x="4" y="10" width="16" height="8" rx="2" fill="#F9A8D4"/><rect x="4" y="10" width="6" height="8" rx="2" fill="#F472B6"/><rect x="4" y="14" width="16" height="1" fill="#FFFFFF" opacity="0.2"/>',
    ];

    $inner = ($key && isset($icons[$key])) ? $icons[$key]
        : '<rect x="4" y="4" width="16" height="16" rx="2" fill="#E5E7EB" stroke="#D1D5DB" stroke-width="0.5"/><circle cx="12" cy="12" r="3" fill="#9CA3AF"/>';

    return "<svg width=\"$s\" height=\"$s\" viewBox=\"0 0 24 24\" fill=\"none\">$inner</svg>";
}

function calculatorGrid(): string
{
    $out = '';
    for ($row = 0; $row < 4; $row++) {
        for ($col = 0; $col < 3; $col++) {
            $x = 7 + $col * 3.5;
            $y = 10.5 + $row * 2.7;
            $out .= "<rect x=\"$x\" y=\"$y\" width=\"2.5\" height=\"2\" rx=\"0.4\" fill=\"#9CA3AF\"/>";
        }
    }
    return $out;
}

// Preset icon options for the icon-picker UI in Masters → Items
function itemIconOptions(): array
{
    return [
        ['key' => 'pen-blue', 'label' => 'Blue Pen'],
        ['key' => 'pen-red', 'label' => 'Red Pen'],
        ['key' => 'pen-black', 'label' => 'Black Pen'],
        ['key' => 'marker', 'label' => 'Marker'],
        ['key' => 'marker-wb', 'label' => 'Whiteboard Marker'],
        ['key' => 'pencil', 'label' => 'Pencil'],
        ['key' => 'highlighter', 'label' => 'Highlighter'],
        ['key' => 'correction', 'label' => 'Correction Pen'],
        ['key' => 'stapler-pins', 'label' => 'Stapler Pins'],
        ['key' => 'stapler', 'label' => 'Stapler'],
        ['key' => 'scissors', 'label' => 'Scissors'],
        ['key' => 'tape', 'label' => 'Tape'],
        ['key' => 'envelope', 'label' => 'Envelope'],
        ['key' => 'calculator', 'label' => 'Calculator'],
        ['key' => 'rubber-bands', 'label' => 'Rubber Bands'],
        ['key' => 'glue', 'label' => 'Glue Stick'],
        ['key' => 'arch-file', 'label' => 'Lever Arch File'],
        ['key' => 'organizer', 'label' => 'Desk Organizer'],
        ['key' => 'usb', 'label' => 'USB Drive'],
        ['key' => 'paper-a4', 'label' => 'A4 Paper'],
        ['key' => 'notebook', 'label' => 'Notebook'],
        ['key' => 'sticky-notes', 'label' => 'Sticky Notes'],
        ['key' => 'folder', 'label' => 'File Folder'],
        ['key' => 'clips', 'label' => 'Paper Clips'],
        ['key' => 'eraser', 'label' => 'Eraser'],
    ];
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// ─── Flash messages (Post/Redirect/Get pattern) ────────────────────────────

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function renderFlash(): void
{
    $f = getFlash();
    if (!$f) return;
    $cls = $f['type'] === 'error'
        ? 'border-red-200 bg-red-50 text-red-800'
        : 'border-green-200 bg-green-50 text-green-800';
    echo '<div class="mb-4 flex items-center justify-between rounded-card border px-4 py-3 ' . $cls . '">'
        . '<span class="text-[13px] font-medium">' . e($f['message']) . '</span>'
        . '<button type="button" onclick="this.parentElement.remove()" class="text-current opacity-60 hover:opacity-100">'
        . '<i data-lucide="x" style="width:16px;height:16px"></i></button></div>';
}

// ─── Priority label (matches priority.charAt(0)+slice(1).toLowerCase() pattern) ─

function priorityLabel(string $p): string
{
    return ucfirst(strtolower($p));
}

// ─── Auto-approval (matches computeAutoApproval() in app-store.ts) ─────────
// Given a PENDING requisition about to be created/submitted, returns
// ['approved' => bool] — if true, the caller should mark it APPROVED
// (with approvedQty = requestedQty for every line) instead of PENDING.

function getAutoApprovalSettings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM auto_approval_settings WHERE id = 1')->fetch();
    if (!$row) return ['enabled' => false, 'priorities' => []];
    return [
        'enabled' => (bool) $row['enabled'],
        'priorities' => $row['priorities'] !== '' ? explode(',', $row['priorities']) : [],
    ];
}

function shouldAutoApprove(PDO $pdo, string $priority): bool
{
    $settings = getAutoApprovalSettings($pdo);
    return $settings['enabled'] && in_array($priority, $settings['priorities'], true);
}

const AUTO_APPROVAL_ACTOR_ID = 'system-auto-approval';
const AUTO_APPROVAL_ACTOR_NAME = 'Auto-Approval System';

function addNotification(PDO $pdo, string $userId, string $message, ?string $link = null): void
{
    $stmt = $pdo->prepare('INSERT INTO notifications (id, user_id, type, message, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([generateId('notif'), $userId, 'GENERAL', $message, $link]);
}

// Notify every user with one of the given roles (used for approver/inventory alerts)
function addNotificationToRoles(PDO $pdo, array $roles, string $message, ?string $link = null): void
{
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ($placeholders) AND is_active = 1");
    $stmt->execute($roles);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        addNotification($pdo, $userId, $message, $link);
    }
}
