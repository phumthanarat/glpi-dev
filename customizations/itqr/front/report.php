<?php

/**
 * "Report a problem with this device" - the page a QR label opens: ?t=Printer&id=5&s=<signature>
 *
 * Any logged-in user allowed to create tickets (GLPI sends anonymous visitors to the login page
 * first). The signature is checked before anything about the asset is shown, so only printed
 * labels work. The ticket gets: the asset linked, requester = current user, the asset's entity
 * (when the user has access to it), a category from the asset type and the "QR code" source.
 */

use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Itqr\Label;

global $CFG_GLPI, $DB;

// Not logged in (a first scan): the normal login page, then back here (see plugin_init_itqr()).
if (!Session::getLoginUserID()) {
    Html::redirect($CFG_GLPI['root_doc'] . '/?redirect=' . rawurlencode('/plugins/itqr/front/report.php?' . ($_SERVER['QUERY_STRING'] ?? '')));
}

$req = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$itemtype = (string) ($req['t'] ?? '');
$id       = (int) ($req['id'] ?? 0);
$sig      = (string) ($req['s'] ?? '');

$refuse = static function (string $message): never {
    $e = new BadRequestHttpException();
    $e->setMessageToDisplay($message);
    throw $e;
};
if (!Label::isValid($itemtype, $id, $sig)) {
    $refuse('QR code นี้ไม่ถูกต้อง หรือเป็นป้ายเก่า กรุณาแจ้ง IT');
}
$item = new $itemtype();
if (!$item->getFromDB($id) || $item->isDeleted() || !empty($item->fields['is_template'])) {
    $refuse('ไม่พบเครื่องนี้ในระบบแล้ว กรุณาแจ้ง IT');
}
if (!Ticket::canCreate()) {
    throw new AccessDeniedHttpException();
}

$h = static fn($v) => htmlescape((string) $v);
$self = $CFG_GLPI['root_doc'] . '/plugins/itqr/front/report.php';
$item_name = $item->fields['name'] !== '' ? $item->fields['name'] : ($itemtype::getTypeName(1) . ' #' . $id);
$urgencies = [2 => 'ต่ำ - ยังใช้งานได้', 3 => 'ปานกลาง - ใช้งานได้บางส่วน', 4 => 'สูง - ใช้งานไม่ได้เลย'];
$max_photo = 10 * 1024 * 1024;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title   = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 200);
    $content = mb_substr(trim((string) ($_POST['content'] ?? '')), 0, 5000);
    $urgency = (int) ($_POST['urgency'] ?? 3);
    if ($content === '') {
        $errors[] = 'กรุณาเล่าอาการที่พบ';
    }
    if (!isset($urgencies[$urgency])) {
        $urgency = 3;
    }

    // Optional photo of the problem, stored the way GLPI's own uploads are (GLPI_TMP_DIR + _filename)
    $files = [];
    $photo = $_FILES['photo'] ?? null;
    if ($photo && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $mime = $photo['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']) : '';
        $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if ($photo['error'] !== UPLOAD_ERR_OK || $photo['size'] > $max_photo) {
            $errors[] = 'แนบรูปไม่สำเร็จ (ขนาดไม่เกิน 10 MB)';
        } elseif ($ext === null) {
            $errors[] = 'แนบได้เฉพาะรูปภาพ (JPG, PNG, WEBP, GIF)';
        } else {
            $prefix = uniqid('itqr', true) . '_';
            $name   = 'photo-' . date('Ymd-His') . '.' . $ext;
            if (move_uploaded_file($photo['tmp_name'], GLPI_TMP_DIR . '/' . $prefix . $name)) {
                $files = ['_filename' => [$prefix . $name], '_prefix_filename' => [$prefix]];
            } else {
                $errors[] = 'แนบรูปไม่สำเร็จ';
            }
        }
    }

    if ($errors === []) {
        $entity = in_array((int) $item->fields['entities_id'], $_SESSION['glpiactiveentities'] ?? [], true)
            ? (int) $item->fields['entities_id']
            : (int) $_SESSION['glpiactive_entity'];
        $category = (int) ($DB->request([
            'SELECT' => 'id', 'FROM' => ITILCategory::getTable(),
            'WHERE'  => ['completename' => Label::CATEGORIES[$itemtype] ?? '', 'is_incident' => 1],
        ])->current()['id'] ?? 0);
        $source = new RequestType();
        $source->getFromDBByCrit(['name' => Label::REQUEST_TYPE]);

        $ticket = new Ticket();
        $tid = $ticket->add([
            'name'                => $title !== '' ? $title : 'แจ้งปัญหา: ' . $item_name,
            'content'             => '<p>' . nl2br($h($content)) . '</p>'
                . '<p><em>แจ้งผ่าน QR ที่ตัวเครื่อง: ' . $h($itemtype::getTypeName(1) . ' ' . $item_name) . '</em></p>',
            'type'                => Ticket::INCIDENT_TYPE,
            'urgency'             => $urgency,
            'entities_id'         => $entity,
            'itilcategories_id'   => $category,
            'requesttypes_id'     => $source->isNewItem() ? 0 : $source->getID(),
            '_users_id_requester' => Session::getLoginUserID(),
            'items_id'            => [$itemtype => [$id]],
        ] + $files);
        if ($tid) {
            Session::addMessageAfterRedirect($h(sprintf('แจ้งปัญหาแล้ว เลขที่ Ticket #%d', $tid)));
            Html::redirect(Ticket::getFormURLWithID($tid));
        }
        $errors[] = 'บันทึกไม่สำเร็จ กรุณาลองใหม่ หรือติดต่อ IT';
    }
}

