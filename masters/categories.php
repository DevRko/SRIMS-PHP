<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/masters/categories';

$iconOptions = ['PenTool' => 'pen-tool', 'FileText' => 'file-text', 'Briefcase' => 'briefcase', 'Folder' => 'folder', 'Calculator' => 'calculator', 'MoreHorizontal' => 'more-horizontal', 'Tag' => 'tag', 'Archive' => 'archive', 'Layers' => 'layers', 'Package2' => 'package-2'];
$colorPresets = [
    ['color' => '#2563EB', 'bg' => '#DBEAFE'], ['color' => '#D97706', 'bg' => '#FEF3C7'], ['color' => '#059669', 'bg' => '#D1FAE5'], ['color' => '#CA8A04', 'bg' => '#FEF9C3'],
    ['color' => '#475569', 'bg' => '#F1F5F9'], ['color' => '#6B7280', 'bg' => '#F3F4F6'], ['color' => '#7C3AED', 'bg' => '#EDE9FE'], ['color' => '#DC2626', 'bg' => '#FEE2E2'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_category') {
        $name = trim($_POST['name'] ?? '');
        $parentId = $_POST['parent_id'] ?? '';
        $icon = $_POST['icon'] ?? 'PenTool';
        $colorIdx = max(0, min(count($colorPresets) - 1, (int) ($_POST['color_idx'] ?? 0)));
        $editingId = $_POST['editing_id'] ?? '';
        $preset = $colorPresets[$colorIdx];
        if ($parentId === $editingId) $parentId = '';

        if ($name) {
            if ($editingId) {
                $pdo->prepare('UPDATE categories SET name=?, parent_id=?, icon=?, color=?, bg_color=? WHERE id=?')
                    ->execute([$name, $parentId ?: null, $icon, $preset['color'], $preset['bg'], $editingId]);
                addAuditLog($pdo, $user['id'], 'UPDATE', 'Category', $editingId, 'Updated category');
                flash('success', "{$name} updated.");
            } else {
                $newId = generateId('cat');
                $pdo->prepare('INSERT INTO categories (id, name, parent_id, icon, color, bg_color) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$newId, $name, $parentId ?: null, $icon, $preset['color'], $preset['bg']]);
                addAuditLog($pdo, $user['id'], 'CREATE', 'Category', $newId, "Created category {$name}");
                flash('success', "{$name} added.");
            }
        }
        header('Location: categories.php');
        exit;
    }
    if ($action === 'delete_category') {
        $id = $_POST['category_id'] ?? '';
        $pdo->prepare('UPDATE categories SET parent_id = NULL WHERE parent_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        addAuditLog($pdo, $user['id'], 'DELETE', 'Category', $id, 'Deleted category');
        flash('success', 'Category deleted.');
        header('Location: categories.php');
        exit;
    }
}

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$itemCounts = [];
foreach ($pdo->query('SELECT category_id, COUNT(*) c FROM items GROUP BY category_id')->fetchAll() as $r) { $itemCounts[$r['category_id']] = (int) $r['c']; }
$topLevel = array_values(array_filter($categories, fn($c) => !$c['parent_id']));
$childrenOf = fn($pid) => array_values(array_filter($categories, fn($c) => $c['parent_id'] === $pid));

$pageTitle = 'Categories';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';

function categoryIconSvg(string $icon, string $color = '#6B7280', int $size = 20): string
{
    global $iconOptions;
    if (str_starts_with($icon, 'data:image')) {
        return '<img src="' . e($icon) . '" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:contain;border-radius:4px;">';
    }
    $lucideName = $iconOptions[$icon] ?? 'more-horizontal';
    return '<i data-lucide="' . e($lucideName) . '" style="width:' . $size . 'px;height:' . $size . 'px;color:' . e($color) . '"></i>';
}

function renderCategoryCard(array $cat, int $itemCount, bool $isChild = false): void
{
    ?>
    <div class="rounded-card border border-border bg-surface-card p-card-padding <?= $isChild ? 'border-dashed' : '' ?>">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-3">
                <?php if ($isChild): ?><i data-lucide="corner-down-right" class="text-text-muted flex-shrink-0" style="width:14px;height:14px"></i><?php endif; ?>
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg" style="background-color:<?= e($cat['bg_color']) ?>"><?= categoryIconSvg($cat['icon'], $cat['color']) ?></div>
                <div><div class="text-[14px] font-semibold text-text-primary"><?= e($cat['name']) ?></div><div class="text-[12px] text-text-secondary"><?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?></div></div>
            </div>
            <div class="flex gap-1">
                <button type="button" onclick="document.getElementById('cat-modal-<?= e($cat['id']) ?>').classList.remove('hidden'); document.getElementById('cat-modal-<?= e($cat['id']) ?>').classList.add('flex');" class="rounded p-1.5 text-text-secondary hover:bg-gray-100 hover:text-brand-primary"><i data-lucide="pencil" style="width:14px;height:14px"></i></button>
                <button type="button" onclick="document.getElementById('cat-del-<?= e($cat['id']) ?>').classList.remove('hidden'); document.getElementById('cat-del-<?= e($cat['id']) ?>').classList.add('flex');" class="rounded p-1.5 text-text-secondary hover:bg-red-50 hover:text-red-600"><i data-lucide="trash-2" style="width:14px;height:14px"></i></button>
            </div>
        </div>
    </div>
    <?php
}

function renderCategoryModal(string $modalId, string $title, array $cat, array $topLevel, array $iconOptions, array $colorPresets, string $editingId, string $defaultParentId = ''): void
{
    $selColorIdx = 0;
    foreach ($colorPresets as $idx => $p) { if (($cat['color'] ?? '') === $p['color']) { $selColorIdx = $idx; break; } }
    ?>
    <div id="<?= $modalId ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
        <div class="w-full max-w-md rounded-card bg-surface-card p-6 shadow-lg">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-[16px] font-semibold text-text-primary"><?= e($title) ?></h3>
                <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="text-text-muted hover:text-text-primary"><i data-lucide="x" style="width:18px;height:18px"></i></button>
            </div>
            <form method="POST">
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="editing_id" value="<?= e($editingId) ?>">
            <div class="space-y-4">
                <div><label class="mb-1.5 block text-[13px] font-medium text-text-primary">Category Name *</label><input type="text" name="name" value="<?= e($cat['name'] ?? '') ?>" placeholder="e.g. Writing Instruments" required class="w-full rounded-button border border-border px-3 py-2 text-[14px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary"></div>
                <div>
                    <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Parent Category</label>
                    <select name="parent_id" class="w-full rounded-button border border-border px-3 py-2 text-[14px] focus:border-brand-primary focus:outline-none">
                        <option value="">None — Top Level</option>
                        <?php foreach ($topLevel as $c): if ($c['id'] === $editingId) continue; ?>
                        <option value="<?= e($c['id']) ?>" <?= ($cat['parent_id'] ?? $defaultParentId) === $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-[11px] text-text-muted">Only one level of sub-categories is supported.</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Icon</label>
                    <div class="grid grid-cols-5 gap-2">
                        <?php foreach ($iconOptions as $key => $lucideName): $sel = ($cat['icon'] ?? 'PenTool') === $key; ?>
                        <label class="flex h-9 w-9 items-center justify-center rounded-lg border-2 cursor-pointer <?= $sel ? 'border-brand-primary bg-tint-blue-bg' : 'border-border' ?>">
                            <input type="radio" name="icon" value="<?= e($key) ?>" <?= $sel ? 'checked' : '' ?> class="hidden">
                            <i data-lucide="<?= e($lucideName) ?>" class="text-text-secondary" style="width:16px;height:16px"></i>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-[13px] font-medium text-text-primary">Color</label>
                    <div class="flex gap-2">
                        <?php foreach ($colorPresets as $idx => $p): ?>
                        <label class="h-8 w-8 rounded-full border-2 cursor-pointer <?= $idx === $selColorIdx ? 'border-text-primary' : 'border-transparent' ?>" style="background-color:<?= e($p['color']) ?>">
                            <input type="radio" name="color_idx" value="<?= $idx ?>" <?= $idx === $selColorIdx ? 'checked' : '' ?> class="hidden">
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('<?= $modalId ?>').classList.add('hidden'); document.getElementById('<?= $modalId ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <button type="submit" class="rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover"><?= $editingId ? 'Save Changes' : 'Add Category' ?></button>
            </div>
            </form>
        </div>
    </div>
    <?php
}
?>
<div class="mb-6 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Categories</h1><p class="text-page-subtitle text-text-secondary mt-1">Manage item categories and sub-categories</p></div>
    <button type="button" onclick="document.getElementById('cat-modal-add').classList.remove('hidden'); document.getElementById('cat-modal-add').classList.add('flex');" class="mt-3 sm:mt-0 flex items-center gap-1.5 rounded-button bg-brand-primary px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-primary-hover w-fit"><i data-lucide="plus" style="width:16px;height:16px"></i> Add Category</button>
</div>

<?php renderFlash(); ?>

<div class="space-y-4">
    <?php foreach ($topLevel as $cat): $children = $childrenOf($cat['id']); ?>
    <div>
        <?php renderCategoryCard($cat, $itemCounts[$cat['id']] ?? 0); ?>
        <?php if ($children): ?>
        <div class="ml-8 mt-2 space-y-2 border-l border-dashed border-border pl-4">
            <?php foreach ($children as $child) renderCategoryCard($child, $itemCounts[$child['id']] ?? 0, true); ?>
        </div>
        <?php endif; ?>
        <button type="button" onclick="document.getElementById('cat-modal-sub-<?= e($cat['id']) ?>').classList.remove('hidden'); document.getElementById('cat-modal-sub-<?= e($cat['id']) ?>').classList.add('flex');" class="ml-8 mt-2 flex items-center gap-1 pl-4 text-[12px] font-medium text-brand-primary hover:underline"><i data-lucide="plus" style="width:12px;height:12px"></i> Add sub-category under <?= e($cat['name']) ?></button>
    </div>
    <?php endforeach; ?>
</div>

<?php
renderCategoryModal('cat-modal-add', 'Add Category', [], $topLevel, $iconOptions, $colorPresets, '');
foreach ($topLevel as $cat) {
    renderCategoryModal('cat-modal-sub-' . $cat['id'], 'Add Category', [], $topLevel, $iconOptions, $colorPresets, '', $cat['id']);
}
foreach ($categories as $cat) {
    renderCategoryModal('cat-modal-' . $cat['id'], 'Edit Category', $cat, $topLevel, $iconOptions, $colorPresets, $cat['id']);
    ?>
    <div id="cat-del-<?= e($cat['id']) ?>" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40">
        <div class="w-full max-w-sm rounded-card bg-surface-card p-6 shadow-lg">
            <h3 class="mb-2 text-[15px] font-semibold text-text-primary">Delete category?</h3>
            <p class="mb-4 text-[13px] text-text-secondary">Items already assigned will lose their classification. Any sub-categories will be promoted to top-level.</p>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('cat-del-<?= e($cat['id']) ?>').classList.add('hidden'); document.getElementById('cat-del-<?= e($cat['id']) ?>').classList.remove('flex');" class="rounded-button border border-border px-4 py-2 text-[13px] text-text-secondary hover:bg-gray-50">Cancel</button>
                <form method="POST"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?= e($cat['id']) ?>">
                    <button type="submit" class="rounded-button bg-red-600 px-4 py-2 text-[13px] font-semibold text-white hover:bg-red-700">Delete</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}
?>
<script>
// Icon + color pickers: the "selected" highlight is rendered server-side
// on page load, so without this it never visually moves when you click a
// different option (the radio itself still gets selected correctly, which
// is why the right icon/color saves — it just doesn't *look* selected
// until after you save and the page reloads).
var PICKER_STYLES = {
    icon: { selected: ['border-brand-primary', 'bg-tint-blue-bg'], unselected: ['border-border'] },
    color_idx: { selected: ['border-text-primary'], unselected: ['border-transparent'] },
};
document.addEventListener('change', function (e) {
    var radio = e.target;
    if (radio.tagName !== 'INPUT' || radio.type !== 'radio') return;
    var styles = PICKER_STYLES[radio.name];
    if (!styles) return;
    var form = radio.closest('form');
    if (!form) return;
    form.querySelectorAll('input[name="' + radio.name + '"]').forEach(function (input) {
        var label = input.closest('label');
        if (!label) return;
        if (input.checked) {
            styles.unselected.forEach(function (c) { label.classList.remove(c); });
            styles.selected.forEach(function (c) { label.classList.add(c); });
        } else {
            styles.selected.forEach(function (c) { label.classList.remove(c); });
            styles.unselected.forEach(function (c) { label.classList.add(c); });
        }
    });
});
</script>
<?php
include __DIR__ . '/../includes/app-shell-end.php';