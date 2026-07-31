<?php
/**
 * SRIMS - Requisition PDF receipt
 * Accessible to: the requisition's own requester, or ADMIN / APPROVER /
 * INVENTORY_MGR (i.e. anyone who could already see it in the app).
 * Usage: requisitions/receipt-pdf.php?id=REQ-260716-0123
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/simple-pdf.php';
requireLogin();
$user = currentUser();

$reqId = $_GET['id'] ?? '';

$stmt = $pdo->prepare(
    'SELECT r.*, u.name AS user_name, d.name AS department_name
     FROM requisitions r JOIN users u ON u.id = r.user_id LEFT JOIN departments d ON d.id = r.department_id
     WHERE r.id = ?'
);
$stmt->execute([$reqId]);
$req = $stmt->fetch();

if (!$req) { http_response_code(404); die('Requisition not found.'); }

$canView = $req['user_id'] === $user['id'] || in_array($user['role'], ['ADMIN', 'APPROVER', 'INVENTORY_MGR'], true);
if (!$canView) { http_response_code(403); die('You do not have permission to view this receipt.'); }

if (!empty($req['approved_by_id'])) {
    if ($req['approved_by_id'] === AUTO_APPROVAL_ACTOR_ID) {
        $req['approved_by_name'] = AUTO_APPROVAL_ACTOR_NAME;
    } else {
        $a = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $a->execute([$req['approved_by_id']]);
        $req['approved_by_name'] = $a->fetchColumn() ?: '';
    }
} else {
    $req['approved_by_name'] = '';
}

$itemsStmt = $pdo->prepare(
    'SELECT ri.*, i.name AS item_name, i.unit, c.color AS category_color FROM requisition_items ri
     JOIN items i ON i.id = ri.item_id LEFT JOIN categories c ON c.id = i.category_id
     WHERE ri.requisition_id = ?'
);
$itemsStmt->execute([$reqId]);
$items = $itemsStmt->fetchAll();

$logStmt = $pdo->prepare(
    "SELECT al.*, u.name AS actor_name FROM audit_logs al JOIN users u ON u.id = al.actor_id
     WHERE al.entity = 'Requisition' AND al.entity_id = ? ORDER BY al.timestamp ASC"
);
$logStmt->execute([$reqId]);
$timeline = $logStmt->fetchAll();

$actionLabels = [
    'CREATE' => 'Saved as Draft', 'SUBMIT' => 'Submitted for Approval', 'AUTO_APPROVE' => 'Auto-Approved',
    'APPROVE' => 'Approved', 'APPROVE_AND_ISSUE' => 'Approved & Issued', 'REJECT' => 'Rejected',
    'SEND_BACK' => 'Sent Back to Draft', 'ISSUE_COMPLETE' => 'Issued (Full)', 'ISSUE_PARTIAL' => 'Issued (Partial)',
];

// Company branding (System -> Settings). Falls back to "SRIMS" if unset.
$brandRows = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('company_name','company_logo')")->fetchAll();
$companyName = null; $companyLogoDataUrl = null;
foreach ($brandRows as $r) {
    if ($r['setting_key'] === 'company_name' && $r['setting_value'] !== '') $companyName = $r['setting_value'];
    if ($r['setting_key'] === 'company_logo' && $r['setting_value'] !== '') $companyLogoDataUrl = $r['setting_value'];
}

/**
 * Convert an uploaded logo (any GD-supported raster format, as a data:
 * URL) to raw JPEG bytes for embedding — the PDF writer only supports
 * DCTDecode/JPEG since that's what keeps it dependency-free. Returns
 * null if it can't be decoded (e.g. an SVG logo, which GD can't
 * rasterize) so the receipt just falls back to text-only branding.
 */
