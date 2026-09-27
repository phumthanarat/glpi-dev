<?php

/**
 * Printable sheet of QR labels (technicians): ?itemtype=Printer&ids=1,2,3
 * Opened from an asset's "QR แจ้งปัญหา" tab or the "Print QR labels" massive action.
 * One label per asset the current user can read; sized to print on A4 or label paper.
 */

use GlpiPlugin\Itqr\Label;

if (Session::getCurrentInterface() !== 'central') {
    Html::displayRightError();
}

$itemtype = (string) ($_GET['itemtype'] ?? '');
if (!in_array($itemtype, Label::ITEMTYPES, true)) {
    Html::displayErrorAndDie('Unknown item type');
}
$ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))))), 0, 500);

$labels = [];
foreach ($ids as $id) {
    $item = new $itemtype();
    if (!$item->can($id, READ)) {
        continue;
    }
    $labels[] = [
        'name'   => $item->fields['name'] !== '' ? $item->fields['name'] : ($itemtype::getTypeName(1) . ' #' . $id),
        'type'   => $itemtype::getTypeName(1),
        'serial' => trim(($item->fields['otherserial'] ?? '') ?: ($item->fields['serial'] ?? '')),
        'qr'     => Label::svg(Label::reportUrl($item), 160),
    ];
}
$h = static fn($v) => htmlescape((string) $v);

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ป้าย QR แจ้งปัญหา (<?= count($labels) ?>)</title>
<style>
    :root { color-scheme: light; }
    body { font-family: system-ui, "Noto Sans Thai", sans-serif; margin: 16px; background: #f4f6fa; color: #1d273b; }
    .bar { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; }
    .bar button { font: inherit; padding: 8px 16px; border: 0; border-radius: 6px; background: #206bc4; color: #fff; cursor: pointer; }
    .sheet { display: grid; grid-template-columns: repeat(auto-fill, 70mm); gap: 6mm; }
    .label { width: 70mm; height: 40mm; box-sizing: border-box; background: #fff; border: 1px dashed #b8c2d3; border-radius: 3mm;
             padding: 3mm; display: flex; gap: 3mm; align-items: center; break-inside: avoid; }
    .label svg { width: 32mm; height: 32mm; flex: none; }
    .label .txt { min-width: 0; font-size: 9pt; line-height: 1.3; }
    .label .cta { font-weight: 700; font-size: 10.5pt; margin-bottom: 1.5mm; }
    .label .name { font-weight: 600; overflow-wrap: anywhere; }
    .label .muted { color: #5b6477; }
    @media print {
        body { margin: 0; background: #fff; }
        .bar { display: none; }
        .label { border-color: #999; }
    }
</style>
</head>
<body>
<div class="bar">
    <button type="button" onclick="window.print()">พิมพ์</button>
    <span><?= count($labels) ?> ป้าย · ตัดตามเส้นประแล้วติดที่ตัวเครื่อง</span>
</div>
<div class="sheet">
<?php foreach ($labels as $l) { ?>
    <div class="label">
        <?= $l['qr'] ?>
        <div class="txt">
            <div class="cta">📱 สแกนเพื่อแจ้งปัญหา IT</div>
            <div class="name"><?= $h($l['name']) ?></div>
            <div class="muted"><?= $h($l['type']) ?><?= $l['serial'] !== '' ? ' · ' . $h($l['serial']) : '' ?></div>
        </div>
    </div>
<?php } ?>
</div>
</body>
</html>
