<?php

/**
 * Setup > Backups: status, "Backup now", the list (download / delete), retention, how to restore.
 * Super-Admin / config update right only.
 */

use Glpi\Event;
use GlpiPlugin\Itbackup\Backup;
use GlpiPlugin\Itbackup\Remote;

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
    } elseif (isset($_POST['save_remote']) || isset($_POST['test_remote'])) {
        $cur = Remote::settings();
        $new = [
            'enabled' => !empty($_POST['remote_enabled']),
            'host'    => trim((string) ($_POST['smb_host'] ?? '')),
            'share'   => trim((string) ($_POST['smb_share'] ?? ''), " \t\\/"),
            'path'    => trim(str_replace('\\', '/', (string) ($_POST['smb_path'] ?? '')), " \t/"),
            'domain'  => trim((string) ($_POST['smb_domain'] ?? '')),
            'user'    => trim((string) ($_POST['smb_user'] ?? '')),
            // an empty password field keeps the stored one
            'pass'    => (string) ($_POST['smb_pass'] ?? '') !== '' ? (string) $_POST['smb_pass'] : $cur['pass'],
        ];
        $error = Remote::validate($new);
        if ($error !== null) {
            $flash($error, ERROR);
            Html::redirect($self);
        }
        Config::setConfigurationValues(Backup::CONTEXT, [
            'remote_enabled' => $new['enabled'] ? 1 : 0,
            'smb_host' => $new['host'], 'smb_share' => $new['share'], 'smb_path' => $new['path'],
            'smb_domain' => $new['domain'], 'smb_user' => $new['user'], 'smb_pass' => $new['pass'],
        ]);
        Event::log(0, 'system', 3, 'setup', sprintf('%s changed the backup file share (%s)', $_SESSION['glpiname'], Remote::label($new)));
        if (isset($_POST['test_remote'])) {
            $error = Remote::test($new);
            $flash($error === null ? 'บันทึกแล้ว และเชื่อมต่อ ' . Remote::label($new) . ' ได้ (เขียน / อ่าน / ลบไฟล์ทดสอบผ่าน)' : 'บันทึกแล้ว แต่เชื่อมต่อไม่ได้: ' . $error, $error === null ? INFO : ERROR);
        } else {
            $flash('บันทึกปลายทางสำรองแล้ว');
        }
    } elseif (isset($_POST['sync_remote'])) {
        if (Backup::isRunning()) {
            $flash('มี backup กำลังทำงานอยู่แล้ว', WARNING);
        } else {
            exec(sprintf('nohup php %s sync > %s 2>&1 &', escapeshellarg(GLPI_ROOT . '/plugins/itbackup/bin/backup.php'), escapeshellarg(Backup::BACKUP_DIR . '/.manual.log')));
            $flash('เริ่ม copy backup ที่ค้างไปยัง file share แล้ว');
            Html::redirect($self . '?running=1');
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
$remote  = Remote::settings();
$rstat   = Remote::lastStatus();
$remote_on = Remote::enabled();
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
} elseif ($remote_on && ($rstat['status'] ?? '') === 'failed') {
    [$level, $msg] = ['danger', 'backup ในเครื่องปกติ แต่ copy ไป file share ล้มเหลว (' . $when($rstat['at']) . '): ' . ($rstat['error'] ?? '')];
} elseif ($remote_on && ($latest['remote']['status'] ?? '') !== 'ok') {
    [$level, $msg] = ['warning', 'backup ล่าสุดยังไม่ได้ copy ไป file share'];
} else {
    [$level, $msg] = ['success', 'ปกติ: backup ล่าสุดเมื่อ ' . $when($latest['created']) . ($remote_on ? ' และ copy ไป ' . Remote::label($remote) . ' แล้ว' : '')];
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
        <thead><tr><th>วันที่</th><th>ประเภท</th><th>ฐานข้อมูล</th><th>ไฟล์</th><th>ตาราง</th><th>ใช้เวลา</th><th>file share</th><th class="text-end">จัดการ</th></tr></thead>
        <tbody>
        <?php if ($list === []) { ?>
          <tr><td colspan="8" class="text-center text-secondary py-4">ยังไม่มี backup</td></tr>
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
            <td class="itbackup-remote"><?php
                $r = $b['remote']['status'] ?? null;
                if ($r === 'ok') { ?><span class="badge bg-green-lt" title="<?= $h($b['remote']['target'] ?? '') ?>"><i class="ti ti-check"></i> copy แล้ว</span><?php }
                elseif ($r === 'failed') { ?><span class="badge bg-red-lt" title="<?= $h($b['remote']['error'] ?? '') ?>"><i class="ti ti-x"></i> ล้มเหลว</span><?php }
                else { ?><span class="text-secondary">-</span><?php } ?></td>
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

  <form method="post" class="card mb-3 itbackup-remote-form">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title"><i class="ti ti-server-2 me-1"></i>ปลายทางสำรอง: Windows file share</h3>
      <label class="form-check form-switch ms-auto mb-0">
        <input class="form-check-input" type="checkbox" name="remote_enabled" value="1" <?= $remote['enabled'] ? 'checked' : '' ?>>
        <span class="form-check-label">copy ทุก backup ไปที่นี่</span>
      </label>
    </div>
    <div class="card-body">
      <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
      <div class="row g-3">
        <div class="col-md-4"><label class="form-label">File server (ชื่อเครื่องหรือ IP)</label>
          <input class="form-control" name="smb_host" value="<?= $h($remote['host']) ?>" placeholder="fileserver.company.local"></div>
        <div class="col-md-3"><label class="form-label">Share</label>
          <input class="form-control" name="smb_share" value="<?= $h($remote['share']) ?>" placeholder="backup"></div>
        <div class="col-md-5"><label class="form-label">โฟลเดอร์ใน share</label>
          <input class="form-control" name="smb_path" value="<?= $h($remote['path']) ?>" placeholder="glpi"></div>
        <div class="col-md-4"><label class="form-label">Domain <span class="text-secondary">(ไม่ใส่ = WORKGROUP)</span></label>
          <input class="form-control" name="smb_domain" value="<?= $h($remote['domain']) ?>" placeholder="COMPANY"></div>
        <div class="col-md-4"><label class="form-label">ผู้ใช้</label>
          <input class="form-control" name="smb_user" value="<?= $h($remote['user']) ?>" autocomplete="off"></div>
        <div class="col-md-4"><label class="form-label">รหัสผ่าน</label>
          <input class="form-control" type="password" name="smb_pass" value="" autocomplete="new-password" placeholder="<?= $remote['pass'] !== '' ? '•••••••• (เว้นว่าง = ใช้รหัสเดิม)' : '' ?>"></div>
      </div>
      <div class="form-hint mt-2">
        ปลายทาง: <code><?= $h($remote['host'] !== '' ? Remote::label($remote) : '\\\\server\\share\\folder') ?></code>
        · ใช้บัญชีที่เขียนได้เฉพาะโฟลเดอร์นี้ · เก็บตามจำนวนวันเดียวกับในเครื่อง
        <?php if (!empty($rstat['at'])) { ?> · copy ล่าสุด: <?= $h($when($rstat['at'])) ?> (<?= ($rstat['status'] ?? '') === 'ok' ? 'สำเร็จ' : 'ล้มเหลว' ?>)<?php } ?>
      </div>
    </div>
    <div class="card-footer d-flex gap-2 justify-content-end">
      <?php if ($remote_on) { ?><button type="submit" name="sync_remote" value="1" class="btn btn-outline-secondary" <?= $running ? 'disabled' : '' ?>><i class="ti ti-refresh me-1"></i>copy ที่ค้างตอนนี้</button><?php } ?>
      <button type="submit" name="test_remote" value="1" class="btn btn-outline-primary"><i class="ti ti-plug-connected me-1"></i>บันทึก + ทดสอบการเชื่อมต่อ</button>
      <button type="submit" name="save_remote" value="1" class="btn btn-primary">บันทึก</button>
    </div>
  </form>

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
