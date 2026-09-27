<?php

/**
 * Setup > Security: idle timeout, 2FA per profile + grace period, who hasn't enrolled yet,
 * reset 2FA (lost phone). Config update right only.
 */

use Glpi\Event;
use Glpi\Security\TOTPManager;
use GlpiPlugin\Itsecurity\Idle;

global $CFG_GLPI, $DB;

Session::checkRight('config', UPDATE);

$self  = $CFG_GLPI['root_doc'] . '/plugins/itsecurity/front/security.php';
$flash = static fn(string $msg, int $type = INFO) => Session::addMessageAfterRedirect(htmlescape($msg), false, $type);
$h     = static fn($v) => htmlescape((string) $v);
$totp  = new TOTPManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save'])) {
        $minutes = max(0, min(1440, (int) ($_POST['idle_minutes'] ?? 60)));
        Config::setConfigurationValues('plugin:itsecurity', ['idle_minutes' => $minutes]);

        $enforced = array_map('intval', (array) ($_POST['tfa_profiles'] ?? []));
        foreach ($DB->request(['SELECT' => ['id', '2fa_enforced'], 'FROM' => 'glpi_profiles']) as $p) {
            $want = in_array((int) $p['id'], $enforced, true) ? 1 : 0;
            if ((int) $p['2fa_enforced'] !== $want) {
                (new Profile())->update(['id' => $p['id'], '2fa_enforced' => $want]);
            }
        }
        $grace = max(0, min(90, (int) ($_POST['grace_days'] ?? 7)));
        if ((int) $CFG_GLPI['2fa_grace_days'] !== $grace) {
            // same as GLPI's own setting: the grace period starts when it's changed
            Config::setConfigurationValues('core', ['2fa_grace_days' => $grace, '2fa_grace_date_start' => $grace > 0 ? $_SESSION['glpi_currenttime'] : null]);
        }
        Event::log(0, 'system', 3, 'setup', sprintf('%s changed login security (idle %d min, 2FA profiles %s, grace %d days)', $_SESSION['glpiname'], $minutes, implode(',', $enforced), $grace));
        $flash('บันทึกแล้ว');
    } elseif (isset($_POST['reset_2fa'])) {
        $u = new User();
        if ($u->getFromDB((int) $_POST['reset_2fa']) && $totp->disable2FAForUser($u->getID())) {
            Event::log(0, 'system', 3, 'setup', sprintf('%s reset 2FA of %s', $_SESSION['glpiname'], $u->fields['name']));
            $flash('รีเซ็ต 2FA ของ ' . $u->fields['name'] . ' แล้ว ผู้ใช้ต้องตั้งใหม่ตอน login ครั้งถัดไป');
        } else {
            $flash('รีเซ็ตไม่สำเร็จ', ERROR);
        }
    }
    Html::redirect($self);
}

