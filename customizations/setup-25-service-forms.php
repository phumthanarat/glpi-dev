<?php

/**
 * Three more Service Catalog forms (Helpdesk > Service catalog), so requests arrive with what
 * the technician needs instead of a back-and-forth:
 *
 *   ขออุปกรณ์ใหม่ (new equipment)   -> Request, category Hardware        -> manager approval, Helpdesk
 *   แจ้งพนักงานเข้าใหม่ (new employee) -> Request, category "New employee" -> Helpdesk, with a
 *                                       5-task checklist (task templates), no manager approval
 *   ขอสิทธิ์เข้าใช้ระบบ (system access) -> Request, category "Access request" -> manager approval, Helpdesk
 *
 * Teams / SLA / approval come from the existing business rules (setup-05, -08, -17); this
 * script adds the two categories, the onboarding task templates, and excludes "New employee"
 * from the manager-approval rule (the manager or HR is the one filing it).
 * Idempotent: a form that already exists (by name) is left alone - edit it in Administration >
 * Forms; delete it there to have it recreated.
 *
 * Run inside the app container:
 *   php customizations/setup-25-service-forms.php
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Super-Admin session for this CLI run, without a password (see setup-01 for why).
$auth = new Auth();
$auth->user = new User();
if (PHP_SAPI !== 'cli' || !$auth->user->getFromDBbyName('glpi')) {
    fwrite(STDERR, "Run from the command line in the GLPI container ('glpi' user needed).\n");
    exit(1);
}
$auth->auth_succeded = true;
$auth->user_present  = true;
Session::init($auth);

use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeDateTime;
use Glpi\Form\QuestionType\QuestionTypeDropdown;
use Glpi\Form\QuestionType\QuestionTypeLongText;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use Glpi\Form\QuestionType\QuestionTypeRadio;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Form\Section;

global $DB;

/** ITIL category by complete name, created under its parent if missing. */
function category(string $parent, string $name, string $comment): int
{
    $cat = new ITILCategory();
    if ($cat->getFromDBByCrit(['completename' => "$parent > $name"])) {
        return $cat->getID();
    }
    $p = new ITILCategory();
    if (!$p->getFromDBByCrit(['completename' => $parent])) {
        throw new RuntimeException("category $parent not found (run setup-03 first)");
    }
    $id = $cat->add(['name' => $name, 'itilcategories_id' => $p->getID(), 'entities_id' => 0, 'is_recursive' => 1,
        'is_request' => 1, 'is_incident' => 0, 'is_helpdeskvisible' => 1, 'comment' => $comment]);
    echo "Category $parent > $name created\n";
    return $id;
}

/** Answer tag for titles: shows the answer to a question in the ticket title. */
function tag(int $question_id, string $label): string
{
    return sprintf(
        '<span data-form-tag="true" data-form-tag-value="%d" data-form-tag-provider="Glpi\\Form\\Tag\\AnswerTagProvider" class="border-teal border-start border-3 bg-dark-lt">#Answer: %s</span>',
        $question_id,
        htmlescape($label)
    );
}

/** Creates a form: questions ['key' => [label, type class, mandatory, extra, description]] + ticket settings. */
function make_form(string $name, string $description, array $questions, callable $destination): void
{
    $form = new Form();
    if ($form->getFromDBByCrit(['name' => $name])) {
        echo "Form '$name' exists (#{$form->getID()}), left as is\n";
        return;
    }
    $form_id = $form->add(['name' => $name, 'description' => $description, 'entities_id' => 0, 'is_recursive' => 1,
        'is_active' => 1, 'illustration' => 'request-service', 'header' => '']);
    $section = new Section();
    $section->getFromDBByCrit(['forms_forms_id' => $form_id]);
    $ids = [];
    $rank = 0;
    foreach ($questions as $key => [$label, $type, $mandatory, $extra, $help]) {
        $ids[$key] = (new Question())->add([
            'forms_sections_id'   => $section->getID(),
            'forms_sections_uuid' => $section->fields['uuid'],
            'name'                => $label,
            'type'                => $type,
            'is_mandatory'        => $mandatory ? 1 : 0,
            'vertical_rank'       => $rank++,
            'description'         => $help,
            'extra_data'          => $extra === null ? null : json_encode($extra),
            'default_value'       => $key === 'qty' ? '1' : null,
        ]);
    }
    $dest = new FormDestination();
    $dest->getFromDBByCrit(['forms_forms_id' => $form_id, 'itemtype' => FormDestinationTicket::class]);
    $config = array_merge(json_decode((string) $dest->fields['config'], true) ?: [], $destination($ids));
    $dest->update(['id' => $dest->getID(), 'config' => json_encode($config)]);
    echo "Form '$name' created (#$form_id, " . count($ids) . " questions)\n";
}

