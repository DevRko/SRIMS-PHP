<?php
require_once __DIR__ . '/../config.php';
requireLogin();
requireRole(['ADMIN', 'INVENTORY_MGR']);
$user = currentUser();
$currentPath = '/issue/items';

$step = (int) ($_GET['step'] ?? 1);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['step']) && !isset($_GET['req'])) {
    unset($_SESSION['issue_wizard']);
}

// Deep-link from Issue Queue: ?req=REQ_ID jumps straight to Step 2
// (APPROVED = never issued yet; PARTIAL = some already issued, topping up the rest)
if (isset($_GET['req']) && empty($_SESSION['issue_wizard'])) {
    $r = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND status IN ('APPROVED', 'PARTIAL')");
    $r->execute([$_GET['req']]);
    if ($r->fetch()) {
        $_SESSION['issue_wizard'] = ['req_id' => $_GET['req'], 'lines' => null];
        $step = 2;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'select_req') {
        $_SESSION['issue_wizard'] = ['req_id' => $_POST['req_id'], 'lines' => null];
        header('Location: items.php?step=2');
        exit;
    }

    if ($action === 'confirm_issue') {
        $reqId = $_SESSION['issue_wizard']['req_id'] ?? '';
        $r = $pdo->prepare("SELECT * FROM requisitions WHERE id = ? AND status IN ('APPROVED', 'PARTIAL')");
        $r->execute([$reqId]);
        $req = $r->fetch();
        if (!$req) { header('Location: items.php'); exit; }

        $li = $pdo->prepare('SELECT ri.*, i.current_stock FROM requisition_items ri JOIN items i ON i.id = ri.item_id WHERE ri.requisition_id = ?');
        $li->execute([$reqId]);
        $lineItems = $li->fetchAll();

        $lines = [];
        $isFullyIssued = true;
        foreach ($lineItems as $l) {
            // Only what's left to issue on this line is available to issue
            // now — this is what makes topping up a PARTIAL requisition
            // safe instead of re-issuing the same quantity twice.
            $remaining = max(0, (int) $l['approved_qty'] - (int) $l['issued_qty']);
            $maxQty = min($remaining, (int) $l['current_stock']);
            $issueQtyNow = max(0, min($maxQty, (int) ($_POST['issue_qty'][$l['item_id']] ?? 0)));
            $lines[] = [
                'item_id' => $l['item_id'], 'ri_id' => $l['id'], 'qty' => $issueQtyNow, 'price' => (float) $l['unit_price'],
                'previously_issued' => (int) $l['issued_qty'], 'approved_qty' => (int) $l['approved_qty'],
            ];
            if (($issueQtyNow + (int) $l['issued_qty']) < (int) $l['approved_qty']) $isFullyIssued = false;
        }

        // Issued To is always the original requester — not something an
        // Inventory Manager should be able to redirect to someone else.
        $issuedToId = $req['user_id'];
        $receivedBy = trim($_POST['received_by'] ?? '') ?: $user['name'];
        $remarks = trim($_POST['remarks'] ?? '');
        $referenceNo = generateIssuanceId();
        if ($isFullyIssued) {
            $status = 'ISSUED';
        } else {
            $completionChoice = $_POST['completion_choice'] ?? 'pending';
            $status = $completionChoice === 'complete' ? 'ISSUED' : 'PARTIAL';
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE requisitions SET status=?, updated_at=NOW() WHERE id=?')->execute([$status, $reqId]);
            $updLine = $pdo->prepare('UPDATE requisition_items SET issued_qty = issued_qty + ? WHERE id=?');
            foreach ($lines as $l) { if ($l['qty'] > 0) $updLine->execute([$l['qty'], $l['ri_id']]); }

            $pdo->prepare('INSERT INTO issuances (id, requisition_id, issued_by_id, issued_to_id, received_by, issue_date, reference_no, remarks) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)')
                ->execute([$referenceNo, $reqId, $user['id'], $issuedToId, $receivedBy ?: null, $referenceNo, $remarks ?: null]);

            $insIssItem = $pdo->prepare('INSERT INTO issuance_items (id, issuance_id, item_id, issued_qty, unit_price) VALUES (?, ?, ?, ?, ?)');
            $insTxn = $pdo->prepare('INSERT INTO stock_transactions (id, type, item_id, quantity, unit_price, reference_no, reference_type, linked_requisition_id, date, user_id) VALUES (?, \'OUTWARD\', ?, ?, ?, ?, \'ISSUANCE\', ?, CURDATE(), ?)');
            $updStock = $pdo->prepare('UPDATE items SET current_stock = GREATEST(0, current_stock - ?) WHERE id = ?');
            $totalAmount = 0;
            foreach ($lines as $l) {
                if ($l['qty'] <= 0) continue;
                $insIssItem->execute([generateId('issi'), $referenceNo, $l['item_id'], $l['qty'], $l['price']]);
                $insTxn->execute([generateId('st'), $l['item_id'], $l['qty'], $l['price'], $referenceNo, $reqId, $user['id']]);
                $updStock->execute([$l['qty'], $l['item_id']]);
                $totalAmount += $l['qty'] * $l['price'];
            }
            $pdo->commit();
        } catch (Exception $ex) {
            $pdo->rollBack();
            flash('error', 'Could not complete issuance: ' . $ex->getMessage());
            header('Location: items.php?step=2');
            exit;
        }

        $completionNote = !$isFullyIssued ? (($status === 'ISSUED') ? ' Marked complete despite shortfall.' : ' Kept pending — remainder to be issued later.') : '';
        addAuditLog($pdo, $user['id'], $isFullyIssued ? 'ISSUE_COMPLETE' : 'ISSUE_PARTIAL', 'Requisition', $reqId, "Issued via {$referenceNo}. " . count($lines) . ' line(s) processed.' . $completionNote);
        addNotification($pdo, $req['user_id'], "Your requisition {$reqId} has been " . ($isFullyIssued ? 'fully issued' : 'partially issued') . " ({$referenceNo})", '/requisitions/my');

        $_SESSION['issue_wizard']['completed'] = ['ref' => $referenceNo, 'items' => count(array_filter($lines, fn($l) => $l['qty'] > 0)), 'total' => $totalAmount];
        header('Location: items.php?step=3');
        exit;
    }
}

