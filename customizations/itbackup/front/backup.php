<?php

/**
 * Setup > Backups: status, "Backup now", the list (download / delete), retention, how to restore.
 * Super-Admin / config update right only.
 */

use Glpi\Event;
use GlpiPlugin\Itbackup\Backup;

global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$self  = $CFG_GLPI['root_doc'] . '/plugins/itbackup/front/backup.php';
$flash = static fn(string $msg, int $type = INFO) => Session::addMessageAfterRedirect(htmlescape($msg), false, $type);
$h     = static fn($v) => htmlescape((string) $v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['backup_now'])) {
        if (!Backup::available()) {
            $flash('ไม่พบที่เก็บ backup (' . Backup::BACKUP_DIR . ')', ERROR);
        } elseif (Backup::isRunning()) {
            $flash('มี backup กำลังทำงานอยู่แล้ว', WARNING);
        } else {
            // in the background: a big backup outlives the web request
            $cmd = sprintf(
                'nohup php %s manual > %s 2>&1 &',
                escapeshellarg(GLPI_ROOT . '/plugins/itbackup/bin/backup.php'),
                escapeshellarg(Backup::BACKUP_DIR . '/.manual.log')
            );
            exec($cmd);
            Event::log(0, 'system', 3, 'setup', sprintf('%s started a manual backup', $_SESSION['glpiname']));
            $flash('เริ่ม backup แล้ว หน้านี้จะอัปเดตเองเมื่อเสร็จ');
            Html::redirect($self . '?running=1');
        }
    } elseif (isset($_POST['delete'])) {
        $name = (string) $_POST['delete'];
        if (Backup::delete($name)) {
            Event::log(0, 'system', 3, 'setup', sprintf('%s deleted backup %s', $_SESSION['glpiname'], $name));
            $flash("ลบ $name แล้ว");
        } else {
            $flash('ลบไม่สำเร็จ', ERROR);
        }
    } elseif (isset($_POST['save'])) {
        Config::setConfigurationValues(Backup::CONTEXT, ['keep_days' => max(1, min(365, (int) ($_POST['keep_days'] ?? 14)))]);
        $flash('บันทึกแล้ว');
    }
    Html::redirect($self);
}

$list    = Backup::list();
$last    = Backup::lastStatus();
$running = Backup::isRunning();
$conf    = Backup::config();
$latest  = $list[0] ?? null;
$age_h   = $latest ? (time() - strtotime($latest['created'])) / 3600 : null;
$used    = array_sum(array_map(static fn($b) => array_sum(array_column($b['files'] ?? [], 'size')), $list));
$free    = Backup::available() ? disk_free_space(Backup::BACKUP_DIR) : 0;
$size    = static function (int $b): string {
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
        if ($b < 1024) {
            return round($b, 1) . " $u";
        }
        $b /= 1024;
    }
    return round($b, 1) . ' PB';
};
$when = static fn(string $iso) => Html::convDateTime(date('Y-m-d H:i:s', strtotime($iso)));

// health: red when there's no volume, the last run failed, or the newest backup is > 26 h old
if (!Backup::available()) {
    [$level, $msg] = ['danger', 'ไม่พบที่เก็บ backup (' . Backup::BACKUP_DIR . ') ตรวจว่า volume glpi-backups ถูก mount'];
} elseif (($last['status'] ?? '') === 'failed') {
    [$level, $msg] = ['danger', 'backup ล่าสุดล้มเหลว (' . $when($last['created']) . '): ' . ($last['error'] ?? '')];
} elseif ($latest === null) {
    [$level, $msg] = ['warning', 'ยังไม่มี backup เลย กด "Backup now" เพื่อสร้างชุดแรก'];
} elseif ($age_h > 26) {
    [$level, $msg] = ['warning', sprintf('backup ล่าสุดเก่า %d ชั่วโมงแล้ว ตรวจ CronJob glpi-backup', $age_h)];
} else {
    [$level, $msg] = ['success', 'ปกติ: backup ล่าสุดเมื่อ ' . $when($latest['created'])];
}