$options = static fn(array $labels) => ['options' => array_combine(range(1, count($labels)), $labels)];
$request = ['strategy' => 'specific_value', 'specific_question_id' => null, 'specific_request_type' => Ticket::DEMAND_TYPE];
$category = static fn(int $id) => ['strategy' => 'specific_value', 'specific_question_id' => null, 'specific_itilcategory_id' => $id];
$date_only = ['is_default_value_current_time' => false, 'is_date_enabled' => true, 'is_time_enabled' => false];
$K = static fn(string $field) => 'glpi-form-destination-commonitilfield-' . $field;

$hardware   = (int) ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_itilcategories', 'WHERE' => ['completename' => 'IT Support > Hardware']])->current()['id'] ?? 0);
$onboarding = category('IT Support > Account', 'New employee', 'Onboarding: accounts, equipment, handover (form แจ้งพนักงานเข้าใหม่)');
$access     = category('IT Support > Account', 'Access request', 'Access to a system / shared folder (form ขอสิทธิ์เข้าใช้ระบบ)');
if (!$hardware) {
    fwrite(STDERR, "category IT Support > Hardware not found (run setup-03 first)\n");
    exit(1);
}

// onboarding checklist = task templates added to the ticket
$tasks = [];
foreach ([
    '1. สร้างบัญชี login (AD) และตั้งรหัสผ่านเริ่มต้น',
    '2. สร้างอีเมล และเพิ่มเข้ากลุ่มอีเมลของแผนก',
    '3. เตรียมเครื่อง (ตามที่ขอในฟอร์ม) และลงทะเบียนในระบบ',
    '4. ติดตั้งโปรแกรม / สิทธิ์ระบบที่ขอ (VPN, ERP, ...)',
    '5. ส่งมอบเครื่องและแนะนำการใช้งาน / ช่องทางแจ้งปัญหา IT',
] as $task_name) {
    $tt = new TaskTemplate();
    if (!$tt->getFromDBByCrit(['name' => "Onboarding: $task_name"])) {
        $tt->add(['name' => "Onboarding: $task_name", 'content' => "<p>$task_name</p>", 'entities_id' => 0, 'is_recursive' => 1]);
    }
    $tasks[] = $tt->getID();
}

make_form(
    'ขออุปกรณ์ใหม่',
    'ขอคอมพิวเตอร์ จอ เครื่องพิมพ์ หรืออุปกรณ์อื่น (ต้องผ่านการอนุมัติของหัวหน้า)',
    [
        'type'   => ['ประเภทอุปกรณ์', QuestionTypeDropdown::class, true, $options(['Notebook', 'Desktop PC', 'จอภาพ', 'เครื่องพิมพ์', 'เมาส์ / คีย์บอร์ด', 'อื่น ๆ (ระบุในรายละเอียด)']) + ['is_multiple_dropdown' => false], null],
        'qty'    => ['จำนวน', QuestionTypeNumber::class, true, null, null],
        'for'    => ['สำหรับใคร', QuestionTypeShortText::class, false, null, 'เว้นว่างถ้าขอให้ตัวเอง'],
        'date'   => ['ต้องการภายในวันที่', QuestionTypeDateTime::class, false, $date_only, null],
        'reason' => ['เหตุผล / รายละเอียด', QuestionTypeLongText::class, true, null, 'เช่น เครื่องเดิมเสีย, พนักงานใหม่, สเปกที่ต้องการ'],
    ],
    static fn($q) => [
        $K('titlefield')        => ['value' => 'ขออุปกรณ์ใหม่: ' . tag($q['type'], 'ประเภทอุปกรณ์') . ' × ' . tag($q['qty'], 'จำนวน')],
        $K('contentfield_auto') => true,
        $K('requesttypefield')  => $request,
        $K('itilcategoryfield') => $category($hardware),
    ]
);