$wizardReqId = $_SESSION['issue_wizard']['req_id'] ?? null;
$selectedReq = null;
$selectedLines = [];
if ($wizardReqId && $step >= 2) {
    $r = $pdo->prepare("SELECT r.*, u.name AS user_name, d.name AS department_name FROM requisitions r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=r.department_id WHERE r.id = ?");
    $r->execute([$wizardReqId]);
    $selectedReq = $r->fetch();
    if ($selectedReq) {
        $li = $pdo->prepare('SELECT ri.*, i.current_stock, i.icon_key, i.unit, i.name AS item_name FROM requisition_items ri JOIN items i ON i.id = ri.item_id WHERE ri.requisition_id = ?');
        $li->execute([$wizardReqId]);
        $selectedLines = $li->fetchAll();
    }
}

$searchQuery = trim($_GET['q'] ?? '');
$sql = "SELECT r.*, u.name AS user_name, d.name AS department_name, (SELECT COUNT(*) FROM requisition_items WHERE requisition_id=r.id) AS item_count
        FROM requisitions r JOIN users u ON u.id=r.user_id JOIN departments d ON d.id=r.department_id WHERE r.status IN ('APPROVED', 'PARTIAL')";
$params = [];
if ($searchQuery) { $sql .= ' AND (r.id LIKE ? OR u.name LIKE ?)'; $like = "%$searchQuery%"; array_push($params, $like, $like); }
$sql .= ' ORDER BY r.approved_at ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$approvedReqs = $stmt->fetchAll();

