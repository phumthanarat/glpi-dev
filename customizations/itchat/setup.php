<?php

/**
 * IT Chat - live chat between end users and technicians, inside GLPI.
 *
 * Adds a floating chat button to every page (central + self-service).
 * - Users with a "technician" profile (see plugin_itchat_is_technician())
 *   see an inbox of every conversation and can claim / reply / close /
 *   convert a conversation into a Ticket.
 * - Everyone else gets a single conversation with "IT Support".
 *
 * No core file is touched; see README.md for deploy steps.
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ITCHAT_VERSION', '1.7.0');
// Request source of tickets opened from a chat (created by plugin_itchat_install()).
define('PLUGIN_ITCHAT_REQUEST_TYPE', 'Chat');

function plugin_version_itchat(): array
{
    return [
        'name'         => 'IT Chat',
        'version'      => PLUGIN_ITCHAT_VERSION,
        'author'       => 'IT Dev',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.99.99',
            ],
        ],
    ];
}

function plugin_init_itchat(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['itchat'] = true;

    // Only for logged-in pages (anonymous login page uses the *_ANONYMOUS_PAGE hooks).
    $assets = plugin_itchat_assets();
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['itchat'] = [$assets['js']];
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['itchat']        = [$assets['css']];

    // Ticket followups -> chat (see plugin_itchat_followup_added()); a technician's profile
    // becomes their default one (see plugin_itchat_profile_added()).
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['itchat'] = [
        ITILFollowup::class => 'plugin_itchat_followup_added',
        Profile_User::class => 'plugin_itchat_profile_added',
    ];
    // Dashboard cards (satisfaction, chats today, waiting chats).
    $PLUGIN_HOOKS[Hooks::DASHBOARD_CARDS]['itchat'] = 'plugin_itchat_dashboard_cards';

    // Setup > Plugins > IT Chat (wrench icon): Google Chat notification settings.
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['itchat'] = 'front/config.form.php';
    }
}

/**
 * JS/CSS file names to load. GLPI cache-busts plugin assets with the plugin *version*
 * (served with a 30-day max-age), and bumping the version forces a plugin update step
 * during which the plugin is unloaded. So instead, itchat-dev/deploy.sh publishes
 * content-hashed copies (public/dist/chat.<hash>.js) and writes public/dist/manifest.json;
 * a JS/CSS change then needs no version bump and no restart (this JSON isn't opcached).
 * Falls back to the plain files when no build was deployed.
 *
 * @return array{js: string, css: string}
 */
function plugin_itchat_assets(): array
{
    $assets   = ['js' => 'chat.js', 'css' => 'chat.css'];
    $manifest = __DIR__ . '/public/dist/manifest.json';
    if (is_file($manifest)) {
        $data = json_decode((string) file_get_contents($manifest), true);
        foreach (['js', 'css'] as $k) {
            if (is_string($data[$k] ?? null) && is_file(__DIR__ . '/public/' . $data[$k])) {
                $assets[$k] = $data[$k];
            }
        }
    }
    return $assets;
}

function plugin_itchat_check_prerequisites(): bool
{
    return true;
}

function plugin_itchat_check_config($verbose = false): bool
{
    return true;
}

/**
 * Technician = central (standard) interface AND allowed to see all tickets AND to update them.
 * The update right keeps out look-only central profiles (stock Observer, Read-Only). Self-service
 * users, and central users without these rights, chat as requesters.
 */
function plugin_itchat_is_technician(): bool
{
    return Session::getCurrentInterface() === 'central'
        && Session::haveRight(Ticket::$rightname, Ticket::READALL)
        && Session::haveRight(Ticket::$rightname, UPDATE);
}

/**
 * Plugin settings (Setup > Plugins > IT Chat), with defaults. Stored in glpi_configs, context "plugin:itchat".
 *
 * @return array{google_chat_webhook_url: string, calendars_id: int, offhours_message: string,
 *               idle_close_hours: int, retention_days: int, canned_replies: string[]}
 */