$profiles = iterator_to_array($DB->request(['SELECT' => ['id', 'name', 'interface', '2fa_enforced'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name']));
$grace_left = $totp->getGracePeriodDaysLeft();

// active users of a central (technician/admin) profile, with their 2FA state
$users = [];
foreach ($DB->request([
    'SELECT'     => ['u.id', 'u.name', 'u.realname', 'u.firstname', 'p.name AS profile', 'p.2fa_enforced'],
    'DISTINCT'   => true,
    'FROM'       => 'glpi_users AS u',
    'INNER JOIN' => [
        'glpi_profiles_users AS pu' => ['ON' => ['pu' => 'users_id', 'u' => 'id']],
        'glpi_profiles AS p'        => ['ON' => ['p' => 'id', 'pu' => 'profiles_id']],
    ],
    'WHERE'      => ['u.is_active' => 1, 'u.is_deleted' => 0, 'p.interface' => 'central'],
    'ORDER'      => 'u.name',
]) as $r) {
    $id = (int) $r['id'];
    $users[$id] ??= ['id' => $id, 'name' => $r['name'], 'full' => trim($r['firstname'] . ' ' . $r['realname']), 'profiles' => [], 'enforced' => false, 'enabled' => $totp->is2FAEnabled($id)];
    $users[$id]['profiles'][] = $r['profile'];
    $users[$id]['enforced'] = $users[$id]['enforced'] || (int) $r['2fa_enforced'] === 1;
}
$missing = count(array_filter($users, static fn($u) => $u['enforced'] && !$u['enabled']));

Html::header('Security', '', 'config', \GlpiPlugin\Itsecurity\Menu::class);
$csrf = $h(Session::getNewCSRFToken());
?>
<div class="container-fluid itsecurity-page" style="max-width: 1200px">
  <h2 class="mb-3"><i class="ti ti-shield-lock me-1"></i>Security</h2>
  <?php if ($missing > 0) { ?>
  <div class="alert alert-warning itsecurity-missing">ยังไม่ได้ตั้ง 2FA <?= $missing ?> คน
    <?= $grace_left > 0 ? "(เหลือเวลาอีก $grace_left วัน หลังจากนั้นต้องตั้งก่อนจึงจะเข้าระบบได้)" : '(ต้องตั้งตอน login ครั้งถัดไป)' ?></div>
  <?php } else { ?>
  <div class="alert alert-success">ทุกคนที่ต้องใช้ 2FA ตั้งแล้ว</div>
  <?php } ?>

  <form method="post" class="card mb-3 itsecurity-settings">
    <div class="card-body">
      <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
      <div class="row g-4">
        <div class="col-md-4">
          <h3 class="card-title"><i class="ti ti-clock me-1"></i>Session timeout</h3>
          <label class="form-label" for="itsecurity-idle">ออกจากระบบอัตโนมัติเมื่อไม่ได้ใช้งาน (นาที)</label>
          <input class="form-control" type="number" min="0" max="1440" id="itsecurity-idle" name="idle_minutes" value="<?= (int) Idle::minutes() ?>">
          <div class="form-hint">0 = ปิด · หน้าจอที่เปิดทิ้งไว้เฉย ๆ (แชท / dashboard ที่รีเฟรชเอง) ไม่นับว่าใช้งาน</div>
        </div>
        <div class="col-md-5">
          <h3 class="card-title"><i class="ti ti-device-mobile me-1"></i>2FA (แอป Authenticator)</h3>
          <div class="form-label">บังคับใช้กับ profile</div>
          <?php foreach ($profiles as $p) { ?>
            <label class="form-check">
              <input class="form-check-input" type="checkbox" name="tfa_profiles[]" value="<?= (int) $p['id'] ?>" <?= (int) $p['2fa_enforced'] === 1 ? 'checked' : '' ?>>
              <span class="form-check-label"><?= $h($p['name']) ?> <span class="text-secondary small">(<?= $p['interface'] === 'central' ? 'ช่าง / admin' : 'ผู้ใช้' ?>)</span></span>
            </label>
          <?php } ?>
        </div>
        <div class="col-md-3">
          <h3 class="card-title">&nbsp;</h3>
          <label class="form-label" for="itsecurity-grace">ระยะผ่อนผัน (วัน)</label>
          <input class="form-control" type="number" min="0" max="90" id="itsecurity-grace" name="grace_days" value="<?= (int) $CFG_GLPI['2fa_grace_days'] ?>">
          <div class="form-hint">ช่วงที่ยังข้ามการตั้ง 2FA ได้ <?= $grace_left > 0 ? "(เหลือ $grace_left วัน)" : '' ?></div>
        </div>
      </div>
    </div>
    <div class="card-footer text-end"><button type="submit" name="save" value="1" class="btn btn-primary">บันทึก</button></div>
  </form>

  <div class="card">
    <div class="card-header"><h3 class="card-title">ช่าง / admin และสถานะ 2FA</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table itsecurity-users">
        <thead><tr><th>ผู้ใช้</th><th>Profile</th><th>บังคับ</th><th>2FA</th><th class="text-end"></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u) { ?>
          <tr data-user="<?= $h($u['name']) ?>">
            <td><?= $h($u['name']) ?> <span class="text-secondary"><?= $h($u['full']) ?></span></td>
            <td><?= $h(implode(', ', array_unique($u['profiles']))) ?></td>
            <td><?= $u['enforced'] ? 'ใช่' : '-' ?></td>
            <td><?php if ($u['enabled']) { ?><span class="badge bg-green-lt"><i class="ti ti-check"></i> ตั้งแล้ว</span><?php } elseif ($u['enforced']) { ?><span class="badge bg-orange-lt">ยังไม่ตั้ง</span><?php } else { ?><span class="text-secondary">ไม่ได้ใช้</span><?php } ?></td>
            <td class="text-end">
              <?php if ($u['enabled']) { ?>
              <form method="post" class="d-inline" onsubmit="return confirm('รีเซ็ต 2FA ของ <?= $h($u['name']) ?>? (เช่น ทำโทรศัพท์หาย)');">
                <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="reset_2fa" value="<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-danger">รีเซ็ต 2FA</button>
              </form>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
Html::footer();
