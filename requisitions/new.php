<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cart-panel.php';
requireLogin();
$user = currentUser();
$currentPath = '/requisitions/new';

// ─── Fresh navigation (no step/edit param) clears any leftover cart, ──────
// matching the useEffect in the original: clearCart() on a plain mount,
// loadRequisitionToCart() when ?edit=ID is present.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['step']) && !isset($_GET['edit'])) {
    unset($_SESSION['req_cart'], $_SESSION['req_wizard']);
}

$editId = $_GET['edit'] ?? ($_SESSION['req_edit_id'] ?? null);

// Load draft into cart/wizard session state (only once, when ?edit= first appears)
if (isset($_GET['edit']) && empty($_SESSION['req_cart']) && empty($_SESSION['req_wizard'])) {
    $stmt = $pdo->prepare('SELECT * FROM requisitions WHERE id = ? AND user_id = ? AND status = \'DRAFT\'');
    $stmt->execute([$_GET['edit'], $user['id']]);
    $draft = $stmt->fetch();
    if ($draft) {
        $_SESSION['req_edit_id'] = $draft['id'];
        $itemsStmt = $pdo->prepare(
            'SELECT ri.*, i.name AS item_name, i.unit, i.current_stock, i.icon_key, c.name AS category_name
             FROM requisition_items ri JOIN items i ON i.id = ri.item_id
             LEFT JOIN categories c ON c.id = i.category_id WHERE ri.requisition_id = ?'
        );
        $itemsStmt->execute([$draft['id']]);
        $cart = [];
        foreach ($itemsStmt->fetchAll() as $it) {
            $cart[$it['item_id']] = [
                'itemName' => $it['item_name'], 'categoryName' => $it['category_name'],
                'unit' => $it['unit'], 'unitPrice' => (float) $it['unit_price'],
                'availableStock' => (int) $it['current_stock'], 'quantity' => (int) $it['requested_qty'],
                'iconKey' => $it['icon_key'],
            ];
        }
        $_SESSION['req_cart'] = $cart;
        $_SESSION['req_wizard'] = [
            'department' => $draft['department_id'], 'requiredDate' => $draft['required_date'] ?? '',
            'purpose' => $draft['purpose'] ?? '', 'remarks' => $draft['remarks'] ?? '',
            'priority' => $draft['priority'],
        ];
    }
}

$cart = $_SESSION['req_cart'] ?? [];
$wizard = $_SESSION['req_wizard'] ?? [
    'department' => $user['departmentId'], 'requiredDate' => date('Y-m-d'), 'purpose' => '', 'remarks' => '', 'priority' => 'NORMAL',
];

// Any POST coming from the Step 2 form (Department/Required Date/Purpose/etc)
// updates the wizard state immediately, regardless of which button was
// clicked — this is what lets "Save as Draft" on Step 2 work correctly
// instead of only the "Next" button being able to see these fields.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('purpose', $_POST)) {
    $wizard = [
        'department' => $_POST['department'] ?? $wizard['department'],
        'requiredDate' => $_POST['required_date'] ?? '',
        'purpose' => $_POST['purpose'] ?? '',
        'remarks' => $_POST['remarks'] ?? '',
        'priority' => in_array($_POST['priority'] ?? '', ['LOW', 'NORMAL', 'URGENT'], true) ? $_POST['priority'] : 'NORMAL',
    ];
    $_SESSION['req_wizard'] = $wizard;
}

$cartTotal = 0;
foreach ($cart as $c) $cartTotal += $c['unitPrice'] * $c['quantity'];

$step = (int) ($_GET['step'] ?? 1);

