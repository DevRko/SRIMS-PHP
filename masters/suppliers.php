<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/masters/suppliers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_supplier') {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $editingId = $_POST['editing_id'] ?? '';
        if ($name) {
            if ($editingId) {
                $pdo->prepare('UPDATE suppliers SET name=?, contact=?, address=? WHERE id=?')->execute([$name, $contact, $address, $editingId]);
                addAuditLog($pdo, $user['id'], 'UPDATE', 'Supplier', $editingId, 'Updated supplier');
                flash('success', "{$name} updated.");
            } else {
                $newId = generateId('sup');
                $pdo->prepare('INSERT INTO suppliers (id, name, contact, address) VALUES (?, ?, ?, ?)')->execute([$newId, $name, $contact, $address]);
                addAuditLog($pdo, $user['id'], 'CREATE', 'Supplier', $newId, "Created supplier {$name}");
                flash('success', "{$name} added.");
            }
        }
        header('Location: suppliers.php');
        exit;
    }
    if ($action === 'delete_supplier') {
        $id = $_POST['supplier_id'] ?? '';
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        addAuditLog($pdo, $user['id'], 'DELETE', 'Supplier', $id, 'Deleted supplier');
        flash('success', 'Supplier deleted.');
        header('Location: suppliers.php');
        exit;
    }
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();

$pageTitle = 'Suppliers';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Suppliers</h1><p class="text-page-subtitle text-text-secondary mt-1">Manage your stationery suppliers</p></div>
    <button type="button" onclick="document.getElementById('sup-modal-add').classList.remove('hidden'); document.getElementById('sup-modal-add').classList.add('flex');" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit"><i data-lucide="plus" style="width:16px;height:16px"></i> Add Supplier</button>
</div>

<?php renderFlash(); ?>

<div class="rounded-card border border-border bg-surface-card">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Name</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Contact</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Address</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Actions</th>
            </tr></thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3 text-[13px] font-medium text-text-primary"><?= e($s['name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($s['contact']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($s['address']) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" onclick="document.getElementById('sup-modal-<?= e($s['id']) ?>').classList.remove('hidden'); document.getElementById('sup-modal-<?= e($s['id']) ?>').classList.add('flex');" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="pencil" style="width:14px;height:14px"></i></button>
                            <button type="button" onclick="document.getElementById('sup-del-<?= e($s['id']) ?>').classList.remove('hidden'); document.getElementById('sup-del-<?= e($s['id']) ?>').classList.add('flex');" class="rounded p-1.5 text-text-secondary hover:bg-red-50 hover:text-red-600"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ([['id' => '', 'name' => '', 'contact' => '', 'address' => ''], ...$suppliers] as $i => $s): $isAdd = $i === 0; $modalId = $isAdd ? 'sup-modal-add' : 'sup-modal-' . $s['id']; ?>
<div id="<?= $modalId ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="w-full max-w-md rounded-card bg-surface-card p-6 shadow-lg">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-[16px] font-semibold text-text-primary"><?= $isAdd ? 'Add Supplier' : 'Edit Supplier' ?></h3>
            <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button>
        </div>
        <form method="POST">
        <input type="hidden" name="action" value="save_supplier"><input type="hidden" name="editing_id" value="<?= e($s['id']) ?>">
        <div class="space-y-4">
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Supplier Name *</label><input type="text" name="name" value="<?= e($s['name']) ?>" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Contact</label><input type="text" name="contact" value="<?= e($s['contact']) ?>" placeholder="+91 ..." class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none"></div>
            <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Address</label><textarea name="address" rows="2" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none resize-none"><?= e($s['address']) ?></textarea></div>
        </div>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
            <button type="submit" class="rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"><?= $isAdd ? 'Add Supplier' : 'Save Changes' ?></button>
        </div>
        </form>
    </div>
</div>
<?php if (!$isAdd): ?>
<div id="sup-del-<?= e($s['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
    <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
        <h3 class="mb-2 text-[15px] font-semibold text-text-primary">Delete supplier?</h3>
        <p class="mb-4 text-[13px] text-text-secondary">This cannot be undone.</p>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('sup-del-<?= e($s['id']) ?>').classList.add('hidden'); document.getElementById('sup-del-<?= e($s['id']) ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
            <form method="POST"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="supplier_id" value="<?= e($s['id']) ?>">
                <button type="submit" class="rounded-button bg-red-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-red-700">Delete</button>
            </form>
        </div>
    </div>
</div>
<?php endif; endforeach; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
