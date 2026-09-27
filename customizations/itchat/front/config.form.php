<?php

/**
 * IT Chat settings (Setup > Plugins > IT Chat, wrench icon). Admins only.
 * Everything is stored in glpi_configs, context "plugin:itchat"; defaults live in
 * plugin_itchat_config() (setup.php).
 */

global $CFG_GLPI, $DB;

Session::checkRight('config', UPDATE);

$context = 'plugin:itchat';
$self    = '/plugins/itchat/front/config.form.php';
$flash   = static fn(string $msg, int $type = INFO) => Session::addMessageAfterRedirect(htmlescape($msg), false, $type);

if (isset($_POST['save']) || isset($_POST['test'])) {
    $url = trim((string) ($_POST['google_chat_webhook_url'] ?? ''));
    if ($url !== '' && !str_starts_with($url, 'https://chat.googleapis.com/')) {
        $flash('URL ต้องขึ้นต้นด้วย https://chat.googleapis.com/', ERROR);
        Html::redirect($CFG_GLPI['root_doc'] . $self);
    }
    $calendar_id = (int) ($_POST['calendars_id'] ?? 0);
    if ($calendar_id > 0 && !(new Calendar())->getFromDB($calendar_id)) {
        $calendar_id = 0;
    }
    // one reply per line, trimmed, empty lines dropped, 300 chars max each, 30 max
    $canned = array_slice(array_values(array_filter(array_map(
        static fn($l) => mb_substr(trim($l), 0, 300),
        explode("\n", str_replace("\r", '', (string) ($_POST['canned_replies'] ?? '')))
    ))), 0, 30);

    Config::setConfigurationValues($context, [
        'google_chat_webhook_url' => $url,
        'calendars_id'            => $calendar_id,
        'offhours_message'        => mb_substr(trim((string) ($_POST['offhours_message'] ?? '')), 0, 1000),
        'idle_close_hours'        => max(0, min(720, (int) ($_POST['idle_close_hours'] ?? 24))),
        'retention_days'          => max(0, min(3650, (int) ($_POST['retention_days'] ?? 90))),
        'canned_replies'          => implode("\n", $canned),
    ]);

    if (isset($_POST['test'])) {
        if ($url === '') {
            $flash('บันทึกแล้ว แต่ยังไม่ได้ใส่ Google Chat URL', WARNING);
        } else {
            $error = plugin_itchat_post_google_chat($url, '✅ ทดสอบการแจ้งเตือนจาก GLPI IT Chat');
            $flash($error === null ? 'บันทึกแล้ว และส่งข้อความทดสอบเข้า Google Chat แล้ว' : 'บันทึกแล้ว แต่ส่งไม่สำเร็จ: ' . $error, $error === null ? INFO : ERROR);
        }
    } else {
        $flash('บันทึกแล้ว');
    }
    Html::redirect($CFG_GLPI['root_doc'] . $self);
}

$conf = plugin_itchat_config();
$h    = static fn($v) => htmlescape((string) $v);

$calendar_options = '<option value="0">— ไม่ใช้ (ไม่ตอบอัตโนมัติ) —</option>';
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_calendars', 'ORDER' => 'name']) as $c) {
    $calendar_options .= sprintf(
        '<option value="%d"%s>%s</option>',
        $c['id'],
        (int) $c['id'] === $conf['calendars_id'] ? ' selected' : '',
        $h($c['name'])
    );
}
$in_hours = plugin_itchat_offhours_reply() === null;

$cron = new CronTask();
$cron_line = $cron->getFromDBbyName(\GlpiPlugin\Itchat\Maintenance::class, \GlpiPlugin\Itchat\Maintenance::TASK)
    ? sprintf(
        'Automatic action <b>%s</b>: ทุก %d นาที · รันล่าสุด %s',
        $h($cron->fields['name']),
        $cron->fields['frequency'] / MINUTE_TIMESTAMP,
        $cron->fields['lastrun'] ? $h(Html::convDateTime($cron->fields['lastrun'])) : 'ยังไม่เคยรัน'
    )
    : '<span class="text-danger">ยังไม่ได้ลงทะเบียน Automatic action (รัน plugin:install ใหม่)</span>';

Html::header('IT Chat', '', 'config', 'plugin');