// ─── POST actions ───────────────────────────────────────────────────────────
// (add_to_cart / remove_from_cart / update_qty now happen via AJAX —
// see cart-api.php — so the cart updates live without a page reload.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $qs = '?' . ($editId ? 'edit=' . urlencode($editId) . '&' : '') . 'step=';

    if ($action === 'goto_step2') {
        header('Location: new.php' . $qs . '2' . ($editId ? '&edit=' . urlencode($editId) : ''));
        exit;
    }

    if ($action === 'save_step2') {
        if ($wizard['requiredDate'] === '' || $wizard['purpose'] === '') {
            flash('error', 'Required Date and Purpose are required.');
            header('Location: new.php?step=2' . ($editId ? '&edit=' . urlencode($editId) : ''));
            exit;
        }
        header('Location: new.php?step=3' . ($editId ? '&edit=' . urlencode($editId) : ''));
        exit;
    }

    if ($action === 'save_draft' || $action === 'submit') {
        if (empty($cart)) {
            flash('error', 'Your cart is empty.');
            header('Location: new.php');
            exit;
        }
        if ($wizard['requiredDate'] === '' || $wizard['purpose'] === '') {
            flash('error', 'Please fill in Required Date and Purpose before saving — even a draft needs these.');
            header('Location: new.php?step=2' . ($editId ? '&edit=' . urlencode($editId) : ''));
            exit;
        }
        $asDraft = $action === 'save_draft';
        $deptStmt = $pdo->prepare('SELECT id, name FROM departments WHERE id = ? OR name = ? LIMIT 1');
        $deptStmt->execute([$wizard['department'], $wizard['department']]);
        $dept = $deptStmt->fetch() ?: ['id' => $user['departmentId'], 'name' => $user['departmentName']];

        $status = $asDraft ? 'DRAFT' : 'PENDING';
        $autoApproved = !$asDraft && shouldAutoApprove($pdo, $wizard['priority']);
        if ($autoApproved) $status = 'APPROVED';

        // Required Date is now validated above, so this will always be a
        // real date at this point — kept as a defensive fallback only.
        $requiredDateForDb = $wizard['requiredDate'] !== '' ? $wizard['requiredDate'] : null;

        $pdo->beginTransaction();
        try {
            if ($editId) {
                $reqId = $editId;
                $pdo->prepare(
                    'UPDATE requisitions SET department_id=?, status=?, purpose=?, remarks=?, required_date=?, priority=?, total_amount=?, updated_at=NOW()' .
                    ($autoApproved ? ', approved_by_id=?, approved_at=NOW()' : '') . ' WHERE id = ?'
                )->execute($autoApproved
                    ? [$dept['id'], $status, $wizard['purpose'], $wizard['remarks'], $requiredDateForDb, $wizard['priority'], $cartTotal, AUTO_APPROVAL_ACTOR_ID, $reqId]
                    : [$dept['id'], $status, $wizard['purpose'], $wizard['remarks'], $requiredDateForDb, $wizard['priority'], $cartTotal, $reqId]);
                $pdo->prepare('DELETE FROM requisition_items WHERE requisition_id = ?')->execute([$reqId]);
            } else {
                $reqId = generateRequisitionId();
                $pdo->prepare(
                    'INSERT INTO requisitions (id, user_id, department_id, status, purpose, remarks, required_date, priority, total_amount, created_at, approved_by_id, approved_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ' . ($autoApproved ? 'NOW()' : 'NULL') . ')'
                )->execute([$reqId, $user['id'], $dept['id'], $status, $wizard['purpose'], $wizard['remarks'], $requiredDateForDb, $wizard['priority'], $cartTotal, $autoApproved ? AUTO_APPROVAL_ACTOR_ID : null]);
            }

            $ins = $pdo->prepare('INSERT INTO requisition_items (id, requisition_id, item_id, requested_qty, approved_qty, issued_qty, unit_price) VALUES (?, ?, ?, ?, ?, 0, ?)');
            foreach ($cart as $itemId => $c) {
                $approvedQty = $autoApproved ? $c['quantity'] : 0;
                $ins->execute([generateId('ri'), $reqId, $itemId, $c['quantity'], $approvedQty, $c['unitPrice']]);
            }
            $pdo->commit();
        } catch (Exception $ex) {
            $pdo->rollBack();
            flash('error', 'Could not save requisition: ' . $ex->getMessage());
            header('Location: new.php?step=3' . ($editId ? '&edit=' . urlencode($editId) : ''));
            exit;
        }

        if ($asDraft) {
            addAuditLog($pdo, $user['id'], 'CREATE', 'Requisition', $reqId, "Saved as draft by {$user['name']}");
        } else {
            addAuditLog($pdo, $user['id'], 'SUBMIT', 'Requisition', $reqId, "Submitted by {$user['name']} (priority: {$wizard['priority']})");
        }
        if ($autoApproved) {
            addAuditLog($pdo, $user['id'], 'AUTO_APPROVE', 'Requisition', $reqId, "Auto-approved (priority: {$wizard['priority']}) per Auto-Approval Rules");
        } elseif ($status === 'PENDING') {
            addNotificationToRoles($pdo, ['ADMIN', 'APPROVER'], "New requisition {$reqId} submitted by {$user['name']} — awaiting approval", '/approvals/pending');
        }

        unset($_SESSION['req_cart'], $_SESSION['req_wizard'], $_SESSION['req_edit_id']);
        flash('success', $asDraft ? "Saved as draft ({$reqId})." : "Requisition {$reqId} submitted" . ($autoApproved ? ' and auto-approved.' : '.'));
        header('Location: ' . ($asDraft ? 'drafts.php' : 'my.php'));
        exit;
    }
}