$pageTitle = 'Issue Items';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/app-shell-start.php';
?>
<div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div><h1 class="text-page-title text-text-primary">Issue Items</h1><p class="text-page-subtitle text-text-secondary mt-1">Process approved requisitions and issue stock</p></div>
    <div class="flex items-center gap-0">
        <?php foreach ([1 => 'Select Requisition', 2 => 'Issue Items', 3 => 'Confirm & Complete'] as $n => $label): ?>
        <div class="flex items-center gap-2">
            <div class="flex h-7 w-7 items-center justify-center rounded-full text-[12px] font-semibold <?= $n <= $step ? 'bg-brand-primary text-white' : 'border-2 border-gray-300 text-text-muted' ?>"><?php if ($n < $step): ?><i data-lucide="check" style="width:14px;height:14px"></i><?php else: echo $n; endif; ?></div>
            <span class="hidden text-[13px] font-medium sm:inline <?= $n === $step ? 'text-brand-primary' : ($n < $step ? 'text-text-primary' : 'text-text-muted') ?>"><?= $label ?></span>
        </div>
        <?php if ($n < 3): ?><div class="mx-3 h-[2px] w-8 sm:w-12 <?= $n < $step ? 'bg-brand-primary' : 'bg-gray-200' ?>"></div><?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php renderFlash(); ?>