function plugin_itchat_config(): array
{
    global $DB;
    static $default_calendar = null;
    if ($default_calendar === null) {
        // the ITSM rollout's calendar (setup-01-calendar.php), if present
        $default_calendar = (int) ($DB->request([
            'SELECT' => 'id', 'FROM' => 'glpi_calendars', 'WHERE' => ['name' => 'IT Support Business Hours'], 'LIMIT' => 1,
        ])->current()['id'] ?? 0);
    }
    $defaults = [
        'google_chat_webhook_url' => '',
        'calendars_id'            => $default_calendar,
        'offhours_message'        => 'ขอบคุณที่ติดต่อทีม IT ขณะนี้อยู่นอกเวลาทำการ เจ้าหน้าที่จะตอบกลับในเวลาทำการถัดไป',
        'idle_close_hours'        => 24,
        'retention_days'          => 90,
        'canned_replies'          => implode("\n", [
            'สวัสดีครับ ทีม IT รับเรื่องแล้ว กำลังตรวจสอบให้นะครับ',
            'รบกวนลองรีสตาร์ทเครื่องก่อน แล้วแจ้งผลอีกครั้งนะครับ',
            'รบกวนส่งภาพหน้าจอ error ให้ดูหน่อยครับ (กด 📎 หรือวางรูปด้วย Ctrl+V)',
            'รบกวนแจ้งชื่อเครื่อง หรือเลข Asset ที่ติดอยู่บนเครื่องด้วยครับ',
            'เดี๋ยวเปิด Ticket ให้ช่างเข้าไปดูที่หน้างานนะครับ',
            'แก้ไขเรียบร้อยแล้วครับ ถ้ายังมีปัญหาทักมาได้เลยครับ',
        ]),
    ];
    $conf = Config::getConfigurationValues('plugin:itchat') + $defaults;
    return [
        'google_chat_webhook_url' => trim((string) $conf['google_chat_webhook_url']),
        'calendars_id'            => (int) $conf['calendars_id'],
        'offhours_message'        => trim((string) $conf['offhours_message']),
        'idle_close_hours'        => max(0, (int) $conf['idle_close_hours']),
        'retention_days'          => max(0, (int) $conf['retention_days']),
        'canned_replies'          => array_values(array_filter(array_map('trim', explode("\n", (string) $conf['canned_replies'])))),
    ];
}

/**
 * The auto-reply for a chat started outside the configured calendar's working hours,
 * or null when inside working hours / no calendar / no message configured.
 */
function plugin_itchat_offhours_reply(?int $time = null): ?string
{
    $conf = plugin_itchat_config();
    if ($conf['calendars_id'] <= 0 || $conf['offhours_message'] === '') {
        return null;
    }
    $calendar = new Calendar();
    if (!$calendar->getFromDB($conf['calendars_id'])) {
        return null;
    }
    return $calendar->isAWorkingHour($time ?? time()) ? null : $conf['offhours_message'];
}

/**
 * Push "new chat started" to a Google Chat space (incoming webhook), so
 * technicians notice without having GLPI open. No-op until an admin sets
 * the URL on the plugin config page. Failures are logged to
 * files/_log/itchat.log and never block the chat itself.
 */
function plugin_itchat_notify_new_conversation(int $conv_id, string $user_name, string $content): void
{
    global $CFG_GLPI;

    $url = plugin_itchat_config()['google_chat_webhook_url'];
    if ($url === '') {
        return;
    }

    // Google Chat text markup: escape &, <, > so user text can't inject <url|label> links.
    $esc  = static fn(string $s): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    $link = rtrim((string) $CFG_GLPI['url_base'], '/') . '/front/central.php?itchat=' . $conv_id;
    $text = sprintf(
        "💬 *แชทใหม่จาก %s*\n%s\n<%s|เปิดใน GLPI>",
        $esc($user_name),
        $esc(mb_strimwidth($content, 0, 300, '…')),
        $link
    );

    $error = plugin_itchat_post_google_chat($url, $text);
    if ($error !== null) {
        Toolbox::logInFile('itchat', "Google Chat notify failed for conversation #$conv_id: $error\n");
    }
}

/** @return string|null error message, null on success */
function plugin_itchat_post_google_chat(string $url, string $text): ?string
{
    if (!str_starts_with($url, 'https://chat.googleapis.com/') || !Toolbox::isUrlSafe($url)) {
        return 'URL must be a https://chat.googleapis.com/... webhook URL';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=UTF-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        return $err;
    }
    return $code >= 200 && $code < 300 ? null : "HTTP $code: " . mb_substr((string) $body, 0, 300);
}

/**
 * Users who count as technicians for chat transfer: active users having a central-interface
 * profile with the ticket READALL and UPDATE rights (same rule as plugin_itchat_is_technician()).
 *
 * @return array<int, string> id => display name
 */