echo '<div class="container-fluid" style="max-width: 860px">';
echo '<form method="post" action="' . $h($CFG_GLPI['root_doc'] . $self) . '">';

// --- notifications
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-brand-google me-2"></i>แจ้งเตือนแชทใหม่เข้า Google Chat</h3></div><div class="card-body">';
echo '<label class="form-label" for="gc">Google Chat webhook URL</label>';
echo '<input type="url" class="form-control" id="gc" name="google_chat_webhook_url" autocomplete="off" placeholder="https://chat.googleapis.com/v1/spaces/.../messages?key=...&amp;token=..." value="' . $h($conf['google_chat_webhook_url']) . '">';
echo '<div class="form-hint mt-2">เว้นว่าง = ปิด · ต้องใช้บัญชี Google Workspace: Space → ชื่อ Space → <b>Apps &amp; integrations</b> → <b>Webhooks</b> · URL นี้เป็นความลับ</div>';
echo '</div></div>';

// --- off-hours
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-clock-off me-2"></i>ตอบกลับอัตโนมัตินอกเวลาทำการ</h3></div><div class="card-body">';
echo '<div class="row g-3"><div class="col-md-5"><label class="form-label" for="cal">ปฏิทินเวลาทำการ</label>';
echo '<select class="form-select" id="cal" name="calendars_id">' . $calendar_options . '</select>';
echo '<div class="form-hint mt-1">ตอนนี้: ' . ($in_hours ? 'อยู่ในเวลาทำการ (หรือปิดใช้งาน)' : '<b>นอกเวลาทำการ</b>') . ' · แก้เวลาได้ที่ Setup &gt; Calendars</div></div>';
echo '<div class="col-md-7"><label class="form-label" for="off">ข้อความตอบกลับ (ส่งเมื่อผู้ใช้เริ่มแชทใหม่นอกเวลา)</label>';
echo '<textarea class="form-control" id="off" name="offhours_message" rows="3">' . $h($conf['offhours_message']) . '</textarea></div></div>';
echo '</div></div>';

// --- housekeeping
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-broom me-2"></i>ปิดและลบแชทอัตโนมัติ</h3></div><div class="card-body">';
echo '<div class="row g-3"><div class="col-md-6"><label class="form-label" for="idle">ปิดแชทที่ไม่มีความเคลื่อนไหวเกิน (ชั่วโมง)</label>';
echo '<input type="number" min="0" max="720" class="form-control" id="idle" name="idle_close_hours" value="' . $conf['idle_close_hours'] . '"><div class="form-hint mt-1">0 = ไม่ปิดอัตโนมัติ</div></div>';
echo '<div class="col-md-6"><label class="form-label" for="ret">ลบแชทที่ปิดแล้วเกิน (วัน)</label>';
echo '<input type="number" min="0" max="3650" class="form-control" id="ret" name="retention_days" value="' . $conf['retention_days'] . '"><div class="form-hint mt-1">0 = เก็บตลอด · ไฟล์ที่แนบเข้า Ticket แล้วจะไม่ถูกลบ</div></div></div>';
echo '<div class="form-hint mt-3">' . $cron_line . '</div>';
echo '</div></div>';

// --- canned replies
echo '<div class="card mb-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-message-2-bolt me-2"></i>ข้อความสำเร็จรูปของช่าง</h3></div><div class="card-body">';
echo '<textarea class="form-control" name="canned_replies" rows="7">' . $h(implode("\n", $conf['canned_replies'])) . '</textarea>';
echo '<div class="form-hint mt-2">บรรทัดละ 1 ข้อความ (สูงสุด 30) · ช่างกดปุ่ม <i class="ti ti-message-2-bolt"></i> ในแชทเพื่อเลือก แล้วแก้ก่อนส่งได้</div>';
echo '</div></div>';

echo '<div class="d-flex gap-2 mb-4">';
echo '<button type="submit" name="save" value="1" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>' . __s('Save') . '</button>';
echo '<button type="submit" name="test" value="1" class="btn btn-outline-secondary"><i class="ti ti-send me-1"></i>บันทึกและส่งข้อความทดสอบเข้า Google Chat</button>';
echo '</div>';
Html::closeForm();
echo '</div>';

Html::footer();