<?php if ($step === 1): ?>
<div class="rounded-card border border-border bg-surface-card">
    <form method="GET" class="flex items-center gap-2 border-b border-border px-4 py-3">
        <div class="relative flex-1 max-w-sm">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-text-muted" style="width:16px;height:16px"></i>
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Search approved requisitions..." class="w-full rounded-button border border-border py-2 pl-9 pr-3 text-[13px] placeholder:text-text-muted focus:border-brand-primary focus:outline-none focus:ring-1 focus:ring-brand-primary">
        </div>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead><tr class="border-b border-border">
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">REQ No.</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Requested By</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Department</th>
                <th class="px-4 py-3 text-left text-table-header uppercase tracking-table-header text-text-secondary">Approved On</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Items</th>
                <th class="px-4 py-3 text-right text-table-header uppercase tracking-table-header text-text-secondary">Amount</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Status</th>
                <th class="px-4 py-3 text-center text-table-header uppercase tracking-table-header text-text-secondary">Action</th>
            </tr></thead>
            <tbody>
                <?php if (empty($approvedReqs)): ?>
                <tr><td colspan="8" class="px-4 py-12 text-center text-[14px] text-text-muted">No approved requisitions waiting to be issued</td></tr>
                <?php else: foreach ($approvedReqs as $req): ?>
                <tr class="border-b border-border last:border-0 hover:bg-gray-50">
                    <td class="px-4 py-3 text-[13px] font-medium text-brand-primary"><?= e($req['id']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-primary"><?= e($req['user_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= e($req['department_name']) ?></td>
                    <td class="px-4 py-3 text-[13px] text-text-secondary"><?= $req['approved_at'] ? formatDate($req['approved_at']) : '—' ?></td>
                    <td class="px-4 py-3 text-right text-[13px] text-text-primary"><?= (int) $req['item_count'] ?></td>
                    <td class="px-4 py-3 text-right text-[13px] font-medium text-text-primary"><?= formatCurrency((float) $req['total_amount']) ?></td>
                    <td class="px-4 py-3 text-center"><?= $req['status'] === 'PARTIAL' ? statusPill('partial', 'Top Up Remaining') : statusPill('approved', 'Ready to Issue') ?></td>
                    <td class="px-4 py-3 text-center">
                        <form method="POST"><input type="hidden" name="action" value="select_req"><input type="hidden" name="req_id" value="<?= e($req['id']) ?>">
                        <button type="submit" class="inline-flex items-center gap-1 rounded-button bg-brand-primary px-3 py-1.5 text-[12px] font-medium text-white hover:bg-brand-primary-hover">Select</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($step === 2 && $selectedReq): ?>
<?php
$totalItems = count($selectedLines);
$fullCount = 0; $partialCount = 0; $totalAmount = 0;
foreach ($selectedLines as $l) {
    $remaining = max(0, (int) $l['approved_qty'] - (int) $l['issued_qty']);
    $maxIssuable = min($remaining, (int) $l['current_stock']);
    if ($maxIssuable >= $remaining) $fullCount++; elseif ($maxIssuable > 0) $partialCount++;
    $totalAmount += $maxIssuable * $l['unit_price'];
}
?>
<div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
    <div class="lg:col-span-3">
        <div class="rounded-card border border-border bg-surface-card p-card-padding">
            <h3 class="mb-1 text-[15px] font-semibold text-text-primary"><?= e($selectedReq['id']) ?></h3>
            <p class="mb-4 text-[12px] text-text-secondary"><?= e($selectedReq['user_name']) ?> — <?= e($selectedReq['department_name']) ?></p>
            <form method="POST" id="issueForm">
            <input type="hidden" name="action" value="confirm_issue">
            <table class="w-full text-[12px]">
                <thead><tr class="border-b border-border">
                    <th class="pb-2 text-left font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Item</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Approved</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Already Issued</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px]">Remaining</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px]">In Stock</th>
                    <th class="pb-2 text-right font-semibold text-text-secondary uppercase tracking-wide text-[10px] w-24">Issue Qty</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($selectedLines as $l):
                        $remaining = max(0, (int) $l['approved_qty'] - (int) $l['issued_qty']);
                        $maxIssuable = min($remaining, (int) $l['current_stock']);
                    ?>
                    <tr class="border-b border-border last:border-0">
                        <td class="py-2"><div class="flex items-center gap-2"><?= itemIconSvg($l['icon_key'], $l['item_id'], 20) ?><span class="text-text-primary"><?= e($l['item_name']) ?></span></div></td>
                        <td class="py-2 text-right text-text-secondary"><?= (int) $l['approved_qty'] ?></td>
                        <td class="py-2 text-right text-text-secondary"><?= (int) $l['issued_qty'] ?: '—' ?></td>
                        <td class="py-2 text-right font-medium text-text-primary"><?= $remaining ?></td>
                        <td class="py-2 text-right font-semibold <?= $l['current_stock'] < $remaining ? 'text-red-600' : 'text-green-600' ?>"><?= (int) $l['current_stock'] ?></td>
                        <td class="py-2 text-right"><input type="number" name="issue_qty[<?= e($l['item_id']) ?>]" value="<?= $maxIssuable ?>" min="0" max="<?= $maxIssuable ?>" onchange="if(parseInt(this.value||0,10)>this.max)this.value=this.max; updateIssueCompletionChoice();" class="w-20 rounded-button border border-border px-2 py-1 text-right text-[12px] focus:border-brand-primary focus:outline-none"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mt-3 flex flex-wrap gap-4 text-[11px] text-text-secondary">
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-green-500"></span> Full Issue: All approved quantity issued</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-500"></span> Partial Issue: Issued less than approved quantity</span>
            </div>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="rounded-card border border-border bg-surface-card p-card-padding">
            <div class="mb-4 grid grid-cols-3 gap-2">
                <div class="rounded-md bg-gray-50 p-2 text-center"><div class="text-[16px] font-bold text-text-primary"><?= $totalItems ?></div><div class="text-[10px] text-text-muted">Total Items</div></div>
                <div class="rounded-md bg-green-50 p-2 text-center"><div class="text-[16px] font-bold text-green-600"><?= $fullCount ?></div><div class="text-[10px] text-text-muted">Full Issue</div></div>
                <div class="rounded-md bg-amber-50 p-2 text-center"><div class="text-[16px] font-bold text-amber-600"><?= $partialCount ?></div><div class="text-[10px] text-text-muted">Partial Issue</div></div>
            </div>
            <h4 class="mb-3 text-[14px] font-semibold text-text-primary">Issuing Details</h4>
            <div class="space-y-3">
                <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Issue Date</label><input type="date" value="<?= date('Y-m-d') ?>" readonly class="w-full rounded-button border border-border bg-gray-50 px-3 py-2 text-[13px] text-text-secondary"></div>
                <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Issued To</label>
                    <input type="text" value="<?= e($selectedReq['user_name']) ?>" readonly class="w-full rounded-button border border-border bg-gray-50 px-3 py-2 text-[13px] text-text-secondary">
                    <input type="hidden" name="issued_to_id" form="issueForm" value="<?= e($selectedReq['user_id']) ?>">
                    <p class="mt-1 text-[11px] text-text-muted">Always the original requester — items can't be redirected to someone else.</p>
                </div>
                <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Received By *</label><input type="text" name="received_by" form="issueForm" value="<?= e($user['name']) ?>" class="w-full rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none"></div>
                <div><label class="mb-1 block text-[12px] font-medium text-text-primary">Remarks</label><textarea name="remarks" form="issueForm" rows="3" maxlength="500" class="w-full rounded-button border border-border px-3 py-2 text-[13px] focus:border-brand-primary focus:outline-none resize-none"></textarea></div>
            </div>

            <div id="issueCompletionChoice" class="mt-4 rounded-md border border-border p-3 <?= $partialCount > 0 ? '' : 'hidden' ?>">
                <p class="mb-2 text-[12px] font-medium text-text-primary">Some lines won't be fully issued. What should happen?</p>
                <label class="mb-1.5 flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="completion_choice" form="issueForm" value="pending" checked class="mt-0.5">
                    <span class="text-[12px] text-text-secondary"><strong class="text-text-primary">Keep Pending</strong> — issue the rest later.</span>
                </label>
                <label class="flex items-start gap-2 cursor-pointer">
                    <input type="radio" name="completion_choice" form="issueForm" value="complete" class="mt-0.5">
                    <span class="text-[12px] text-text-secondary"><strong class="text-text-primary">Mark as Complete</strong> — close this out now.</span>
                </label>
            </div>

            <div class="mt-5 flex flex-col gap-2">
                <button type="submit" form="issueForm" class="flex items-center justify-center gap-2 rounded-button bg-brand-primary py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover"><i data-lucide="check-circle-2" style="width:16px;height:16px"></i> Confirm &amp; Complete</button>
                <a href="items.php" class="flex-1 rounded-button border border-border py-2 text-center text-[13px] font-medium text-text-secondary hover:bg-gray-50"><i data-lucide="arrow-left" style="width:14px;height:14px" class="mr-1 inline"></i> Back</a>
            </div>
        </div>