function plugin_itchat_technicians(): array
{
    global $DB;
    $out = [];
    foreach (
        $DB->request([
            'SELECT'     => ['glpi_users.id'],
            'DISTINCT'   => true,
            'FROM'       => 'glpi_users',
            'INNER JOIN' => [
                'glpi_profiles_users' => ['ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id']],
                'glpi_profiles'       => ['ON' => ['glpi_profiles' => 'id', 'glpi_profiles_users' => 'profiles_id']],
                'glpi_profilerights'  => ['ON' => ['glpi_profilerights' => 'profiles_id', 'glpi_profiles' => 'id']],
            ],
            'WHERE' => [
                'glpi_users.is_active'        => 1,
                'glpi_users.is_deleted'       => 0,
                'glpi_profiles.interface'     => 'central',
                'glpi_profilerights.name'     => Ticket::$rightname,
                new Glpi\DBAL\QueryExpression(sprintf(
                    '(`glpi_profilerights`.`rights` & %1$d) = %1$d',
                    Ticket::READALL | UPDATE
                )),
            ],
        ]) as $row
    ) {
        $name = getUserName((int) $row['id']);
        if ($name !== '') {
            $out[(int) $row['id']] = $name;
        }
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * ITEM_ADD hook: a user given a central (technician-side) profile while their default profile is
 * a Self-Service one - every new GLPI user gets Self-Service as default - gets the new profile as
 * default. Otherwise they keep landing on the Helpdesk at login, and for IT Chat they are a requester,
 * not a technician. A default profile someone chose on purpose (a central one) is left alone.
 */
function plugin_itchat_profile_added(Profile_User $pu): void
{
    global $DB;
    $profile = new Profile();
    $user = new User();
    if (
        !$profile->getFromDB((int) $pu->fields['profiles_id'])
        || $profile->fields['interface'] !== 'central'
        || !$user->getFromDB((int) $pu->fields['users_id'])
    ) {
        return;
    }
    $current = new Profile();
    if ((int) $user->fields['profiles_id'] > 0 && $current->getFromDB((int) $user->fields['profiles_id'])
        && $current->fields['interface'] === 'central') {
        return;
    }
    // Direct SQL: User::update() needs rights the LDAP sync / rules running this may not have.
    $DB->update(User::getTable(), ['profiles_id' => $profile->getID()], ['id' => $user->getID()]);
}

/**
 * ITEM_ADD hook: a public followup added on a ticket that came from a chat is copied into
 * that chat, so the requester sees answers given in the ticket. Followups that were
 * themselves created from chat messages carry `_itchat_from_chat` and are skipped (no loop).
 */
function plugin_itchat_followup_added(ITILFollowup $fup): void
{
    global $DB;
    if (!empty($fup->input['_itchat_from_chat']) || $fup->fields['itemtype'] !== Ticket::class || $fup->fields['is_private']) {
        return;
    }
    $conv = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_plugin_itchat_conversations',
        'WHERE'  => ['tickets_id' => (int) $fup->fields['items_id']],
        'ORDER'  => 'id DESC',
        'LIMIT'  => 1,
    ])->current();
    if ($conv === null) {
        return;
    }
    $text = trim(Glpi\RichText\RichText::getTextFromHtml((string) $fup->fields['content'], false, false, false, true, true));
    if ($text === '') {
        return;
    }
    $now = Session::getCurrentTime();
    $DB->insert('glpi_plugin_itchat_messages', [
        'plugin_itchat_conversations_id' => $conv['id'],
        'users_id'      => (int) $fup->fields['users_id'],
        'content'       => mb_substr('🎫 ' . $text, 0, 2000),
        'date_creation' => $now,
    ]);
    $DB->update('glpi_plugin_itchat_conversations', ['date_mod' => $now], ['id' => $conv['id']]);
}

/** DASHBOARD_CARDS hook. */
function plugin_itchat_dashboard_cards($cards = null): array
{
    $cards = is_array($cards) ? $cards : [];
    $group = 'IT Chat';
    $cards['itchat_csat_30d'] = [
        'widgettype' => ['bigNumber'],
        'group'      => $group,
        'label'      => 'IT Chat: คะแนนความพึงพอใจเฉลี่ย (30 วัน)',
        'provider'   => 'GlpiPlugin\\Itchat\\Dashboard::csat',
    ];
    $cards['itchat_chats_today'] = [
        'widgettype' => ['bigNumber'],
        'group'      => $group,
        'label'      => 'IT Chat: แชทวันนี้',
        'provider'   => 'GlpiPlugin\\Itchat\\Dashboard::chatsToday',
    ];
    $cards['itchat_waiting'] = [
        'widgettype' => ['bigNumber'],
        'group'      => $group,
        'label'      => 'IT Chat: แชทรอรับเรื่อง',
        'provider'   => 'GlpiPlugin\\Itchat\\Dashboard::waiting',
    ];
    return $cards;
}
