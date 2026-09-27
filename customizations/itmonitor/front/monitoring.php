<?php

/**
 * Setup > Monitoring: status of glpi-monitor's checks, alert channels (Secret glpi-monitor),
 * test alert. Config update right only. Tokens / webhook URLs are shown masked; an empty
 * field keeps the stored value, "-" clears it.
 */

use Glpi\Event;
use GlpiPlugin\Itmonitor\Kube;

global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$self    = $CFG_GLPI['root_doc'] . '/plugins/itmonitor/front/monitoring.php';
$flash   = static fn(string $msg, int $type = INFO) => Session::addMessageAfterRedirect(htmlescape($msg), false, $type);
$h       = static fn($v) => htmlescape((string) $v);
$MONITOR = 'http://glpi-monitor:8080';
$SECRET_FIELDS = ['ALERT_TEAMS_URL', 'ALERT_GCHAT_URL', 'ALERT_SLACK_URL', 'ALERT_LINE_TOKEN', 'ALERT_WEBHOOK_URL', 'SMTP_PASSWORD'];
$PLAIN_FIELDS  = ['ALERT_EMAILS', 'ALERT_LINE_TO', 'HEARTBEAT_HOUR', 'INTERVAL', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_FROM'];

$call = static function (string $method, string $path, array $headers = []) use ($MONITOR): array {
    $ch = curl_init($MONITOR . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true)];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conf = Kube::getSecret('glpi-monitor');
        if (isset($_POST['save'])) {
            $new = [];
            foreach ($PLAIN_FIELDS as $k) {
                $new[$k] = trim((string) ($_POST[$k] ?? ''));
            }
            foreach ($SECRET_FIELDS as $k) {
                $v = trim((string) ($_POST[$k] ?? ''));
                if ($v === '-') {
                    $new[$k] = '';
                } elseif ($v !== '') {
                    $new[$k] = $v;
                }
            }
            foreach (array_filter(array_map('trim', explode(',', $new['ALERT_EMAILS']))) as $mail) {
                if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException("อีเมลไม่ถูกต้อง: $mail");
                }
            }
            foreach (['ALERT_TEAMS_URL', 'ALERT_GCHAT_URL', 'ALERT_SLACK_URL', 'ALERT_WEBHOOK_URL'] as $k) {
                if (($new[$k] ?? '') !== '' && !preg_match('#^https?://\S+$#', $new[$k])) {
                    throw new RuntimeException("URL ไม่ถูกต้อง ($k)");
                }
            }
            if ($new['HEARTBEAT_HOUR'] !== '' && !preg_match('/^([01]?\d|2[0-3])$/', $new['HEARTBEAT_HOUR'])) {
                throw new RuntimeException('ชั่วโมงรายงานประจำวันต้องเป็น 0-23 หรือเว้นว่าง');
            }
            $new['INTERVAL'] = (string) max(10, min(3600, (int) ($new['INTERVAL'] ?: 60)));
            Kube::patchSecret('glpi-monitor', $new);
            Event::log(0, 'system', 3, 'setup', sprintf('%s changed the monitoring alerts', $_SESSION['glpiname']));
            $flash('บันทึกแล้ว ระบบตรวจจะใช้ค่าใหม่ภายในราว 1–2 นาที');
        } elseif (isset($_POST['test'])) {
            [$code, $res] = $call('POST', '/test', ['X-Monitor-Token: ' . ($conf['MONITOR_TOKEN'] ?? '')]);
            if ($code !== 200) {
                throw new RuntimeException($res['error'] ?? "ระบบตรวจตอบ HTTP $code");
            }
            $bad = array_filter($res, static fn($r) => $r !== 'ok');
            $flash('ส่งทดสอบแล้ว: ' . implode(', ', array_map(static fn($k, $v) => "$k " . ($v === 'ok' ? '✔' : "✘ ($v)"), array_keys($res), $res)), $bad ? WARNING : INFO);
        }
    } catch (\Throwable $e) {
        $flash($e->getMessage(), ERROR);
    }
    Html::redirect($self);
}

