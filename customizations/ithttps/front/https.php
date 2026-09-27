<?php

/**
 * Setup > HTTPS: the certificate GLPI is served with - status, upload the organisation's
 * certificate (.crt/.pem + key, or .pfx), or an internal CA certificate (+ CA download).
 * Config update right only. The private key is never shown or sent back.
 */

use Glpi\Event;
use GlpiPlugin\Ithttps\Kube;
use GlpiPlugin\Ithttps\Tls;

global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$self  = $CFG_GLPI['root_doc'] . '/plugins/ithttps/front/https.php';
$flash = static fn(string $msg, int $type = INFO) => Session::addMessageAfterRedirect(htmlescape($msg), false, $type);
$h     = static fn($v) => htmlescape((string) $v);
$hosts_from = static fn(string $txt) => array_values(array_unique(array_filter(array_map(
    static fn($l) => strtolower(trim($l)),
    preg_split('/[\s,]+/', $txt)
))));
$file = static function (string $field): ?string {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1024 * 1024) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (ไม่เกิน 1 MB)');
    }
    return (string) file_get_contents($f['tmp_name']);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hosts = $hosts_from((string) ($_POST['hosts'] ?? implode(',', Tls::config()['hosts'])));
        if (isset($_POST['upload'])) {
            $pfx = $file('pfx');
            if ($pfx !== null) {
                [$chain, $key] = Tls::fromPfx($pfx, (string) ($_POST['pfx_password'] ?? ''));
            } else {
                $chain = $file('crt');
                $key   = $file('key');
                if ($chain === null || $key === null) {
                    throw new RuntimeException('เลือกไฟล์ certificate และ private key (หรือไฟล์ .pfx ไฟล์เดียว)');
                }
            }
            $info = Tls::installUploaded($chain, $key, $hosts);
            Tls::setConfig(['hosts' => $hosts]);
            Event::log(0, 'system', 3, 'setup', sprintf('%s uploaded the HTTPS certificate (%s, until %s)', $_SESSION['glpiname'], $info['subject'], $info['to']));
            $flash('ใช้ certificate ที่อัปโหลดแล้ว (' . implode(', ', $info['names']) . ' ถึง ' . Html::convDate(substr($info['to'], 0, 10)) . ') เว็บจะเปลี่ยนมาใช้ภายในราว 1 นาที');
        } elseif (isset($_POST['internal'])) {
            $info = Tls::issueInternal($hosts);
            Event::log(0, 'system', 3, 'setup', sprintf('%s issued an internal HTTPS certificate for %s', $_SESSION['glpiname'], implode(', ', $hosts)));
            $flash('ออกใบรับรองภายในแล้ว (' . implode(', ', $info['names']) . ' ถึง ' . Html::convDate(substr($info['to'], 0, 10)) . ') เว็บจะเปลี่ยนมาใช้ภายในราว 1 นาที');
        }
    } catch (\Throwable $e) {
        $flash($e->getMessage(), ERROR);
    }
    Html::redirect($self);
}

$conf = Tls::config();
$cur = null;
$error = null;
try {
    $cur = Tls::current();
    $has_ca = Tls::caCertificate() !== null;
} catch (\Throwable $e) {
    $error = $e->getMessage();
    $has_ca = false;
}
$uncovered = $cur ? array_values(array_filter($conf['hosts'], static fn($x) => !Tls::covers($cur['names'], $x))) : [];

if ($error !== null) {
    [$level, $msg] = ['danger', 'อ่าน certificate ไม่ได้: ' . $error];
} elseif ($cur === null) {
    [$level, $msg] = ['warning', 'ยังไม่มี certificate: เว็บใช้ใบรับรองตัวอย่างของ Ingress อยู่ อัปโหลด certificate หรือออกใบรับรองภายในด้านล่าง'];
} elseif ($cur['days_left'] < 0) {
    [$level, $msg] = ['danger', 'certificate หมดอายุแล้ว'];
} elseif ($uncovered !== []) {
    [$level, $msg] = ['danger', 'certificate ไม่ครอบคลุม: ' . implode(', ', $uncovered)];
} elseif ($cur['days_left'] < Tls::RENEW_DAYS) {
    [$level, $msg] = [$conf['mode'] === 'internal' ? 'warning' : 'danger', sprintf('certificate จะหมดอายุใน %d วัน', $cur['days_left'])
        . ($conf['mode'] === 'internal' ? ' (จะต่ออายุอัตโนมัติคืนนี้)' : ': อัปโหลดใบใหม่')];
} else {
    [$level, $msg] = ['success', sprintf('ปกติ: ใช้ได้อีก %d วัน', $cur['days_left'])];
}