// ─── Catalog data for Step 1 ────────────────────────────────────────────────
$searchQuery = trim($_GET['q'] ?? '');
$selectedCategory = $_GET['category'] ?? '';
$itemPage = max(1, (int) ($_GET['page'] ?? 1));
$itemsPerPage = 5;

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

$sql = 'SELECT i.*, c.name AS category_name FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.is_active = 1';
$params = [];
if ($selectedCategory) { $sql .= ' AND i.category_id = ?'; $params[] = $selectedCategory; }
if ($searchQuery) {
    $sql .= ' AND (i.name LIKE ? OR i.id LIKE ? OR c.name LIKE ?)';
    $like = '%' . $searchQuery . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY i.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$filteredItems = $stmt->fetchAll();

$totalFilteredPages = max(1, (int) ceil(count($filteredItems) / $itemsPerPage));
$itemPage = min($itemPage, $totalFilteredPages);
$paginatedItems = array_slice($filteredItems, ($itemPage - 1) * $itemsPerPage, $itemsPerPage);

$categoryIconMap = [
    'PenTool' => 'pen-tool', 'FileText' => 'file-text', 'Briefcase' => 'briefcase',
    'Folder' => 'folder', 'Calculator' => 'calculator', 'MoreHorizontal' => 'more-horizontal',
];

$purposeOptions = ['Marketing Campaign', 'Office Use', 'Event Preparation', 'Training Session', 'Workshop', 'Recruitment Drive', 'Quarterly Audit', 'IT Supplies', 'Year End', 'Other'];
$departments = $pdo->query('SELECT * FROM departments ORDER BY name')->fetchAll();

$pageTitle = $editId ? "Edit Draft — {$editId}" : 'New Requisition';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div>
        <h1 class="text-page-title text-text-primary"><?= e($pageTitle) ?></h1>
        <p class="text-page-subtitle text-text-secondary mt-1"><?= $editId ? 'Update items and details for this draft' : 'Create a new stationery requisition request' ?></p>
    </div>
    <div class="flex items-center gap-0">
        <?php foreach ([1 => 'Add Items', 2 => 'Requisition Details', 3 => 'Review & Submit'] as $n => $label): ?>
        <div class="flex items-center gap-2">
            <div class="flex h-7 w-7 items-center justify-center rounded-full text-[12px] font-semibold <?= $n < $step ? 'bg-brand-primary text-white' : ($n === $step ? 'bg-brand-primary text-white' : 'border-2 border-gray-300 text-text-muted') ?>">
                <?php if ($n < $step): ?><i data-lucide="check" style="width:14px;height:14px"></i><?php else: echo $n; endif; ?>
            </div>
            <span class="hidden text-[13px] font-medium sm:inline <?= $n === $step ? 'text-brand-primary' : ($n < $step ? 'text-text-primary' : 'text-text-muted') ?>"><?= $label ?></span>
        </div>
        <?php if ($n < 3): ?><div class="mx-3 h-[2px] w-8 sm:w-12 <?= $n < $step ? 'bg-brand-primary' : 'bg-gray-200' ?>"></div><?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php renderFlash(); ?>

<?php if ($step === 1): ?>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
    <div class="lg:col-span-3">
        <div class="rounded-card border border-border bg-surface-card p-card-padding">
            <h3 class="mb-4 text-[15px] font-semibold text-text-primary">1. Select Items</h3>

            <form method="GET" class="mb-4 flex gap-2">
                <input type="hidden" name="step" value="1">
                <?php if ($editId): ?><input type="hidden" name="edit" value="<?= e($editId) ?>"><?php endif; ?>
                <?php if ($selectedCategory): ?><input type="hidden" name="category" value="<?= e($selectedCategory) ?>"><?php endif; ?>
                <div class="relative flex-1">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:16px;height:16px"></i>
                    <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search items by name or ID..."
                        class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
                </div>
            </form>

            <div class="mb-4 flex gap-2 overflow-x-auto pb-2">
                <a href="?step=1<?= $editId ? '&edit=' . e($editId) : '' ?><?= $searchQuery ? '&q=' . e($searchQuery) : '' ?>"
                    class="flex flex-shrink-0 flex-col items-center gap-2 rounded-lg border-2 px-4 py-3 transition-all min-w-[100px] <?= !$selectedCategory ? 'border-brand-primary bg-tint-blue-bg' : 'border-transparent bg-gray-50 hover:bg-gray-100' ?>">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-200">
                        <i data-lucide="layout-grid" style="width:18px;height:18px;color:#475569"></i>
                    </div>
                    <span class="text-[11px] font-medium text-text-primary leading-tight text-center">All</span>
                </a>
                <?php foreach ($categories as $cat): $active = $selectedCategory === $cat['id']; ?>
                <a href="?step=1<?= $editId ? '&edit=' . e($editId) : '' ?><?= $searchQuery ? '&q=' . e($searchQuery) : '' ?>&category=<?= e($active ? '' : $cat['id']) ?>"
                    class="flex flex-shrink-0 flex-col items-center gap-2 rounded-lg border-2 px-4 py-3 transition-all min-w-[100px] <?= $active ? 'border-brand-primary bg-tint-blue-bg' : 'border-transparent bg-gray-50 hover:bg-gray-100' ?>">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg" style="background-color:<?= e($cat['bg_color']) ?>">
                        <i data-lucide="<?= e($categoryIconMap[$cat['icon']] ?? 'box') ?>" style="width:18px;height:18px;color:<?= e($cat['color']) ?>"></i>
                    </div>
                    <span class="text-[11px] font-medium text-text-primary leading-tight text-center"><?= e($cat['name']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead><tr class="border-b border-border">
                        <th class="pb-2 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                        <th class="pb-2 text-left text-table-header uppercase tracking-table-header text-text-secondary">Category</th>
                        <th class="pb-2 text-left text-table-header uppercase tracking-table-header text-text-secondary">Unit</th>
                        <th class="pb-2 text-right text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($paginatedItems as $item): $inCart = isset($cart[$item['id']]); ?>
                        <tr class="border-b border-border last:border-0" data-item-id="<?= e($item['id']) ?>">
                            <td class="py-3"><div class="flex items-center gap-2">
                                <?= itemIconSvg($item['icon_key'], $item['id'], 28) ?>
                                <div><div class="text-[13px] font-medium text-text-primary"><?= e($item['name']) ?></div>
                                <div class="text-[11px] text-text-muted"><?= e($item['id']) ?></div></div>
                            </div></td>
                            <td class="py-3 text-[13px] text-text-secondary"><?= e($item['category_name']) ?></td>
                            <td class="py-3 text-[13px] text-text-secondary"><?= e($item['unit']) ?></td>
                            <td class="py-3 text-right catalog-action-cell" data-item-id="<?= e($item['id']) ?>">
                                <?php if ($inCart): ?>
                                <span class="text-[12px] font-medium text-green-600">✓ Added</span>
                                <?php else: ?>
                                <button type="button" class="cart-add-btn inline-flex items-center gap-1 rounded-button border border-brand-primary px-3 py-1.5 text-[12px] font-medium text-brand-primary hover:bg-brand-primary hover:text-white transition-colors" data-item-id="<?= e($item['id']) ?>">
                                    <i data-lucide="plus" style="width:12px;height:12px"></i> Add
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($paginatedItems)): ?>
                        <tr><td colspan="4" class="py-8 text-center text-[13px] text-text-muted">No items match your filters</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex items-center justify-between">
                <span class="text-[12px] text-text-secondary">
                    Showing <?= count($filteredItems) ? (($itemPage - 1) * $itemsPerPage + 1) : 0 ?> to <?= min($itemPage * $itemsPerPage, count($filteredItems)) ?> of <?= count($filteredItems) ?> items
                </span>
                <div class="flex items-center gap-1">
                    <?php for ($p = 1; $p <= $totalFilteredPages; $p++): ?>
                    <a href="?step=1<?= $editId ? '&edit=' . e($editId) : '' ?><?= $searchQuery ? '&q=' . e($searchQuery) : '' ?><?= $selectedCategory ? '&category=' . e($selectedCategory) : '' ?>&page=<?= $p ?>"
                        class="min-w-[28px] rounded px-1.5 py-0.5 text-center text-[12px] font-medium <?= $p === $itemPage ? 'bg-brand-primary text-white' : 'text-text-secondary hover:bg-gray-100' ?>"><?= $p ?></a>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <form method="POST">
                    <input type="hidden" name="action" value="save_draft">
                    <button type="submit" class="rounded-button bg-gray-600 px-4 py-2 text-[13px] font-medium text-white hover:bg-gray-700 disabled:opacity-40">
                        <i data-lucide="save" style="width:14px;height:14px" class="mr-1.5 inline"></i> Save as Draft
                    </button>
                </form>
                <span class="text-[12px] text-text-muted">Save your progress and complete later</span>
            </div>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="rounded-card border border-border bg-surface-card p-card-padding" id="cartPanel">
            <?php renderCartPanelBody($cart, $cartTotal); ?>
        </div>
    </div>
</div>

<script>
// ─── Step 1 cart: add / remove / change quantity without a page reload ───
// Everything here talks to cart-api.php, which mutates the session cart
// and hands back freshly-rendered cart panel HTML — that HTML replaces
// #cartPanel's contents, so totals and line amounts are always exactly
// what the server computed (no separate client-side math to keep in sync).

function cartApiCall(action, itemId, qty) {
    var body = 'action=' + encodeURIComponent(action) + '&item_id=' + encodeURIComponent(itemId);
    if (qty !== undefined) body += '&qty=' + encodeURIComponent(qty);
    return fetch('cart-api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    }).then(function (r) { return r.json(); }).catch(function () {
        return { ok: false, error: 'network' };
    });
}