$conf = [];
$error = null;
try {
    $conf = Kube::getSecret('glpi-monitor');
} catch (\Throwable $e) {
    $error = $e->getMessage();
}
[$code, $status] = $call('GET', '/status');
$checks = $status['checks'] ?? [];
$bad = array_filter($checks, static fn($c) => !$c['ok']);
$mask = static fn(string $v) => $v === '' ? '' : (strlen($v) > 16 ? substr($v, 0, 12) . '…' . substr($v, -4) : '••••');
$names = ['app' => 'GLPI', 'db' => 'ฐานข้อมูล', 'crontasks' => 'งาน cron', 'mail_collectors' => 'รับอีเมล', 'filesystem' => 'พื้นที่ไฟล์',
    'https' => 'HTTPS', 'backup' => 'Backup', 'backup_share' => 'Backup → file share'];

Html::header('Monitoring', '', 'config', \GlpiPlugin\Itmonitor\Menu::class);
$csrf = $h(Session::getNewCSRFToken());
?>
<div class="container-fluid itmonitor-page" style="max-width: 1200px">
  <h2 class="mb-3"><i class="ti ti-heartbeat me-1"></i>Monitoring</h2>
  <?php if ($code !== 200) { ?>
    <div class="alert alert-danger itmonitor-health">ติดต่อระบบตรวจ (glpi-monitor) ไม่ได้: ไม่มีการแจ้งเตือนจนกว่าจะกลับมา</div>
  <?php } elseif ($bad) { ?>
    <div class="alert alert-danger itmonitor-health">มีปัญหา <?= count($bad) ?> เรื่อง (แจ้งเตือนแล้วเมื่อพบซ้ำ 2 รอบ)</div>
  <?php } else { ?>
    <div class="alert alert-success itmonitor-health">ปกติทุกอย่าง · ตรวจทุก <?= (int) ($status['interval'] ?? 60) ?> วินาที · รอบล่าสุด <?= $h(substr((string) ($status['last_round'] ?? ''), 11, 8)) ?></div>
  <?php } ?>
  <?php if (($status['channels'] ?? []) === [] && $code === 200) { ?>
    <div class="alert alert-warning">ยังไม่ได้ตั้งช่องทางแจ้งเตือน: ระบบตรวจอยู่ แต่จะไม่มีใครได้รับแจ้ง</div>
  <?php } ?>

  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title">สิ่งที่ตรวจ</h3></div>
        <table class="table card-table itmonitor-checks">
          <?php foreach ($checks as $k => $c) { ?>
            <tr data-check="<?= $h($k) ?>"><td><?= $h($names[$k] ?? $k) ?></td>
              <td><?= $c['ok'] ? '<span class="badge bg-green-lt">ปกติ</span>' : '<span class="badge bg-red-lt">ปัญหา</span>' ?></td>
              <td class="small text-secondary"><?= $h($c['detail'] ?? '') ?></td></tr>
          <?php } ?>
        </table>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header"><h3 class="card-title">การแจ้งเตือนล่าสุด</h3></div>
        <table class="table card-table itmonitor-sent">
          <?php foreach (array_slice($status['sent'] ?? [], 0, 8) as $s) { ?>
            <tr><td class="small"><?= $h(substr($s['at'], 0, 16)) ?></td><td><?= $h($s['title']) ?></td>
              <td class="small"><?php foreach ($s['results'] as $ch => $r) { ?><span class="badge <?= $r === 'ok' ? 'bg-green-lt' : 'bg-red-lt' ?> me-1" title="<?= $h($r) ?>"><?= $h($ch) ?></span><?php } ?></td></tr>
          <?php } ?>
          <?php if (empty($status['sent'])) { ?><tr><td class="text-secondary">ยังไม่มี</td></tr><?php } ?>
        </table>
      </div>
    </div>
  </div>

  <form method="post" class="card itmonitor-form">
    <div class="card-header"><h3 class="card-title">ส่งแจ้งเตือนไปที่</h3></div>
    <div class="card-body">
      <input type="hidden" name="_glpi_csrf_token" value="<?= $csrf ?>">
      <?php if ($error) { ?><div class="alert alert-danger"><?= $h($error) ?></div><?php } ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">อีเมล (คั่นด้วย ,)</label>
          <input class="form-control" name="ALERT_EMAILS" value="<?= $h($conf['ALERT_EMAILS'] ?? '') ?>" placeholder="it-team@company.com"></div>
        <div class="col-md-3"><label class="form-label">รายงานประจำวัน (ชั่วโมง)</label>
          <input class="form-control" name="HEARTBEAT_HOUR" value="<?= $h($conf['HEARTBEAT_HOUR'] ?? '') ?>" placeholder="8 (ว่าง = ไม่ส่ง)"></div>
        <div class="col-md-3"><label class="form-label">ตรวจทุก (วินาที)</label>
          <input class="form-control" type="number" min="10" max="3600" name="INTERVAL" value="<?= $h($conf['INTERVAL'] ?? '60') ?>"></div>
        <?php foreach (['ALERT_TEAMS_URL' => 'Microsoft Teams (Workflows webhook URL)', 'ALERT_GCHAT_URL' => 'Google Chat (webhook URL)',
                        'ALERT_SLACK_URL' => 'Slack (Incoming webhook URL)', 'ALERT_WEBHOOK_URL' => 'Webhook อื่น (JSON POST)',
                        'ALERT_LINE_TOKEN' => 'LINE Messaging API: channel access token'] as $k => $label) { ?>
          <div class="col-md-6"><label class="form-label"><?= $h($label) ?></label>
            <input class="form-control" name="<?= $k ?>" value="" autocomplete="off" placeholder="<?= $h(($conf[$k] ?? '') !== '' ? $mask($conf[$k]) . ' (ว่าง = คงเดิม, - = ลบ)' : '') ?>"></div>
        <?php } ?>
        <div class="col-md-6"><label class="form-label">LINE: ส่งถึง (user / group id)</label>
          <input class="form-control" name="ALERT_LINE_TO" value="<?= $h($conf['ALERT_LINE_TO'] ?? '') ?>"></div>
      </div>
      <details class="mt-3"><summary class="text-secondary">SMTP ของระบบตรวจ (ใช้ส่งอีเมลได้แม้ GLPI ล่ม)</summary>
        <div class="row g-3 mt-1">
          <div class="col-md-4"><label class="form-label">SMTP host</label><input class="form-control" name="SMTP_HOST" value="<?= $h($conf['SMTP_HOST'] ?? '') ?>"></div>
          <div class="col-md-2"><label class="form-label">Port</label><input class="form-control" name="SMTP_PORT" value="<?= $h($conf['SMTP_PORT'] ?? '587') ?>"></div>
          <div class="col-md-3"><label class="form-label">ผู้ใช้</label><input class="form-control" name="SMTP_USER" value="<?= $h($conf['SMTP_USER'] ?? '') ?>" autocomplete="off"></div>
          <div class="col-md-3"><label class="form-label">รหัสผ่าน</label><input class="form-control" type="password" name="SMTP_PASSWORD" value="" autocomplete="new-password" placeholder="<?= ($conf['SMTP_PASSWORD'] ?? '') !== '' ? '•••• (ว่าง = คงเดิม)' : '' ?>"></div>
          <div class="col-md-6"><label class="form-label">ผู้ส่ง (From)</label><input class="form-control" name="SMTP_FROM" value="<?= $h($conf['SMTP_FROM'] ?? '') ?>"></div>
        </div>
      </details>
    </div>
    <div class="card-footer d-flex gap-2 justify-content-end">
      <button type="submit" name="test" value="1" class="btn btn-outline-primary"><i class="ti ti-send me-1"></i>ส่งทดสอบ</button>
      <button type="submit" name="save" value="1" class="btn btn-primary">บันทึก</button>
    </div>
  </form>
  <p class="text-secondary small mt-2">ระบบตรวจรันแยกจาก GLPI จึงแจ้งได้แม้ GLPI ล่ม แต่ถ้าเครื่อง / cluster ดับทั้งหมดจะแจ้งไม่ได้: ถ้ารายงานประจำวันไม่มาตามเวลา แปลว่ามีปัญหา</p>
</div>
<?php
Html::footer();