Html::header('HTTPS', '', 'config', \GlpiPlugin\Ithttps\Menu::class);
$csrf = $h(Session::getNewCSRFToken());
$mode_label = ['uploaded' => 'อัปโหลดเอง', 'internal' => 'ใบรับรองภายใน (Internal CA)'][$conf['mode']] ?? '-';
?>
<div class="container-fluid ithttps-page" style="max-width: 1200px">
  <h2 class="mb-3"><i class="ti ti-lock me-1"></i>HTTPS</h2>
  <div class="alert alert-<?= $level ?> ithttps-health"><?= $h($msg) ?></div>

  <?php if ($cur) { ?>
  <div class="card mb-3 ithttps-current">
    <div class="card-header"><h3 class="card-title">certificate ที่ใช้อยู่</h3><span class="badge bg-blue-lt ms-2"><?= $h($mode_label) ?></span></div>
    <div class="card-body">
      <dl class="row mb-0">
        <dt class="col-sm-3">ชื่อที่ครอบคลุม</dt><dd class="col-sm-9 ithttps-names"><?= $h(implode(', ', $cur['names'])) ?></dd>
        <dt class="col-sm-3">ออกโดย</dt><dd class="col-sm-9"><?= $h($cur['issuer']) ?><?= $cur['self_signed'] ? ' (self-signed)' : '' ?></dd>
        <dt class="col-sm-3">ใช้ได้</dt><dd class="col-sm-9"><?= $h(Html::convDate(substr($cur['from'], 0, 10))) ?> ถึง <?= $h(Html::convDate(substr($cur['to'], 0, 10))) ?> (<?= (int) $cur['days_left'] ?> วัน)</dd>
        <dt class="col-sm-3">SHA-256</dt><dd class="col-sm-9"><code class="small text-break"><?= $h($cur['sha256']) ?></code></dd>
      </dl>
    </div>
  </div>
  <?php } ?>

  <div class="row g-3">
    <div class="col-lg-6">
      <form method="post" enctype="multipart/form-data" class="card h-100 ithttps-upload">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-upload me-1"></i>1. อัปโหลด certificate ขององค์กร</h3></div>
        <div class="card-body">
          <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
          <p class="text-secondary small">จาก CA ที่องค์กรใช้ หรือที่ซื้อมา (เช่น *.company.com) เบราว์เซอร์เชื่อถือทันที</p>
          <div class="mb-2"><label class="form-label">Certificate (.crt / .pem, ใส่ intermediate ต่อท้ายได้)</label>
            <input class="form-control" type="file" name="crt" accept=".crt,.pem,.cer"></div>
          <div class="mb-3"><label class="form-label">Private key (.key, ไม่เข้ารหัส)</label>
            <input class="form-control" type="file" name="key" accept=".key,.pem"></div>
          <div class="text-center text-secondary small mb-2">หรือ</div>
          <div class="row g-2">
            <div class="col-7"><label class="form-label">ไฟล์ .pfx / .p12</label><input class="form-control" type="file" name="pfx" accept=".pfx,.p12"></div>
            <div class="col-5"><label class="form-label">รหัสผ่าน .pfx</label><input class="form-control" type="password" name="pfx_password" autocomplete="off"></div>
          </div>
          <div class="form-hint mt-2">ตรวจก่อนใช้: key ตรงกับ certificate, ยังไม่หมดอายุ และครอบคลุมชื่อเว็บด้านขวา</div>
        </div>
        <div class="card-footer text-end"><button type="submit" name="upload" value="1" class="btn btn-primary">อัปโหลดและใช้งาน</button></div>
      </form>
    </div>
    <div class="col-lg-6">
      <form method="post" class="card h-100 ithttps-internal">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-certificate me-1"></i>2. ใบรับรองภายใน (Internal CA)</h3></div>
        <div class="card-body">
          <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
          <p class="text-secondary small">ระบบออกใบรับรองให้เอง และต่ออายุอัตโนมัติก่อนหมด 30 วัน ให้เครื่องในองค์กรเชื่อถือโดยแจก CA ผ่าน Group Policy (AD):
            Computer Configuration &gt; Policies &gt; Windows Settings &gt; Security Settings &gt; Public Key Policies &gt; Trusted Root Certification Authorities</p>
          <label class="form-label">ชื่อเว็บ (บรรทัดละชื่อ)</label>
          <textarea class="form-control" name="hosts" rows="3"><?= $h(implode("\n", $conf['hosts'])) ?></textarea>
          <div class="form-hint">ชื่อที่ผู้ใช้พิมพ์เข้าเว็บ เช่น itsm.company.local</div>
        </div>
        <div class="card-footer d-flex gap-2 justify-content-end">
          <?php if ($has_ca) { ?><a class="btn btn-outline-secondary ithttps-ca" href="<?= $h($CFG_GLPI['root_doc'] . '/plugins/ithttps/front/ca.php') ?>"><i class="ti ti-download me-1"></i>ดาวน์โหลด CA (.crt)</a><?php } ?>
          <button type="submit" name="internal" value="1" class="btn btn-primary"><?= $conf['mode'] === 'internal' ? 'ออกใบรับรองใหม่' : 'ใช้ใบรับรองภายใน' ?></button>
        </div>
      </form>
    </div>
  </div>
  <?php if (!Kube::available()) { ?>
  <div class="alert alert-warning mt-3">ไม่ได้รันบน Kubernetes: จัดการ certificate ที่ web server / reverse proxy แทน</div>
  <?php } ?>
</div>
<?php
Html::footer();
