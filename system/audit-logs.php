<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN']);
$user = currentUser();
$currentPath = '/system/audit-logs';

$actionColors = [
    'APPROVE' => 'text-green-600 bg-green-50', 'REJECT' => 'text-red-600 bg-red-50',
    'ISSUE_COMPLETE' => 'text-blue-600 bg-blue-50', 'ISSUE_PARTIAL' => 'text-amber-600 bg-amber-50', 'STOCK_INWARD' => 'text-purple-600 bg-purple-50',
];

$searchQuery = trim($_GET['q'] ?? '');
$sql = 'SELECT al.*, u.name AS actor_name FROM audit_logs al JOIN users u ON u.id = al.actor_id WHERE 1=1';
$params = [];
if ($searchQuery) {
    $sql .= ' AND (u.name LIKE ? OR al.action LIKE ? OR al.entity LIKE ? OR al.entity_id LIKE ?)';
    $like = "%$searchQuery%"; array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY al.timestamp DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Audit Logs';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6"><h1 class="text-page-title text-text-primary">Audit Logs</h1><p class="text-page-subtitle text-text-secondary mt-1">Complete trail of all system actions and changes</p></div>

<div class="rounded-card border border-border bg-surface-card">
    <form method="GET" class="border-b border-border px-4 py-3">
        <div class="relative max-w-sm">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:16px;height:16px"></i>
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search by actor, action, or entity..." class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
        </div>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Timestamp</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Actor</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Entity</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Details</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary w-10"></th>
            </tr></thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="6" class="px-4 py-12 text-center text-[14px] text-text-muted">No audit log entries found</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50 cursor-pointer" onclick="var d=document.getElementById('audit-detail-<?= e($log['id']) ?>'); d.classList.toggle('hidden'); var c=document.getElementById('audit-chev-<?= e($log['id']) ?>'); c.setAttribute('data-lucide', d.classList.contains('hidden')?'chevron-down':'chevron-up'); if(window.lucide) lucide.createIcons();">
                    <td class="px-4 py-3 text-[13px] text-text-secondary whitespace-nowrap"><?= formatDateTime($log['timestamp']) ?></td>
                    <td class="px-4 py-3 text-[13px] font-medium text-text-primary"><?= e($log['actor_name']) ?></td>
                    <td class="px-4 py-3"><span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-semibold <?= $actionColors[$log['action']] ?? 'text-text-secondary bg-gray-100' ?>"><?= e(str_replace('_', ' ', $log['action'])) ?></span></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($log['entity']) ?> <span class="text-text-muted">#<?= e($log['entity_id']) ?></span></td>
                    <td class="px-4 py-3 text-[12px] text-text-secondary truncate max-w-[300px]"><?= e($log['after_json']) ?></td>
                    <td class="px-4 py-3 text-center text-text-muted"><i data-lucide="chevron-down" id="audit-chev-<?= e($log['id']) ?>" style="width:14px;height:14px"></i></td>
                </tr>
                <tr class="bg-gray-50 hidden" id="audit-detail-<?= e($log['id']) ?>">
                    <td colspan="6" class="px-4 py-3">
                        <div class="rounded-md bg-white border border-border p-3 text-[12px] font-mono text-text-secondary whitespace-pre-wrap"><?= e(json_encode(['id' => $log['id'], 'actor' => $log['actor_name'], 'action' => $log['action'], 'entity' => $log['entity'], 'entityId' => $log['entity_id'], 'details' => $log['after_json'], 'timestamp' => $log['timestamp']], JSON_PRETTY_PRINT)) ?></div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
