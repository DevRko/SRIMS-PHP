<?php
/**
 * SRIMS - Requisition detail modal (ported 1:1 from RequisitionDetailModal.tsx)
 * Call renderRequisitionModal($pdo, $req) for each requisition row on the
 * page; it renders a hidden <div id="req-modal-{id}"> that a small JS
 * helper (openReqModal / closeReqModal, in footer.php) toggles.
 *
 * $req only strictly needs: id, status, user_id, department_id, created_at,
 * required_date, purpose, remarks, priority, total_amount. If the caller's
 * query already joined user_name / department_name / approved_by_name,
 * those are used as-is; otherwise this function looks them up itself, so
 * it's always safe to call regardless of which columns the page's SQL
 * happened to SELECT.
 */
function renderRequisitionModal(PDO $pdo, array $req): void
{
    if (!isset($req['department_name'])) {
        $req['department_name'] = '';
        if (!empty($req['department_id'])) {
            $d = $pdo->prepare('SELECT name FROM departments WHERE id = ?');
            $d->execute([$req['department_id']]);
            $req['department_name'] = $d->fetchColumn() ?: '';
        }
    }
    if (!isset($req['user_name'])) {
        $req['user_name'] = '';
        if (!empty($req['user_id'])) {
            $u = $pdo->prepare('SELECT name FROM users WHERE id = ?');
            $u->execute([$req['user_id']]);
            $req['user_name'] = $u->fetchColumn() ?: '';
        }
    }
    if (!isset($req['approved_by_name'])) {
        $req['approved_by_name'] = null;
        if (!empty($req['approved_by_id'])) {
            if ($req['approved_by_id'] === AUTO_APPROVAL_ACTOR_ID) {
                $req['approved_by_name'] = AUTO_APPROVAL_ACTOR_NAME;
            } else {
                $a = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                $a->execute([$req['approved_by_id']]);
                $req['approved_by_name'] = $a->fetchColumn() ?: null;
            }
        }
    }

    $stmt = $pdo->prepare(
        'SELECT ri.*, i.name AS item_name, i.unit, i.icon_key FROM requisition_items ri
         JOIN items i ON i.id = ri.item_id WHERE ri.requisition_id = ?'
    );
    $stmt->execute([$req['id']]);
    $items = $stmt->fetchAll();

    $hasBeenIssued = in_array($req['status'], ['ISSUED', 'PARTIAL'], true);
    $isPartial = $req['status'] === 'PARTIAL' || array_reduce($items, fn($carry, $i) => $carry || ($i['issued_qty'] > 0 && $i['issued_qty'] < $i['requested_qty']), false);
    $originalTotal = 0; $issuedTotal = 0;
    foreach ($items as $i) { $originalTotal += $i['requested_qty'] * $i['unit_price']; $issuedTotal += $i['issued_qty'] * $i['unit_price']; }

    $timelineStmt = $pdo->prepare(
        "SELECT al.*, u.name AS actor_name FROM audit_logs al JOIN users u ON u.id = al.actor_id
         WHERE al.entity = 'Requisition' AND al.entity_id = ? ORDER BY al.timestamp ASC"
    );
    $timelineStmt->execute([$req['id']]);
    $timeline = $timelineStmt->fetchAll();
    $timelineLabels = [
        'CREATE' => 'Saved as Draft', 'SUBMIT' => 'Submitted for Approval', 'AUTO_APPROVE' => 'Auto-Approved',
        'APPROVE' => 'Approved', 'APPROVE_AND_ISSUE' => 'Approved & Issued', 'REJECT' => 'Rejected',
        'SEND_BACK' => 'Sent Back to Draft', 'ISSUE_COMPLETE' => 'Issued (Full)', 'ISSUE_PARTIAL' => 'Issued (Partial)',
    ];
    ?>
    <div id="req-modal-<?= e($req['id']) ?>" class="req-modal fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-card bg-surface-card shadow-lg">
            <div class="flex items-start justify-between border-b border-border px-6 py-4">
                <div>
                    <h3 class="text-[18px] font-bold text-text-primary"><?= e($req['id']) ?></h3>
                    <div class="mt-1.5 flex items-center gap-2">
                        <?= statusPill(requisitionStatusVariant($req['status'])) ?>
                        <?php if ($isPartial && $req['status'] === 'PARTIAL'): ?><span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Pending Completion</span>
                        <?php elseif ($isPartial): ?><span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600">Closed — Partial</span><?php endif; ?>
                    </div>
                </div>
                <button type="button" onclick="closeReqModal('<?= e($req['id']) ?>')" class="rounded p-1 text-text-muted hover:bg-gray-100"><i data-lucide="x" style="width:18px;height:18px"></i></button>
            </div>
            <div class="px-6 py-4">
                <div class="mb-5 grid grid-cols-2 gap-4 rounded-lg bg-gray-50 p-4 sm:grid-cols-3">
                    <div><span class="text-[11px] uppercase text-text-muted">Requested By</span><p class="text-[13px] font-medium text-text-primary"><?= e($req['user_name']) ?></p></div>
                    <div><span class="text-[11px] uppercase text-text-muted">Department</span><p class="text-[13px] font-medium text-text-primary"><?= e($req['department_name']) ?></p></div>
                    <div><span class="text-[11px] uppercase text-text-muted">Created On</span><p class="text-[13px] font-medium text-text-primary"><?= e(formatDateTime($req['created_at'])) ?></p></div>
                    <div><span class="text-[11px] uppercase text-text-muted">Required Date</span><p class="text-[13px] font-medium text-text-primary"><?= $req['required_date'] ? e(formatDate($req['required_date'])) : '—' ?></p></div>
                    <div><span class="text-[11px] uppercase text-text-muted">Purpose</span><p class="text-[13px] font-medium text-text-primary"><?= e($req['purpose'] ?: '—') ?></p></div>
                    <div><span class="text-[11px] uppercase text-text-muted">Priority</span><p class="text-[13px] font-medium text-text-primary"><?= priorityLabel($req['priority']) ?></p></div>
                    <?php if (!empty($req['remarks'])): ?>
                    <div class="col-span-2 sm:col-span-3"><span class="text-[11px] uppercase text-text-muted">Remarks</span><p class="text-[13px] text-text-primary"><?= e($req['remarks']) ?></p></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($req['approved_by_name']) || !empty($req['rejected_reason'])): ?>
                <div class="mb-5 rounded-lg p-3 text-[13px] <?= $req['status'] === 'REJECTED' ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-800' ?>">
                    <?php if ($req['status'] === 'REJECTED'): ?>
                        <span class="font-semibold">Rejected by <?= e($req['approved_by_name']) ?>: </span><?= e($req['rejected_reason']) ?>
                    <?php else: ?>
                        <span class="font-semibold"><?= $isPartial ? 'Partially approved' : 'Approved' ?> by <?= e($req['approved_by_name']) ?></span>
                        <?php if (!empty($req['approved_at'])): ?> on <?= e(formatDateTime($req['approved_at'])) ?><?php endif; ?>
                        <?php if ($isPartial): ?><span class="ml-1 text-[12px] opacity-80">— some items issued at reduced quantity</span><?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasBeenIssued): ?>
                <div class="mb-3 flex items-center gap-3">
                    <div class="flex rounded-lg border border-border bg-gray-50 p-0.5">
                        <button type="button" onclick="setReqModalView('<?= e($req['id']) ?>','issued')" id="req-modal-<?= e($req['id']) ?>-btn-issued" class="rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors bg-brand-primary text-white shadow-sm">Approved / Issued</button>
                        <button type="button" onclick="setReqModalView('<?= e($req['id']) ?>','original')" id="req-modal-<?= e($req['id']) ?>-btn-original" class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors text-text-secondary hover:text-text-primary"><i data-lucide="history" style="width:12px;height:12px"></i> Original Request</button>
                    </div>
                </div>
                <?php endif; ?>

                <h4 class="mb-2 text-[13px] font-semibold text-text-primary">Items (<?= count($items) ?>)</h4>

                <!-- "Issued/current" view -->
                <div id="req-modal-<?= e($req['id']) ?>-view-issued" class="overflow-x-auto rounded-md border border-border">
                <table class="w-full">
                    <thead><tr class="border-b border-border bg-gray-50">
                        <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Item</th>
                        <?php if ($hasBeenIssued): ?>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Requested</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-green-700">Issued</th>
                        <?php else: ?>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Req.</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Appr.</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Issued</th>
                        <?php endif; ?>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Amount</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $isReduced = $item['issued_qty'] > 0 && $item['issued_qty'] < $item['requested_qty'];
                        ?>
                        <tr class="border-b border-border last:border-0">
                            <td class="px-3 py-2"><div class="flex items-center gap-2"><?= itemIconSvg($item['icon_key'], $item['item_id'], 22) ?><span class="text-[12px] text-text-primary"><?= e($item['item_name']) ?></span></div></td>
                            <?php if ($hasBeenIssued): ?>
                            <td class="px-3 py-2 text-right text-[12px] text-text-muted"><?= (int)$item['requested_qty'] ?></td>
                            <td class="px-3 py-2 text-right text-[12px] font-semibold <?= $isReduced ? 'text-amber-600' : 'text-green-600' ?>"><?= (int)$item['issued_qty'] ?><?php if ($isReduced): ?><span class="ml-1 text-[9px] font-normal text-amber-500">(-<?= $item['requested_qty'] - $item['issued_qty'] ?>)</span><?php endif; ?></td>
                            <?php else: ?>
                            <td class="px-3 py-2 text-right text-[12px] text-text-primary"><?= (int)$item['requested_qty'] ?></td>
                            <td class="px-3 py-2 text-right text-[12px] text-text-secondary"><?= (int)$item['approved_qty'] ?: '—' ?></td>
                            <td class="px-3 py-2 text-right text-[12px] text-text-secondary"><?= (int)$item['issued_qty'] ?: '—' ?></td>
                            <?php endif; ?>
                            <td class="px-3 py-2 text-right text-[12px] font-medium text-text-primary"><?= formatCurrency(($hasBeenIssued ? $item['issued_qty'] : $item['requested_qty']) * $item['unit_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="bg-gray-50">
                        <td colspan="<?= $hasBeenIssued ? 2 : 3 ?>" class="px-3 py-2 text-right text-[12px] font-semibold text-text-primary">Issued Total</td>
                        <td class="px-3 py-2 text-right text-[13px] font-bold text-brand-primary"><?= formatCurrency($hasBeenIssued ? $issuedTotal : $req['total_amount']) ?></td>
                    </tr></tfoot>
                </table>
                </div>

                <?php if ($hasBeenIssued): ?>
                <!-- "Original request" view (hidden by default) -->
                <div id="req-modal-<?= e($req['id']) ?>-view-original" class="hidden overflow-x-auto rounded-md border border-border">
                <table class="w-full">
                    <thead><tr class="border-b border-border bg-amber-50">
                        <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Item</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-amber-700">Requested Qty</th>
                        <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wider text-text-secondary">Amount</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr class="border-b border-border last:border-0">
                            <td class="px-3 py-2"><div class="flex items-center gap-2"><?= itemIconSvg($item['icon_key'], $item['item_id'], 22) ?><span class="text-[12px] text-text-primary"><?= e($item['item_name']) ?></span></div></td>
                            <td class="px-3 py-2 text-right text-[12px] font-medium text-amber-700"><?= (int)$item['requested_qty'] ?></td>
                            <td class="px-3 py-2 text-right text-[12px] font-medium text-text-primary"><?= formatCurrency($item['requested_qty'] * $item['unit_price']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="bg-amber-50">
                        <td class="px-3 py-2 text-right text-[12px] font-semibold text-text-primary">Original Total</td>
                        <td class="px-3 py-2 text-right text-[13px] font-bold text-amber-700"><?= formatCurrency($originalTotal) ?></td>
                    </tr></tfoot>
                </table>
                </div>
                <?php endif; ?>

                <h4 class="mb-2 mt-5 text-[13px] font-semibold text-text-primary">Status Timeline</h4>
                <?php if (empty($timeline)): ?>
                <p class="text-[12px] text-text-muted">No recorded history yet for this requisition.</p>
                <?php else: ?>
                <div class="space-y-0">
                    <?php foreach ($timeline as $i => $entry): $label = $timelineLabels[$entry['action']] ?? ucwords(strtolower(str_replace('_', ' ', $entry['action']))); ?>
                    <div class="flex gap-3">
                        <div class="flex flex-col items-center">
                            <span class="mt-1.5 h-2.5 w-2.5 flex-shrink-0 rounded-full <?= $entry['action'] === 'REJECT' ? 'bg-red-500' : ($entry['action'] === 'SEND_BACK' ? 'bg-amber-500' : 'bg-brand-primary') ?>"></span>
                            <?php if ($i < count($timeline) - 1): ?><span class="w-px flex-1 bg-border"></span><?php endif; ?>
                        </div>
                        <div class="pb-3">
                            <div class="flex flex-wrap items-baseline gap-x-2">
                                <span class="text-[12px] font-semibold text-text-primary"><?= e($label) ?></span>
                                <span class="text-[11px] text-text-muted">by <?= e($entry['actor_name']) ?> — <?= e(formatDateTime($entry['timestamp'])) ?></span>
                            </div>
                            <?php if (!empty($entry['after_json'])): ?>
                            <p class="mt-0.5 text-[11px] text-text-secondary"><?= e($entry['after_json']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex items-center justify-between border-t border-border px-6 py-3">
                <a href="<?= BASE_URL ?>/requisitions/receipt-pdf.php?id=<?= e($req['id']) ?>" target="_blank" class="flex items-center gap-1.5 rounded-button bg-indigo-600 px-4 py-2 text-[13px] font-medium text-white hover:bg-indigo-700">
                    <i data-lucide="file-down" style="width:14px;height:14px"></i> Download PDF
                </a>
                <button type="button" onclick="closeReqModal('<?= e($req['id']) ?>')" class="rounded-button border border-border px-4 py-2 text-[13px] font-medium text-text-secondary hover:bg-gray-50">Close</button>
            </div>
        </div>
    </div>
    <?php
}
?>