// Open tickets already on this device: a hint to avoid duplicates (count only, no details)
$open = countElementsInTable(['glpi_items_tickets', 'glpi_tickets'], [
    'glpi_items_tickets.itemtype' => $itemtype,
    'glpi_items_tickets.items_id' => $id,
    'glpi_items_tickets.tickets_id' => new \Glpi\DBAL\QueryExpression($DB::quoteName('glpi_tickets.id')),
    'glpi_tickets.is_deleted' => 0,
    'glpi_tickets.status' => Ticket::getNotSolvedStatusArray(),
]);
$location = '';
if (!empty($item->fields['locations_id'])) {
    $loc = new Location();
    $location = $loc->getFromDB($item->fields['locations_id']) ? $loc->fields['completename'] : '';
}

$title = 'แจ้งปัญหา: ' . $item_name;
if (Session::getCurrentInterface() === 'helpdesk') {
    Html::helpHeader($title);
} else {
    Html::header($title, '', 'helpdesk', 'ticket');
}
$v = static fn(string $k, $d = '') => $h($_POST[$k] ?? $d);
?>
<div class="container-fluid" style="max-width: 720px">
  <div class="card mb-3 itqr-device">
    <div class="card-body d-flex gap-3 align-items-center">
      <span class="avatar avatar-lg bg-blue-lt"><i class="ti ti-<?= $itemtype === 'Printer' ? 'printer' : ($itemtype === 'Monitor' ? 'device-desktop' : ($itemtype === 'NetworkEquipment' ? 'network' : ($itemtype === 'Phone' ? 'phone' : 'device-laptop'))) ?> fs-1"></i></span>
      <div class="min-w-0">
        <div class="text-secondary small"><?= $h($itemtype::getTypeName(1)) ?></div>
        <div class="fs-2 fw-bold text-break itqr-device-name"><?= $h($item_name) ?></div>
        <?php if ($location !== '') { ?><div class="text-secondary"><i class="ti ti-map-pin"></i> <?= $h($location) ?></div><?php } ?>
      </div>
    </div>
  </div>

  <?php if ($open > 0) { ?>
  <div class="alert alert-info itqr-open-hint">
    เครื่องนี้มีคนแจ้งปัญหาไว้แล้ว <?= (int) $open ?> รายการที่ยังไม่ปิด ถ้าเป็นอาการเดียวกัน IT กำลังดำเนินการอยู่ แต่ถ้าเป็นเรื่องใหม่ แจ้งเพิ่มได้เลย
  </div>
  <?php } ?>
  <?php foreach ($errors as $e) { ?>
  <div class="alert alert-danger itqr-error"><?= $h($e) ?></div>
  <?php } ?>

  <form method="post" action="<?= $h($self) ?>" enctype="multipart/form-data" class="card itqr-form">
    <div class="card-body">
      <input type="hidden" name="_glpi_csrf_token" value="<?= $h(Session::getNewCSRFToken()) ?>">
      <input type="hidden" name="t" value="<?= $h($itemtype) ?>">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <input type="hidden" name="s" value="<?= $h($sig) ?>">

      <div class="mb-3">
        <label class="form-label required" for="itqr-content">อาการที่พบ</label>
        <textarea class="form-control" id="itqr-content" name="content" rows="5" required maxlength="5000"
                  placeholder="เช่น เปิดไม่ติด, กระดาษติด, จอดับเป็นพัก ๆ"><?= $v('content') ?></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label" for="itqr-name">หัวข้อ <span class="text-secondary">(ไม่ใส่ก็ได้)</span></label>
        <input class="form-control" id="itqr-name" name="name" maxlength="200" value="<?= $v('name') ?>"
               placeholder="<?= $h('แจ้งปัญหา: ' . $item_name) ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">ความเร่งด่วน</label>
        <?php foreach ($urgencies as $u => $label) { ?>
        <label class="form-check">
          <input class="form-check-input" type="radio" name="urgency" value="<?= $u ?>" <?= (int) ($_POST['urgency'] ?? 3) === $u ? 'checked' : '' ?>>
          <span class="form-check-label"><?= $h($label) ?></span>
        </label>
        <?php } ?>
      </div>
      <div class="mb-3">
        <label class="form-label" for="itqr-photo">รูปถ่ายอาการ <span class="text-secondary">(ไม่ใส่ก็ได้, ไม่เกิน 10 MB)</span></label>
        <input class="form-control" type="file" id="itqr-photo" name="photo" accept="image/*" capture="environment">
      </div>
    </div>
    <div class="card-footer text-end">
      <button type="submit" name="report" value="1" class="btn btn-primary"><i class="ti ti-send me-1"></i>ส่งแจ้งปัญหา</button>
    </div>
  </form>
</div>
<?php
if (Session::getCurrentInterface() === 'helpdesk') {
    Html::helpFooter();
} else {
    Html::footer();
}