function logoToJpeg(?string $dataUrl): ?string
{
    if (!$dataUrl || !function_exists('imagecreatefromstring')) return null;
    if (!preg_match('#^data:image/[a-zA-Z+]+;base64,(.+)$#', $dataUrl, $m)) return null;
    $raw = base64_decode($m[1], true);
    if ($raw === false) return null;
    $im = @imagecreatefromstring($raw);
    if (!$im) return null;
    // Flatten transparency onto a white background (JPEG has no alpha).
    $w = imagesx($im); $h = imagesy($im);
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
    ob_start();
    imagejpeg($flat, null, 90);
    $jpeg = ob_get_clean();
    imagedestroy($im);
    imagedestroy($flat);
    return $jpeg ?: null;
}

function pdfMoney(float $amount): string
{
    return 'Rs. ' . number_format($amount, 2);
}

// Whether every line was actually fulfilled at the recorded quantity —
// used to tell "Issued (in full)" apart from "Issued (closed, partial)"
// when an approver chose "Mark as Complete" on a shortfall.
$allFulfilled = true;
foreach ($items as $it) {
    if ((int) $it['issued_qty'] < (int) $it['requested_qty']) { $allFulfilled = false; break; }
}
$statusDisplay = $req['status'];
if ($req['status'] === 'ISSUED' && !$allFulfilled) {
    $statusDisplay = 'ISSUED (Closed - Partial)';
} elseif ($req['status'] === 'PARTIAL') {
    $statusDisplay = 'PARTIAL (Pending Completion)';
}

$pdf = new SimplePdf();
$leftX = 45; $rightColX = 310; $pageRight = 550;
$y = 45;

// ─── Header: company branding (left) + document meta (right) ──────────────
$logoJpeg = logoToJpeg($companyLogoDataUrl);
if ($logoJpeg) {
    $pdf->image($leftX, $y - 5, 32, 32, $logoJpeg);
    $pdf->setFont('B', 16);
    $pdf->text($leftX + 40, $y + 14, $companyName ?: 'SRIMS');
} else {
    $pdf->setFont('B', 20);
    $pdf->text($leftX, $y + 14, $companyName ?: 'SRIMS');
}
$pdf->setFont('', 9);
$pdf->text($pageRight - 130, $y, 'Requisition Receipt', '', 10);
$y += 12;
$pdf->text($pageRight - 130, $y, 'Generated: ' . date('d M Y, h:i A'), '', 8);
$y += 20;
$pdf->line($leftX, $y, $pageRight, $y);
$y += 22;

$pdf->setFont('B', 14);
$pdf->text($leftX, $y, $req['id']);
$pdf->setFont('B', 11);
$pdf->text($pageRight - 160, $y, 'Status: ' . $statusDisplay);
$y += 24;

$pdf->setFont('', 9);
$rows = [
    ['Requested By', $req['user_name'], 'Department', $req['department_name'] ?: '-'],
    ['Created On', date('d M Y, h:i A', strtotime($req['created_at'])), 'Required Date', $req['required_date'] ? date('d M Y', strtotime($req['required_date'])) : '-'],
    ['Purpose', $req['purpose'] ?: '-', 'Priority', ucfirst(strtolower($req['priority']))],
];
foreach ($rows as $r) {
    $pdf->text($leftX, $y, $r[0] . ':', 'B', 9);
    $pdf->text($leftX + 85, $y, (string) $r[1], '', 9);
    $pdf->text($rightColX, $y, $r[2] . ':', 'B', 9);
    $pdf->text($rightColX + 85, $y, (string) $r[3], '', 9);
    $y += 16;
}

if ($req['status'] === 'REJECTED' && $req['rejected_reason']) {
    $pdf->text($leftX, $y, 'Rejection Reason:', 'B', 9);
    $pdf->text($leftX + 110, $y, $req['rejected_reason'], '', 9);
    $y += 16;
} elseif ($req['approved_by_name']) {
    $pdf->text($leftX, $y, 'Approved By:', 'B', 9);
    $pdf->text($leftX + 110, $y, $req['approved_by_name'] . ($req['approved_at'] ? ' on ' . date('d M Y', strtotime($req['approved_at'])) : ''), '', 9);
    $y += 16;
}

$y += 6;
$pdf->line($leftX, $y, $pageRight, $y);
$y += 20;

$pdf->setFont('B', 11);
$pdf->text($leftX, $y, 'Items (' . count($items) . ')');
$y += 16;