<script>
function updateIssueCompletionChoice() {
    var form = document.getElementById('issueForm');
    var short = false;
    form.querySelectorAll('input[name^="issue_qty"]').forEach(function (input) {
        if (parseInt(input.value || 0, 10) < parseInt(input.max || 0, 10)) short = true;
    });
    document.getElementById('issueCompletionChoice').classList.toggle('hidden', !short);
}
</script>
    </div>
</div>

<?php elseif ($step === 3 && !empty($_SESSION['issue_wizard']['completed'])):
    $done = $_SESSION['issue_wizard']['completed'];
    unset($_SESSION['issue_wizard']);
?>
<div class="mx-auto max-w-lg text-center">
    <div class="rounded-card border border-border bg-surface-card p-10">
        <div class="mb-4 flex justify-center"><div class="flex h-16 w-16 items-center justify-center rounded-full bg-green-100"><i data-lucide="check-circle-2" class="text-green-600" style="width:32px;height:32px"></i></div></div>
        <h2 class="mb-2 text-[20px] font-bold text-text-primary">Issuance Complete!</h2>
        <p class="mb-6 text-[14px] text-text-secondary">The requisition has been processed and stock has been updated.</p>
        <div class="mb-6 rounded-lg bg-gray-50 p-4 text-left">
            <div class="flex justify-between text-[13px] mb-2"><span class="text-text-secondary">Reference No.</span><span class="font-semibold text-text-primary"><?= e($done['ref']) ?></span></div>
            <div class="flex justify-between text-[13px] mb-2"><span class="text-text-secondary">Items Issued</span><span class="font-semibold text-text-primary"><?= $done['items'] ?></span></div>
            <div class="flex justify-between text-[13px]"><span class="text-text-secondary">Total Value</span><span class="font-semibold text-text-primary"><?= formatCurrency($done['total']) ?></span></div>
        </div>
        <a href="history.php" class="block w-full rounded-button bg-brand-primary py-2.5 text-[14px] font-semibold text-white hover:bg-brand-primary-hover">View Issued History</a>
    </div>
</div>
<?php else: ?>
<script>window.location.href = 'items.php';</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/app-shell-end.php'; ?>