function applyCartResponse(data) {
    if (!data || !data.ok) { if (data && data.error === 'network') alert('Network error — please try again.'); return; }

    var panel = document.getElementById('cartPanel');
    if (panel) panel.innerHTML = data.cartHtml;

    var cell = document.querySelector('.catalog-action-cell[data-item-id="' + data.itemId + '"]');
    if (cell) {
        cell.innerHTML = data.inCart
            ? '<span class="text-[12px] font-medium text-green-600">\u2713 Added</span>'
            : '<button type="button" class="cart-add-btn inline-flex items-center gap-1 rounded-button border border-brand-primary px-3 py-1.5 text-[12px] font-medium text-brand-primary hover:bg-brand-primary hover:text-white transition-colors" data-item-id="' + data.itemId + '"><i data-lucide="plus" style="width:12px;height:12px"></i> Add</button>';
    }
    if (window.lucide) lucide.createIcons();
}

function cartAdd(itemId) { cartApiCall('add', itemId).then(applyCartResponse); }
function cartRemove(itemId) { cartApiCall('remove', itemId).then(applyCartResponse); }
function cartUpdateQty(itemId, qty) {
    qty = parseInt(qty, 10);
    if (!qty || qty < 1) qty = 1;
    cartApiCall('update_qty', itemId, qty).then(applyCartResponse);
}
function cartStep(itemId, delta) {
    var input = document.querySelector('.cart-qty-input[data-item-id="' + itemId + '"]');
    if (!input) return;
    var max = parseInt(input.getAttribute('max'), 10) || 9999;
    var val = (parseInt(input.value, 10) || 1) + delta;
    val = Math.max(1, Math.min(max, val));
    input.value = val;
    cartUpdateQty(itemId, val);
}