make_form(
    'แจ้งพนักงานเข้าใหม่',
    'เตรียมบัญชี อีเมล และเครื่องให้พนักงานใหม่ก่อนวันเริ่มงาน (แจ้งล่วงหน้าอย่างน้อย 5 วันทำการ)',
    [
        'name'     => ['ชื่อ-นามสกุล', QuestionTypeShortText::class, true, null, null],
        'position' => ['ตำแหน่ง', QuestionTypeShortText::class, true, null, null],
        'dept'     => ['แผนก', QuestionTypeShortText::class, true, null, null],
        'start'    => ['วันเริ่มงาน', QuestionTypeDateTime::class, true, $date_only, null],
        'manager'  => ['หัวหน้างาน', QuestionTypeShortText::class, false, null, null],
        'devices'  => ['อุปกรณ์ที่ต้องใช้', QuestionTypeCheckbox::class, false, $options(['Notebook', 'Desktop PC', 'จอเสริม', 'โทรศัพท์', 'ไม่ต้องการ']), null],
        'accounts' => ['บัญชี / ระบบที่ต้องใช้', QuestionTypeCheckbox::class, false, $options(['Login (AD)', 'อีเมล', 'VPN', 'ERP', 'ระบบอื่น (ระบุในหมายเหตุ)']), null],
        'note'     => ['หมายเหตุ', QuestionTypeLongText::class, false, null, null],
    ],
    static fn($q) => [
        $K('titlefield')        => ['value' => 'พนักงานใหม่: ' . tag($q['name'], 'ชื่อ-นามสกุล') . ' (' . tag($q['dept'], 'แผนก') . ') เริ่ม ' . tag($q['start'], 'วันเริ่มงาน')],
        $K('contentfield_auto') => true,
        $K('requesttypefield')  => $request,
        $K('itilcategoryfield') => $category($onboarding),
        $K('itiltaskfield')     => ['strategy' => 'specific_values', 'tasktemplate_ids' => $tasks],
    ]
);

make_form(
    'ขอสิทธิ์เข้าใช้ระบบ',
    'ขอสิทธิ์เข้าโฟลเดอร์ / ระบบงาน / VPN (ต้องผ่านการอนุมัติของหัวหน้า)',
    [
        'system' => ['ระบบ', QuestionTypeDropdown::class, true, $options(['File server / โฟลเดอร์กลาง', 'VPN', 'ERP', 'อีเมลกลุ่ม / Shared mailbox', 'ระบบอื่น']) + ['is_multiple_dropdown' => false], null],
        'detail' => ['ชื่อระบบ / โฟลเดอร์', QuestionTypeShortText::class, false, null, 'เช่น \\\\fileserver\\บัญชี'],
        'level'  => ['ระดับสิทธิ์', QuestionTypeRadio::class, true, $options(['อ่านอย่างเดียว', 'อ่าน / แก้ไข', 'ผู้ดูแล (admin)']), null],
        'for'    => ['สำหรับใคร', QuestionTypeShortText::class, false, null, 'เว้นว่างถ้าขอให้ตัวเอง'],
        'from'   => ['ใช้ตั้งแต่', QuestionTypeDateTime::class, false, $date_only, null],
        'until'  => ['ถึงวันที่', QuestionTypeDateTime::class, false, $date_only, 'เว้นว่าง = ใช้ต่อเนื่อง'],
        'reason' => ['เหตุผล', QuestionTypeLongText::class, true, null, null],
    ],
    static fn($q) => [
        $K('titlefield')        => ['value' => 'ขอสิทธิ์: ' . tag($q['system'], 'ระบบ') . ' (' . tag($q['level'], 'ระดับสิทธิ์') . ')'],
        $K('contentfield_auto') => true,
        $K('requesttypefield')  => $request,
        $K('itilcategoryfield') => $category($access),
    ]
);

// "New employee" requests are filed by the manager / HR: no manager approval for them
$rule = new RuleTicket();
if ($rule->getFromDBByCrit(['name' => 'Approval: Request type requires manager approval', 'sub_type' => RuleTicket::class])) {
    $crit = new RuleCriteria();
    if (!$crit->getFromDBByCrit(['rules_id' => $rule->getID(), 'criteria' => 'itilcategories_id', 'pattern' => $onboarding])) {
        $crit->add(['rules_id' => $rule->getID(), 'criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_IS_NOT, 'pattern' => $onboarding]);
        echo "Approval rule #{$rule->getID()}: New employee excluded\n";
    }
}
echo "Done.\n";