// Item, Requested, Approved, Issued, Unit Price, Amount
$colSwatch = $leftX; $colItem = $leftX + 14; $colReq = 305; $colAppr = 355; $colIssued = 405; $colPrice = 455; $colAmt = 505;
$pdf->filledRect($leftX, $y - 10, $pageRight - $leftX, 16);
$pdf->setFont('B', 8);
$pdf->text($colItem, $y, 'ITEM');
$pdf->text($colReq, $y, 'REQ.');
$pdf->text($colAppr, $y, 'APPR.');
$pdf->text($colIssued, $y, 'ISSD.');
$pdf->text($colPrice, $y, 'PRICE');
$pdf->text($colAmt, $y, 'AMOUNT');
$y += 18;

$pdf->setFont('', 9);
$total = 0;
foreach ($items as $item) {
    $qty = $req['status'] === 'ISSUED' || $req['status'] === 'PARTIAL' ? (int) $item['issued_qty'] : (int) $item['requested_qty'];
    $lineAmount = $qty * (float) $item['unit_price'];
    $total += $lineAmount;

    // A colored dot standing in for the item's catalog icon — the PDF
    // writer has no vector icon set (that's what keeps it dependency-free,
    // with no Composer/library install needed), so this uses the item's
    // category color as a visual cue instead of the exact catalog icon.
    $pdf->filledCircleColor($colSwatch + 3.5, $y - 3.5, 3.5, $item['category_color'] ?: '#9CA3AF');

    $name = mb_strlen($item['item_name']) > 30 ? mb_substr($item['item_name'], 0, 30) . '...' : $item['item_name'];
    $pdf->text($colItem, $y, $name);
    $pdf->text($colReq, $y, (string) (int) $item['requested_qty']);
    $pdf->text($colAppr, $y, $item['approved_qty'] > 0 ? (string) (int) $item['approved_qty'] : '-');
    $pdf->text($colIssued, $y, $item['issued_qty'] > 0 ? (string) (int) $item['issued_qty'] : '-');
    $pdf->text($colPrice, $y, number_format((float) $item['unit_price'], 2));
    $pdf->text($colAmt, $y, number_format($lineAmount, 2));
    $y += 15;
}

$y += 2;
$pdf->setFont('', 7);
$pdf->text($leftX, $y, 'REQ. = Qty requested   APPR. = Qty approved by reviewer   ISSD. = Qty actually handed over');
$y += 12;

$pdf->line($leftX, $y, $pageRight, $y);
$y += 16;
$pdf->setFont('B', 10);
$pdf->text(400, $y, 'Total:');
$pdf->text($colAmt, $y, pdfMoney($total));
$y += 24;

$pdf->line($leftX, $y, $pageRight, $y);
$y += 20;

$pdf->setFont('B', 11);
$pdf->text($leftX, $y, 'Status Timeline');
$y += 16;

$pdf->setFont('', 9);
if (empty($timeline)) {
    $pdf->text($leftX, $y, 'No recorded history for this requisition yet.');
    $y += 14;
} else {
    foreach ($timeline as $entry) {
        $label = $actionLabels[$entry['action']] ?? ucwords(strtolower(str_replace('_', ' ', $entry['action'])));
        $pdf->text($leftX, $y, date('d M Y, h:i A', strtotime($entry['timestamp'])), '', 8);
        $pdf->text($leftX + 115, $y, $label, 'B', 9);
        $pdf->text($leftX + 250, $y, 'by ' . $entry['actor_name'], '', 9);
        $y += 13;
        if (!empty($entry['after_json'])) {
            $detail = mb_strlen($entry['after_json']) > 95 ? mb_substr($entry['after_json'], 0, 95) . '...' : $entry['after_json'];
            $pdf->text($leftX + 115, $y, $detail, '', 8);
            $y += 13;
        }
    }
}

$pdf->setFont('', 7);
$pdf->text($leftX, 815, ($companyName ?: 'SRIMS') . ' - Stationery Requisition & Inventory Management System - This is a system-generated document.');

$pdf->output('Receipt-' . $reqId . '.pdf');