// Event delegation — #cartPanel's contents get replaced wholesale after
// every change, so listeners are bound on stable ancestors instead of
// the (disposable) elements inside it.
document.addEventListener('click', function (e) {
    var addBtn = e.target.closest('.cart-add-btn');
    if (addBtn) { cartAdd(addBtn.dataset.itemId); return; }
    var removeBtn = e.target.closest('.cart-remove-btn');
    if (removeBtn) { cartRemove(removeBtn.dataset.itemId); return; }
    var minusBtn = e.target.closest('.cart-qty-minus');
    if (minusBtn) { cartStep(minusBtn.dataset.itemId, -1); return; }
    var plusBtn = e.target.closest('.cart-qty-plus');
    if (plusBtn) { cartStep(plusBtn.dataset.itemId, 1); return; }
});

// Typing a quantity directly: debounce so we're not firing a request per
// keystroke, but still commit immediately on blur/Enter.
var cartQtyDebounce = null;
document.addEventListener('input', function (e) {
    if (!e.target.matches('.cart-qty-input')) return;
    clearTimeout(cartQtyDebounce);
    var itemId = e.target.dataset.itemId;
    var val = e.target.value;
    cartQtyDebounce = setTimeout(function () { cartUpdateQty(itemId, val); }, 600);
});
document.addEventListener('change', function (e) {
    if (!e.target.matches('.cart-qty-input')) return;
    clearTimeout(cartQtyDebounce);
    cartUpdateQty(e.target.dataset.itemId, e.target.value);
});
</script>