Html::header('Backups', '', 'config', \GlpiPlugin\Itbackup\Menu::class);
$csrf = $h(Session::getNewCSRFToken());
?>
<div class="container-fluid itbackup-page" style="max-width: 1200px">
  <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
    <h2 class="m-0"><i class="ti ti-database-export me-1"></i>Backups</h2>
    <form method="post" class="ms-auto">
      <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
      <button type="submit" name="backup_now" value="1" class="btn btn-primary itbackup-now" <?= $running || !Backup::available() ? 'disabled' : '' ?>>
        <?php if ($running) { ?><span class="spinner-border spinner-border-sm me-2"></span>กำลัง backup...<?php } else { ?><i class="ti ti-player-play me-1"></i>Backup now<?php } ?>
      </button>
    </form>
  </div>

  <div class="alert alert-<?= $level ?> itbackup-health"><?= $h($msg) ?></div>

  <div class="row g-3 mb-3">
    <div class="col-sm-3"><div class="card card-body"><div class="text-secondary small">backup ทั้งหมด</div><div class="fs-2 fw-bold"><?= count($list) ?></div></div></div>
    <div class="col-sm-3"><div class="card card-body"><div class="text-secondary small">พื้นที่ที่ใช้</div><div class="fs-2 fw-bold"><?= $size($used) ?></div></div></div>
    <div class="col-sm-3"><div class="card card-body"><div class="text-secondary small">พื้นที่ว่าง</div><div class="fs-2 fw-bold"><?= $size((int) $free) ?></div></div></div>
    <div class="col-sm-3"><div class="card card-body"><div class="text-secondary small">อัตโนมัติ</div><div class="fs-4 fw-bold">ทุกวัน 02:00</div><div class="small text-secondary">เก็บ <?= (int) $conf['keep_days'] ?> วัน</div></div></div>
  </div>

  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-vcenter card-table itbackup-list">
        <thead><tr><th>วันที่</th><th>ประเภท</th><th>ฐานข้อมูล</th><th>ไฟล์</th><th>ตาราง</th><th>ใช้เวลา</th><th class="text-end">จัดการ</th></tr></thead>
        <tbody>
        <?php if ($list === []) { ?>
          <tr><td colspan="7" class="text-center text-secondary py-4">ยังไม่มี backup</td></tr>
        <?php } ?>
        <?php foreach ($list as $b) {
            $dl = static fn(string $f) => $h($CFG_GLPI['root_doc'] . '/plugins/itbackup/front/download.php?' . http_build_query(['backup' => $b['name'], 'file' => $f])); ?>
          <tr data-backup="<?= $h($b['name']) ?>">
            <td><?= $h($when($b['created'] ?? '')) ?></td>
            <td><span class="badge bg-<?= ($b['type'] ?? '') === 'manual' ? 'blue' : 'green' ?>-lt"><?= ($b['type'] ?? '') === 'manual' ? 'สั่งเอง' : 'อัตโนมัติ' ?></span></td>
            <td><a href="<?= $dl('database.sql.gz') ?>"><i class="ti ti-download"></i> <?= $size((int) ($b['files']['database.sql.gz']['size'] ?? 0)) ?></a></td>
            <td><a href="<?= $dl('files.tar.gz') ?>"><i class="ti ti-download"></i> <?= $size((int) ($b['files']['files.tar.gz']['size'] ?? 0)) ?></a></td>
            <td><?= (int) ($b['tables'] ?? 0) ?></td>
            <td><?= $h($b['duration_s'] ?? '') ?> s</td>
            <td class="text-end">
              <form method="post" class="d-inline" onsubmit="return confirm('ลบ backup <?= $h($b['name']) ?> ?');">
                <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="delete" value="<?= $h($b['name']) ?>" class="btn btn-sm btn-ghost-danger"><i class="ti ti-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-5">
      <form method="post" class="card">
        <div class="card-header"><h3 class="card-title">ตั้งค่า</h3></div>
        <div class="card-body">
          <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
          <label class="form-label" for="itbackup-keep">เก็บ backup ไว้กี่วัน</label>
          <input class="form-control" type="number" min="1" max="365" id="itbackup-keep" name="keep_days" value="<?= (int) $conf['keep_days'] ?>">
          <div class="form-hint">เก่ากว่านี้ลบอัตโนมัติ (ชุดล่าสุดไม่ถูกลบเสมอ)</div>
        </div>
        <div class="card-footer text-end"><button type="submit" name="save" value="1" class="btn btn-primary">บันทึก</button></div>
      </form>
    </div>
    <div class="col-md-7">
      <div class="card">
        <div class="card-header"><h3 class="card-title">วิธีกู้คืน</h3></div>
        <div class="card-body small">
          <p>กู้คืนทำจาก command line เท่านั้น เพราะจะแทนที่ข้อมูลปัจจุบันทั้งหมด:</p>
<pre class="mb-2">zcat database.sql.gz | kubectl exec -i -n glpi deploy/mariadb -- \
  sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" glpi'
kubectl exec -i -n glpi deploy/glpi-app -c glpi-app -- \
  tar -C /var/www/glpi -xzf - &lt; files.tar.gz
kubectl rollout restart deploy/glpi-app -n glpi</pre>
          <p class="mb-0 text-secondary">files.tar.gz มี <code>config/glpicrypt.key</code> ที่ใช้ถอดรหัสรหัสผ่านในระบบ ต้องกู้คู่กับฐานข้อมูลชุดเดียวกัน และเก็บไฟล์ backup ให้ปลอดภัย</p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php if ($running) { ?>
<script>setTimeout(() => location.reload(), 5000);</script>
<?php } ?>
<?php
Html::footer();