<?php elseif ($step === 2): ?>
<div class="mx-auto max-w-2xl">
    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <h3 class="mb-6 text-[15px] font-semibold text-text-primary">2. Requisition Details</h3>
        <form method="POST">
        <div class="space-y-5">
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Department</label>
                <select name="department" class="w-full rounded-button border border-border px-3 py-2 text-[14px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= e($d['id']) ?>" <?= $wizard['department'] === $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Required Date *</label>
                <input type="date" name="required_date" value="<?= e($wizard['requiredDate']) ?>" min="<?= date('Y-m-d') ?>" required
                    class="w-full rounded-button border border-border px-3 py-2 text-[14px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Purpose *</label>
                <select name="purpose" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] text-text-primary focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
                    <option value="">Select purpose...</option>
                    <?php foreach ($purposeOptions as $p): ?>
                    <option value="<?= e($p) ?>" <?= $wizard['purpose'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Remarks</label>
                <textarea name="remarks" rows="3" placeholder="Additional comments or notes..."
                    class="w-full rounded-button border border-border px-3 py-2 text-[14px] text-text-primary placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary resize-none"><?= e($wizard['remarks']) ?></textarea>
            </div>
            <div>
                <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Priority</label>
                <div class="flex gap-3">
                    <?php foreach (['LOW', 'NORMAL', 'URGENT'] as $p): $sel = $wizard['priority'] === $p; ?>
                    <label class="priority-option rounded-button border px-4 py-2 text-[13px] font-medium transition-colors cursor-pointer <?= $sel ? ($p === 'URGENT' ? 'border-red-500 bg-red-50 text-red-600' : 'border-brand-primary bg-tint-blue-bg text-brand-primary') : 'border-border text-text-secondary hover:bg-gray-50' ?>">
                        <input type="radio" name="priority" value="<?= $p ?>" <?= $sel ? 'checked' : '' ?> class="hidden" onchange="updatePriorityVisual(this)">
                        <?= priorityLabel($p) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="mt-8 flex items-center justify-between">
            <a href="?step=1<?= $editId ? '&edit=' . e($editId) : '' ?>" class="flex items-center gap-1.5 rounded-button border border-border px-4 py-2 text-[13px] font-medium text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
            <div class="flex items-center gap-3">
                <button type="submit" name="action" value="save_draft" class="rounded-button bg-gray-600 px-4 py-2 text-[13px] font-medium text-white hover:bg-gray-700">Save as Draft</button>
                <button type="submit" name="action" value="save_step2" class="flex items-center gap-1.5 rounded-button bg-brand-primary px-5 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover transition-colors">Next: Review &amp; Submit <i data-lucide="arrow-right" style="width:14px;height:14px"></i></button>
            </div>
        </div>
        </form>
    </div>
</div>
<script>
function updatePriorityVisual(radio) {
    document.querySelectorAll('.priority-option').forEach(function (label) {
        var input = label.querySelector('input[name="priority"]');
        var isUrgent = input.value === 'URGENT';
        label.classList.remove('border-red-500', 'bg-red-50', 'text-red-600', 'border-brand-primary', 'bg-tint-blue-bg', 'text-brand-primary', 'border-border', 'text-text-secondary');
        if (input.checked) {
            if (isUrgent) label.classList.add('border-red-500', 'bg-red-50', 'text-red-600');
            else label.classList.add('border-brand-primary', 'bg-tint-blue-bg', 'text-brand-primary');
        } else {
            label.classList.add('border-border', 'text-text-secondary');
        }
    });
}
</script>

<?php elseif ($step === 3): ?>
<div class="mx-auto max-w-3xl">
    <div class="rounded-card border border-border bg-surface-card p-card-padding">
        <h3 class="mb-6 text-[15px] font-semibold text-text-primary">3. Review &amp; Submit</h3>
        <div class="mb-6 grid grid-cols-2 gap-4 rounded-lg bg-gray-50 p-4">
            <div><span class="text-[12px] text-text-muted">Department</span><p class="text-[14px] font-medium text-text-primary"><?php
                foreach ($departments as $d) { if ($d['id'] === $wizard['department']) { echo e($d['name']); break; } }
            ?></p></div>
            <div><span class="text-[12px] text-text-muted">Required Date</span><p class="text-[14px] font-medium text-text-primary"><?= e($wizard['requiredDate']) ?></p></div>
            <div><span class="text-[12px] text-text-muted">Purpose</span><p class="text-[14px] font-medium text-text-primary"><?= e($wizard['purpose']) ?></p></div>
            <div><span class="text-[12px] text-text-muted">Priority</span><p class="text-[14px] font-medium text-text-primary"><?= priorityLabel($wizard['priority']) ?></p></div>
            <?php if ($wizard['remarks']): ?>
            <div class="col-span-2"><span class="text-[12px] text-text-muted">Remarks</span><p class="text-[14px] text-text-primary"><?= e($wizard['remarks']) ?></p></div>
            <?php endif; ?>
        </div>

        <h4 class="mb-3 text-[14px] font-semibold text-text-primary">Items (<?= count($cart) ?>)</h4>
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="pb-2 text-left text-table-header uppercase tracking-table-header text-text-secondary">Item</th>
                <th class="pb-2 text-right text-table-header uppercase tracking-table-header text-text-secondary">Qty</th>
                <th class="pb-2 text-left text-table-header uppercase tracking-table-header text-text-secondary">Unit</th>
                <th class="pb-2 text-right text-table-header uppercase tracking-table-header text-text-secondary">Unit Price</th>
                <th class="pb-2 text-right text-table-header uppercase tracking-table-header text-text-secondary">Total</th>
            </tr></thead>
            <tbody>
                <?php foreach ($cart as $itemId => $c): ?>
                <tr class="border-b border-border last:border-0">
                    <td class="py-3"><div class="flex items-center gap-2"><?= itemIconSvg($c['iconKey'], $itemId, 24) ?><span class="text-[13px] font-medium text-text-primary"><?= e($c['itemName']) ?></span></div></td>
                    <td class="py-3 text-right text-[13px] text-text-primary"><?= (int) $c['quantity'] ?></td>
                    <td class="py-3 text-[13px] text-text-secondary"><?= e($c['unit']) ?></td>
                    <td class="py-3 text-right text-[13px] text-text-primary"><?= formatCurrency($c['unitPrice']) ?></td>
                    <td class="py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency($c['unitPrice'] * $c['quantity']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="border-t-2 border-border">
                <td colspan="4" class="py-3 text-right text-[14px] font-semibold text-text-primary">Total Amount</td>
                <td class="py-3 text-right text-[16px] font-bold text-brand-primary"><?= formatCurrency($cartTotal) ?></td>
            </tr></tfoot>
        </table>

        <div class="mt-8 flex items-center justify-between">
            <a href="?step=2<?= $editId ? '&edit=' . e($editId) : '' ?>" class="flex items-center gap-1.5 rounded-button border border-border px-4 py-2 text-[13px] font-medium text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</a>
            <div class="flex items-center gap-3">
                <form method="POST"><input type="hidden" name="action" value="save_draft">
                    <button type="submit" class="rounded-button bg-gray-600 px-4 py-2 text-[13px] font-medium text-white hover:bg-gray-700">Save as Draft</button>
                </form>
                <form method="POST"><input type="hidden" name="action" value="submit">
                    <button type="submit" class="rounded-button bg-brand-primary px-5 py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover transition-colors">Submit Requisition</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
