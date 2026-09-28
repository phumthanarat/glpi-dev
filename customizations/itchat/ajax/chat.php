<?php

/**
 * IT Chat JSON endpoint: /plugins/itchat/ajax/chat.php
 *
 * GET  action=poll       [conv=<id>] [after=<msg id>]
 * POST action=send       conv=<id|0> content=<text> [file=<upload>]  (conv=0 on user side = start/continue own chat)
 * GET  action=file       msg=<message id>             (download/inline an attachment)
 * POST action=claim      conv=<id>                    (tech)
 * POST action=close      conv=<id>
 * GET  action=ticketform conv=<id>                    (tech: suggested title + categories for the dialog)
 * POST action=toticket   conv=<id> [name, type, itilcategories_id, urgency, problem]  (tech;
 *                        type: 1 = Incident, 2 = Request, 'problem' = Incident linked to a Problem;
 *                        problem: 0 = none, -1 = new Problem from this ticket, <id> = link to that open Problem)
 * GET  action=techs                                   (tech: who a chat can be transferred to)
 * POST action=transfer   conv=<id> users_id=<tech>    (tech; an open ticket of the chat is reassigned too)
 * POST action=rate       conv=<id> value=1..5         (requester, closed chat, once)
 * GET  action=search     q=<text>                     (tech: message text, requester name or ticket #)
 *
 * Login is enforced by GLPI's firewall (legacy plugin scripts default to
 * "authenticated"); CSRF for POST is checked by the kernel from the
 * X-Glpi-Csrf-Token header that GLPI's jQuery ajaxSend hook adds.
 */

use Symfony\Component\HttpFoundation\JsonResponse;

const ITCHAT_CONV = 'glpi_plugin_itchat_conversations';
const ITCHAT_MSG  = 'glpi_plugin_itchat_messages';
const ITCHAT_MAX_LEN = 2000;

global $DB, $CFG_GLPI;

$me      = (int) Session::getLoginUserID();
$is_tech = plugin_itchat_is_technician();
$action  = $_REQUEST['action'] ?? 'poll';
$conv_id = (int) ($_REQUEST['conv'] ?? 0);
$now     = Session::getCurrentTime();

$fail = static function (string $msg, int $code = 400): JsonResponse {
    return new JsonResponse(['error' => $msg], $code);
};

/** Load a conversation the current user is allowed to access, or null. */
$load_conv = static function (int $id) use ($DB, $me, $is_tech): ?array {
    if ($id <= 0) {
        return null;
    }
    $row = $DB->request(['FROM' => ITCHAT_CONV, 'WHERE' => ['id' => $id]])->current();
    if ($row === null || (!$is_tech && (int) $row['users_id'] !== $me)) {
        return null;
    }
    return $row;
};

/** The requester's current open conversation, or null. */
$own_open_conv = static function () use ($DB, $me): ?array {
    return $DB->request([
        'FROM'  => ITCHAT_CONV,
        'WHERE' => ['users_id' => $me, 'status' => 'open'],
        'ORDER' => 'id DESC',
        'LIMIT' => 1,
    ])->current();
};

$add_msg = static function (int $conv, int $author, string $content, int $documents_id = 0) use ($DB, $now): int {
    $DB->insert(ITCHAT_MSG, [
        'plugin_itchat_conversations_id' => $conv,
        'users_id'      => $author,
        'content'       => $content,
        'documents_id'  => $documents_id,
        'date_creation' => $now,
    ]);
    $id = (int) $DB->insertId();
    $DB->update(ITCHAT_CONV, ['date_mod' => $now], ['id' => $conv]);
    return $id;
};

$names = [];
$name_of = static function (int $uid) use (&$names): string {
    if ($uid <= 0) {
        return '';
    }
    return $names[$uid] ??= getUserName($uid);
};

/** Attachment info for the widget (never exposes the GLPI document URL, see action=file). */
$docs = [];
$export_file = static function (int $msg_id, int $documents_id) use (&$docs, $CFG_GLPI): ?array {
    if ($documents_id <= 0) {
        return null;
    }
    if (!array_key_exists($documents_id, $docs)) {
        $doc = new Document();
        $docs[$documents_id] = $doc->getFromDB($documents_id) ? $doc->fields : null;
    }
    $d = $docs[$documents_id];
    if ($d === null) {
        return ['name' => '(ไฟล์ถูกลบแล้ว)', 'url' => '', 'is_image' => false, 'size' => 0];
    }
    $path = GLPI_DOC_DIR . '/' . $d['filepath'];
    $mime = strtolower((string) $d['mime']);
    return [
        'name'     => $d['filename'],
        'url'      => $CFG_GLPI['root_doc'] . '/plugins/itchat/ajax/chat.php?action=file&msg=' . $msg_id,
        // same rule as Toolbox::getFileAsResponse(): images inline, but never SVG
        'is_image' => str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml',
        'size'     => is_file($path) ? filesize($path) : 0,
    ];
};

$export_conv = static function (array $c) use ($name_of): array {
    return [
        'id'         => (int) $c['id'],
        'user'       => $name_of((int) $c['users_id']),
        'tech_id'    => (int) $c['users_id_tech'],
        'tech'       => $name_of((int) $c['users_id_tech']),
        'status'     => $c['status'],
        'tickets_id' => (int) $c['tickets_id'],
        'ticket_url' => $c['tickets_id'] ? Ticket::getFormURLWithID((int) $c['tickets_id']) : '',
        'date_mod'   => $c['date_mod'],
        'satisfaction' => (int) ($c['satisfaction'] ?? 0),
    ];
};

/** Default ticket title: "แชท: " + the requester's first text message. */
$suggest_title = static function (array $conv) use ($DB, $name_of): string {
    $first = $DB->request([
        'SELECT' => 'content',
        'FROM'   => ITCHAT_MSG,
        'WHERE'  => ['plugin_itchat_conversations_id' => $conv['id'], 'users_id' => $conv['users_id'], 'content' => ['<>', '']],
        'ORDER'  => 'id ASC',
        'LIMIT'  => 1,
    ])->current()['content'] ?? '';
    $first = trim(preg_replace('/\s+/u', ' ', (string) $first));
    return 'แชท: ' . ($first !== '' ? mb_strimwidth($first, 0, 70, '…') : $name_of((int) $conv['users_id']));
};

/** Ticket categories the current tech may use, with their incident/request flags. */
$ticket_categories = static function () use ($DB): array {
    $out = [];
    foreach (
        $DB->request([
            'SELECT' => ['id', 'completename', 'is_incident', 'is_request'],
            'FROM'   => ITILCategory::getTable(),
            'WHERE'  => ['OR' => ['is_incident' => 1, 'is_request' => 1]]
                + getEntitiesRestrictCriteria(ITILCategory::getTable(), '', '', true),
            'ORDER'  => 'completename',
        ]) as $c
    ) {
        $out[(int) $c['id']] = [
            'id'       => (int) $c['id'],
            'name'     => $c['completename'],
            'incident' => (bool) $c['is_incident'],
            'request'  => (bool) $c['is_request'],
        ];
    }
    return $out;
};

/**
 * Requester group to put on the ticket, so the "Request needs manager approval" rule can find
 * an approver: the user's default group, else their only group. Null when it can't be decided.
 * @return array{id:int, name:string, has_manager:bool}|null
 */
$requester_group = static function (int $users_id) use ($DB): ?array {
    $user = new User();
    if (!$user->getFromDB($users_id)) {
        return null;
    }
    $member_of = array_map('intval', array_column(iterator_to_array($DB->request([
        'SELECT' => 'groups_id',
        'FROM'   => Group_User::getTable(),
        'WHERE'  => ['users_id' => $users_id],
    ])), 'groups_id'));
    $gid = (int) $user->fields['groups_id'];
    if ($gid <= 0 && count($member_of) === 1) {
        $gid = $member_of[0];
    }
    $group = new Group();
    if ($gid <= 0 || !$group->getFromDB($gid) || !$group->fields['is_requester']) {
        return null;
    }
    return [
        'id'          => $gid,
        'name'        => $group->fields['completename'],
        'has_manager' => countElementsInTable(Group_User::getTable(), ['groups_id' => $gid, 'is_manager' => 1]) > 0,
    ];
};

/** Urgency values enabled in Setup > General > Assistance (urgency_mask), high to low. */
$urgencies = static function () use ($CFG_GLPI): array {
    $labels = [5 => 'สูงมาก', 4 => 'สูง', 3 => 'ปานกลาง', 2 => 'ต่ำ', 1 => 'ต่ำมาก'];
    $out = [];
    foreach ($labels as $v => $label) {
        if ($v === 3 || ((int) $CFG_GLPI['urgency_mask'] & (1 << $v))) {
            $out[] = ['value' => $v, 'label' => $label];
        }
    }
    return $out;
};

/**
 * What the dialog may offer for linking the new ticket to a Problem (ITIL: incidents with the same
 * root cause go under one Problem). 'open' lists not-yet-solved Problems in the tech's entities,
 * newest first; null when the tech has no right to update Problems.
 * @return array{create:bool, open:list<array{id:int, name:string}>|null}
 */
$problem_options = static function () use ($DB): array {
    $open = null;
    if (Session::haveRight(Problem::$rightname, UPDATE)) {
        $open = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => Problem::getTable(),
                'WHERE'  => ['is_deleted' => 0, 'status' => Problem::getNotSolvedStatusArray()]
                    + getEntitiesRestrictCriteria(Problem::getTable()),
                'ORDER'  => 'id DESC',
                'LIMIT'  => 200,
            ]) as $p
        ) {
            $open[] = ['id' => (int) $p['id'], 'name' => $p['name']];
        }
    }
    return ['create' => Problem::canCreate(), 'open' => $open];
};

switch ($action) {
    case 'ticketform':
        if (!$is_tech) {
            return $fail('forbidden', 403);
        }
        $conv = $load_conv($conv_id);
        if ($conv === null) {
            return $fail('conversation not found', 404);
        }
        Session::writeClose();
        $popts = $problem_options();
        return new JsonResponse([
            'title'      => $suggest_title($conv),
            'requester_group' => $requester_group((int) $conv['users_id']),
            'categories' => array_values($ticket_categories()),
            'urgencies'  => $urgencies(),
            'problems'   => $popts,
            'types'      => array_merge([
                ['value' => Ticket::INCIDENT_TYPE, 'label' => 'Incident (แจ้งปัญหา)'],
                ['value' => Ticket::DEMAND_TYPE,   'label' => 'Request (ขอใช้บริการ)'],
            ], $popts['create'] || !empty($popts['open'])
                // Not a ticket type in GLPI: an Incident ticket that goes under a Problem.
                ? [['value' => 'problem', 'label' => 'Problem (ปัญหาที่เกิดซ้ำ / ต้องหาสาเหตุ)']]
                : []),
        ]);

    case 'poll':
        // Polling runs every few seconds; don't hold the session lock.
        $after = (int) ($_GET['after'] ?? 0);

        if ($is_tech) {
            // Inbox: open conversations, closed ones from the last 3 days, and any conversation
            // still holding messages the tech team hasn't read (e.g. user wrote, then closed).
            $unread_sql = '(SELECT COUNT(*) FROM `' . ITCHAT_MSG . '` m'
                . ' WHERE m.`plugin_itchat_conversations_id` = `' . ITCHAT_CONV . '`.`id`'
                . ' AND m.`id` > `' . ITCHAT_CONV . '`.`last_read_tech`'
                . ' AND m.`users_id` = `' . ITCHAT_CONV . '`.`users_id`)';
            $list = [];
            $iterator = $DB->request([
                'SELECT' => [
                    ITCHAT_CONV . '.*',
                    new Glpi\DBAL\QueryExpression($unread_sql . ' AS `unread`'),
                    new Glpi\DBAL\QueryExpression(
                        '(SELECT m2.`content` FROM `' . ITCHAT_MSG . '` m2'
                        . ' WHERE m2.`plugin_itchat_conversations_id` = `' . ITCHAT_CONV . '`.`id`'
                        . ' AND m2.`users_id` > 0 ORDER BY m2.`id` DESC LIMIT 1) AS `last_msg`'
                    ),
                ],
                'FROM'  => ITCHAT_CONV,
                'WHERE' => [
                    'OR' => [
                        'status'   => 'open',
                        'date_mod' => ['>', date('Y-m-d H:i:s', strtotime($now) - 3 * DAY_TIMESTAMP)],
                        new Glpi\DBAL\QueryExpression($unread_sql . ' > 0'),
                    ],
                ],
                // unread first, then open ('open' > 'closed'), then newest - so the LIMIT never drops them
                'ORDER' => [new Glpi\DBAL\QueryExpression('`unread` > 0 DESC'), 'status DESC', 'date_mod DESC'],
                'LIMIT' => 100,
            ]);
            $total_unread = 0;
            foreach ($iterator as $row) {
                $item = $export_conv($row);
                $item['unread']   = (int) $row['unread'];
                $item['last_msg'] = $row['last_msg'] === '' ? '📎 ไฟล์แนบ' : mb_substr((string) $row['last_msg'], 0, 80);
                $list[] = $item;
            }
            // Tech badge = number of chats with unread messages (open or closed), counted on its
            // own so it stays right when there are more chats than the list LIMIT.
            $total_unread = (int) $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => ITCHAT_CONV,
                'WHERE' => [new Glpi\DBAL\QueryExpression($unread_sql . ' > 0')],
            ])->current()['cpt'];
            // Inbox order: chats with unread messages first (even closed ones, or a message sent
            // right before closing would go unnoticed), then new (unclaimed), then other open,
            // then closed; latest activity first inside each group.
            $rank = static fn(array $c): int => match (true) {
                $c['unread'] > 0         => 0,
                $c['status'] !== 'open'  => 3,
                $c['tech_id'] === 0      => 1,
                default                  => 2,
            };
            usort($list, static fn(array $a, array $b): int => [$rank($a), $b['date_mod']] <=> [$rank($b), $a['date_mod']]);
            $conv = $load_conv($conv_id);
        } else {
            $list = null;
            // No conversation pinned yet: the open one, else the latest one if it still has
            // unread replies (tech answered, then closed), so the user doesn't miss them.
            $conv = $conv_id > 0 ? $load_conv($conv_id) : $own_open_conv();
            if ($conv === null && $conv_id === 0) {
                $latest = $DB->request([
                    'FROM'  => ITCHAT_CONV,
                    'WHERE' => ['users_id' => $me],
                    'ORDER' => 'id DESC',
                    'LIMIT' => 1,
                ])->current();
                if (
                    $latest !== null
                    && countElementsInTable(ITCHAT_MSG, [
                        'plugin_itchat_conversations_id' => $latest['id'],
                        'id'       => ['>', (int) $latest['last_read_user']],
                        'users_id' => ['<>', $me],
                    ]) > 0
                ) {
                    $conv = $latest;
                }
            }
            $total_unread = 0;
            if ($conv !== null) {
                $total_unread = (int) $DB->request([
                    'COUNT' => 'cpt',
                    'FROM'  => ITCHAT_MSG,
                    'WHERE' => [
                        'plugin_itchat_conversations_id' => $conv['id'],
                        'id'       => ['>', (int) $conv['last_read_user']],
                        'users_id' => ['<>', $me],
                    ],
                ])->current()['cpt'];
            }
        }

        $messages = [];
        if ($conv !== null && ($_GET['open'] ?? '0') === '1') {
            $iterator = $DB->request([
                'FROM'  => ITCHAT_MSG,
                'WHERE' => [
                    'plugin_itchat_conversations_id' => $conv['id'],
                    'id' => ['>', $after],
                ],
                'ORDER' => 'id ASC',
                'LIMIT' => 500,
            ]);
            $last_id = 0;
            foreach ($iterator as $m) {
                $messages[] = [
                    'id'      => (int) $m['id'],
                    'author'  => $name_of((int) $m['users_id']),
                    'mine'    => (int) $m['users_id'] === $me,
                    'system'  => (int) $m['users_id'] === 0,
                    'content' => $m['content'],
                    'file'    => $export_file((int) $m['id'], (int) $m['documents_id']),
                    'date'    => $m['date_creation'],
                ];
                $last_id = (int) $m['id'];
            }
            // Panel is open on this conversation -> mark as read for my side.
            $field = $is_tech ? 'last_read_tech' : 'last_read_user';
            if ($last_id > (int) $conv[$field]) {
                $DB->update(ITCHAT_CONV, [$field => $last_id], ['id' => $conv['id']]);
                if (!$is_tech) {
                    $total_unread = 0;
                } else {
                    foreach ($list as &$item) {
                        if ($item['id'] === (int) $conv['id'] && $item['unread'] > 0) {
                            $item['unread'] = 0;
                            $total_unread--;
                        }
                    }
                    unset($item);
                }
            }
        }

        Session::writeClose();
        return new JsonResponse([
            'is_tech'  => $is_tech,
            'me'       => $me,
            'unread'   => $total_unread,
            'list'     => $list,
            'conv'     => $conv !== null ? $export_conv($conv) : null,
            'messages' => $messages,
            // Read receipts: last message id the *other* side has seen in this conversation.
            // Quick replies for the technician's picker, only when asked for (first poll).
            'canned'    => $is_tech && ($_GET['canned'] ?? '0') === '1' ? plugin_itchat_config()['canned_replies'] : null,
            'peer_read' => $conv !== null ? (int) $conv[$is_tech ? 'last_read_user' : 'last_read_tech'] : 0,
        ]);

    case 'send':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $fail('POST required', 405);
        }
        $content = mb_substr(trim((string) ($_POST['content'] ?? '')), 0, ITCHAT_MAX_LEN);
        $upload  = $_FILES['file'] ?? null;
        $has_file = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($content === '' && !$has_file) {
            return $fail('empty message');
        }
        if ($has_file) {
            if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
                return $fail($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE
                    ? 'ไฟล์ใหญ่เกินไป' : 'อัปโหลดไฟล์ไม่สำเร็จ');
            }
            $max_mb = (int) ($CFG_GLPI['document_max_size'] ?? 2);
            if ($upload['size'] > $max_mb * 1024 * 1024) {
                return $fail(sprintf('ไฟล์ใหญ่เกิน %d MB', $max_mb));
            }
            // Document::isValidDoc() only accepts extensions marked "uploadable" in Setup > Dropdowns > Document types.
            $orig_name = trim(str_replace(['/', '\\', "\0"], '_', (string) $upload['name']));
            if ($orig_name === '' || !Document::isValidDoc($orig_name)) {
                return $fail('ไม่อนุญาตให้ส่งไฟล์ชนิดนี้');
            }
        }

        if ($is_tech) {
            $conv = $load_conv($conv_id);
            if ($conv === null) {
                return $fail('conversation not found', 404);
            }
            if ($conv['status'] !== 'open') {
                return $fail('conversation closed');
            }
            // Replying implicitly claims an unassigned conversation.
            if ((int) $conv['users_id_tech'] === 0) {
                $DB->update(ITCHAT_CONV, ['users_id_tech' => $me], ['id' => $conv['id']]);
                $add_msg((int) $conv['id'], 0, sprintf('%s รับเรื่องแล้ว', $name_of($me)));
            }
        } else {
            $conv = $own_open_conv();
            $is_new_conv = $conv === null;
            if ($is_new_conv) {
                $DB->insert(ITCHAT_CONV, [
                    'users_id'      => $me,
                    'status'        => 'open',
                    'date_creation' => $now,
                    'date_mod'      => $now,
                ]);
                $conv = $load_conv((int) $DB->insertId());
            }
        }

        $documents_id = 0;
        if ($has_file) {
            // Same path GLPI's own uploader uses: file in GLPI_TMP_DIR + `_filename`/`_prefix_filename`
            // -> Document::moveDocument() checks the extension, dedups by sha1 and moves it into GLPI_DOC_DIR.
            $prefix = uniqid('itchat', true) . '_';
            if (!move_uploaded_file($upload['tmp_name'], GLPI_TMP_DIR . '/' . $prefix . $orig_name)) {
                return $fail('อัปโหลดไฟล์ไม่สำเร็จ', 500);
            }
            $documents_id = (int) (new Document())->add([
                'name'                    => $orig_name,
                'entities_id'             => (int) ($_SESSION['glpiactive_entity'] ?? 0),
                'comment'                 => 'IT Chat #' . (int) $conv['id'],
                '_filename'               => [$prefix . $orig_name],
                '_prefix_filename'        => [$prefix],
                '_only_if_upload_succeed' => 1,
            ]);
            if ($documents_id <= 0) {
                return $fail('บันทึกไฟล์ไม่สำเร็จ', 500);
            }
        }

        $msg_id = $add_msg((int) $conv['id'], $me, $content, $documents_id);

        // Chat already turned into a ticket: keep the ticket's history complete by copying the
        // message there as a followup (flagged so plugin_itchat_followup_added() won't echo it back).
        if ((int) $conv['tickets_id'] > 0 && (new Ticket())->getFromDB((int) $conv['tickets_id'])) {
            $fup_content = $content !== '' ? nl2br(htmlescape($content)) : '';
            if ($documents_id > 0) {
                (new Document_Item())->add(['documents_id' => $documents_id, 'itemtype' => Ticket::class, 'items_id' => (int) $conv['tickets_id']]);
                $fup_content .= ($fup_content !== '' ? '<br>' : '') . '📎 ' . htmlescape($orig_name);
            }
            (new ITILFollowup())->add([
                'itemtype'          => Ticket::class,
                'items_id'          => (int) $conv['tickets_id'],
                'users_id'          => $me,
                'content'           => '<p><em>จาก IT Chat:</em> ' . $fup_content . '</p>',
                'is_private'        => 0,
                '_itchat_from_chat' => 1,
            ]);
        }
        $DB->update(
            ITCHAT_CONV,
            [$is_tech ? 'last_read_tech' : 'last_read_user' => $msg_id],
            ['id' => $conv['id']]
        );
        if (!$is_tech && $is_new_conv && ($offhours = plugin_itchat_offhours_reply()) !== null) {
            // Started outside the business-hours calendar: tell the requester when to expect an answer.
            $add_msg((int) $conv['id'], 0, $offhours);
        }
        if (!$is_tech && $is_new_conv) {
            plugin_itchat_notify_new_conversation((int) $conv['id'], $name_of($me), $content !== '' ? $content : '📎 ' . $orig_name);
        }
        return new JsonResponse(['ok' => true, 'conv' => (int) $conv['id'], 'id' => $msg_id]);

    case 'techs':
        if (!$is_tech) {
            return $fail('forbidden', 403);
        }
        Session::writeClose();
        $techs = [];
        foreach (plugin_itchat_technicians() as $id => $name) {
            if ($id !== $me) {
                $techs[] = ['id' => $id, 'name' => $name];
            }
        }
        return new JsonResponse(['techs' => $techs]);

    case 'transfer':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $fail('POST required', 405);
        }
        if (!$is_tech) {
            return $fail('forbidden', 403);
        }
        $conv = $load_conv($conv_id);
        if ($conv === null) {
            return $fail('conversation not found', 404);
        }
        if ($conv['status'] !== 'open') {
            return $fail('conversation closed');
        }
        $target = (int) ($_POST['users_id'] ?? 0);
        if (!array_key_exists($target, plugin_itchat_technicians())) {
            return $fail('โอนได้เฉพาะช่างที่มีสิทธิ์ดู Ticket ทั้งหมด');
        }
        if ($target === (int) $conv['users_id_tech']) {
            return $fail('แชทนี้อยู่กับช่างคนนี้อยู่แล้ว');
        }
        $DB->update(ITCHAT_CONV, ['users_id_tech' => $target], ['id' => $conv['id']]);
        // The chat's ticket follows while it's still being worked on: the new tech takes the previous
        // chat tech's place among the assignees (other assigned techs / groups stay as they are).
        $ticket_moved = false;
        $ticket = new Ticket();
        if (
            (int) $conv['tickets_id'] > 0
            && $ticket->getFromDB((int) $conv['tickets_id'])
            && !in_array((int) $ticket->fields['status'], array_merge(Ticket::getSolvedStatusArray(), Ticket::getClosedStatusArray()), true)
        ) {
            $actor = ['tickets_id' => $ticket->getID(), 'type' => CommonITILActor::ASSIGN];
            $link = new Ticket_User();
            if (!$link->getFromDBByCrit($actor + ['users_id' => $target])) {
                $link->add($actor + ['users_id' => $target]);
            }
            $previous = (int) $conv['users_id_tech'];
            if ($previous > 0 && $previous !== $target && $link->getFromDBByCrit($actor + ['users_id' => $previous])) {
                $link->delete(['id' => $link->getID()]);
            }
            $ticket_moved = $link->getFromDBByCrit($actor + ['users_id' => $target]);
        }
        $add_msg((int) $conv['id'], 0, sprintf('%s โอนแชทให้ %s', $name_of($me), $name_of($target))
            . ($ticket_moved ? sprintf(' (Ticket #%d ด้วย)', $ticket->getID()) : ''));
        return new JsonResponse(['ok' => true, 'ticket_moved' => $ticket_moved]);

    case 'rate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $fail('POST required', 405);
        }
        $conv = $load_conv($conv_id);
        if ($conv === null || (int) $conv['users_id'] !== $me) {
            return $fail('conversation not found', 404);
        }
        if ($conv['status'] !== 'closed') {
            return $fail('ให้คะแนนได้หลังปิดแชทแล้ว');
        }
        if ((int) $conv['satisfaction'] > 0) {
            return $fail('ให้คะแนนแชทนี้ไปแล้ว');
        }
        $value = (int) ($_POST['value'] ?? 0);
        if ($value < 1 || $value > 5) {
            return $fail('คะแนนต้องอยู่ระหว่าง 1-5');
        }
        $DB->update(ITCHAT_CONV, ['satisfaction' => $value, 'date_satisfaction' => $now], ['id' => $conv['id']]);
        $rating_msg = $add_msg((int) $conv['id'], 0, sprintf('%s ให้คะแนน %s', $name_of($me), str_repeat('★', $value) . str_repeat('☆', 5 - $value)));
        // the rating message is the requester's own action: don't show it back as unread
        $DB->update(ITCHAT_CONV, ['last_read_user' => $rating_msg], ['id' => $conv['id']]);
        return new JsonResponse(['ok' => true]);

    case 'search':
        if (!$is_tech) {
            return $fail('forbidden', 403);
        }
        Session::writeClose();
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return new JsonResponse(['results' => []]);
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $or = [
            ['id' => new Glpi\DBAL\QuerySubQuery([
                'SELECT' => 'plugin_itchat_conversations_id',
                'FROM'   => ITCHAT_MSG,
                'WHERE'  => ['content' => ['LIKE', $like]],
            ])],
            ['users_id' => new Glpi\DBAL\QuerySubQuery([
                'SELECT' => 'id',
                'FROM'   => 'glpi_users',
                'WHERE'  => ['OR' => [
                    ['name' => ['LIKE', $like]],
                    ['realname' => ['LIKE', $like]],
                    ['firstname' => ['LIKE', $like]],
                ]],
            ])],
        ];
        if (ctype_digit(ltrim($q, '#'))) {
            $or[] = ['tickets_id' => (int) ltrim($q, '#')];
        }
        $results = [];
        foreach ($DB->request(['FROM' => ITCHAT_CONV, 'WHERE' => ['OR' => $or], 'ORDER' => 'date_mod DESC', 'LIMIT' => 50]) as $row) {
            $item = $export_conv($row);
            // the matching message, else the latest one, as the preview line
            $hit = $DB->request([
                'SELECT' => 'content',
                'FROM'   => ITCHAT_MSG,
                'WHERE'  => ['plugin_itchat_conversations_id' => $row['id'], 'users_id' => ['>', 0], 'content' => ['LIKE', $like]],
                'ORDER'  => 'id DESC',
                'LIMIT'  => 1,
            ])->current() ?? $DB->request([
                'SELECT' => 'content',
                'FROM'   => ITCHAT_MSG,
                'WHERE'  => ['plugin_itchat_conversations_id' => $row['id'], 'users_id' => ['>', 0]],
                'ORDER'  => 'id DESC',
                'LIMIT'  => 1,
            ])->current();
            $item['last_msg'] = mb_substr((string) ($hit['content'] ?? ''), 0, 80) ?: '📎 ไฟล์แนบ';
            $item['unread'] = 0;
            $results[] = $item;
        }
        return new JsonResponse(['results' => $results]);

    case 'file':
        $msg = $DB->request(['FROM' => ITCHAT_MSG, 'WHERE' => ['id' => (int) ($_GET['msg'] ?? 0)]])->current();
        // Access = access to the conversation the message belongs to (not GLPI document rights,
        // which self-service users don't have).
        if ($msg === null || (int) $msg['documents_id'] <= 0 || $load_conv((int) $msg['plugin_itchat_conversations_id']) === null) {
            return $fail('not found', 404);
        }
        $doc = new Document();
        if (!$doc->getFromDB((int) $msg['documents_id'])) {
            return $fail('not found', 404);
        }
        Session::writeClose();
        return $doc->getAsResponse();

    case 'claim':
    case 'close':
    case 'toticket':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $fail('POST required', 405);
        }
        $conv = $load_conv($conv_id);
        if ($conv === null) {
            return $fail('conversation not found', 404);
        }
        if ($conv['status'] !== 'open') {
            return $fail('conversation closed');
        }

        if ($action === 'close') {
            // Both sides may close.
            $DB->update(ITCHAT_CONV, ['status' => 'closed'], ['id' => $conv['id']]);
            $add_msg((int) $conv['id'], 0, sprintf('%s ปิดการสนทนา', $name_of($me)));
            return new JsonResponse(['ok' => true]);
        }

        if (!$is_tech) {
            return $fail('forbidden', 403);
        }

        if ($action === 'claim') {
            $DB->update(ITCHAT_CONV, ['users_id_tech' => $me], ['id' => $conv['id']]);
            $add_msg((int) $conv['id'], 0, sprintf('%s รับเรื่องแล้ว', $name_of($me)));
            return new JsonResponse(['ok' => true]);
        }

        // toticket: transcript -> new Ticket, requester = chat user, assigned = me.
        if ((int) $conv['tickets_id'] > 0) {
            return $fail('ticket already created');
        }
        // Fields from the dialog.
        // Type is required: the technician must pick Incident, Request or Problem explicitly.
        // 'problem' = an Incident ticket that must be linked to a (new or open) Problem.
        $as_problem = ($_POST['type'] ?? '') === 'problem';
        $type = $as_problem ? Ticket::INCIDENT_TYPE : (int) ($_POST['type'] ?? 0);
        if (!in_array($type, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)) {
            return $fail('กรุณาเลือกประเภท Ticket (Incident, Request หรือ Problem)');
        }
        $category_id = (int) ($_POST['itilcategories_id'] ?? 0);
        if ($category_id > 0) {
            $cat = $ticket_categories()[$category_id] ?? null;
            if ($cat === null || !($type === Ticket::INCIDENT_TYPE ? $cat['incident'] : $cat['request'])) {
                return $fail('หมวดหมู่นี้ใช้กับประเภท Ticket ที่เลือกไม่ได้');
            }
        }
        $urgency = (int) ($_POST['urgency'] ?? 3);
        if (!in_array($urgency, array_column($urgencies(), 'value'), true)) {
            return $fail('ความเร่งด่วนไม่ถูกต้อง');
        }
        // Optional Problem link, incidents only: -1 = new Problem from this ticket, <id> = existing one.
        $problem = (int) ($_POST['problem'] ?? ($as_problem ? -1 : 0));
        if ($as_problem && $problem === 0) {
            return $fail('กรุณาเลือก Problem');
        }
        if ($problem !== 0) {
            $popts = $problem_options();
            if ($type !== Ticket::INCIDENT_TYPE) {
                return $fail('เชื่อมโยง Problem ได้เฉพาะ Incident');
            }
            if ($problem === -1 ? !$popts['create'] : !in_array($problem, array_column($popts['open'] ?? [], 'id'), true)) {
                return $fail('ไม่มีสิทธิ์หรือไม่พบ Problem ที่เลือก');
            }
        }
        $custom_title = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        $lines = [];
        $doc_ids = [];
        foreach (
            $DB->request([
                'FROM'  => ITCHAT_MSG,
                'WHERE' => ['plugin_itchat_conversations_id' => $conv['id'], 'users_id' => ['>', 0]],
                'ORDER' => 'id ASC',
            ]) as $m
        ) {
            $body = nl2br(htmlescape($m['content']));
            if ((int) $m['documents_id'] > 0) {
                $doc_ids[] = (int) $m['documents_id'];
                $f = $export_file((int) $m['id'], (int) $m['documents_id']);
                $body .= ($body !== '' ? '<br>' : '') . '📎 ' . htmlescape($f['name'] ?? '');
            }
            $lines[] = sprintf(
                '<p><strong>%s</strong> <small>(%s)</small><br>%s</p>',
                htmlescape($name_of((int) $m['users_id'])),
                htmlescape(Html::convDateTime($m['date_creation'])),
                $body
            );
        }
        if ($lines === []) {
            return $fail('no messages');
        }
        $title = $custom_title !== '' ? mb_substr($custom_title, 0, 250) : $suggest_title($conv);

        $source = new RequestType();
        $source->getFromDBByCrit(['name' => PLUGIN_ITCHAT_REQUEST_TYPE]);
        $ticket = new Ticket();
        $tickets_id = $ticket->add([
            'requesttypes_id'     => $source->isNewItem() ? RequestType::getDefault('helpdesk') : $source->getID(),
            'name'                => $title,
            'content'             => '<p><em>สร้างจาก IT Chat #' . (int) $conv['id'] . '</em></p>' . implode('', $lines),
            'type'                => $type,
            'itilcategories_id'   => $category_id,
            'urgency'             => $urgency,
            '_users_id_requester' => (int) $conv['users_id'],
            '_users_id_assign'    => $me,
        ] + (($rg = $requester_group((int) $conv['users_id'])) !== null ? ['_groups_id_requester' => $rg['id']] : []));
        if (!$tickets_id) {
            return $fail('ticket creation failed', 500);
        }
        // Profiles without the "assign" right (stock Technician only has OWN = "be in charge")
        // get _users_id_assign silently dropped by add(). Mirror GLPI's own "Assign to me"
        // button: add the actor afterwards when canAssignToMe() allows it.
        $ticket->getFromDB($tickets_id);
        if ($ticket->countUsers(CommonITILActor::ASSIGN) === 0 && $ticket->canAssignToMe()) {
            (new Ticket_User())->add([
                'tickets_id' => $tickets_id,
                'users_id'   => $me,
                'type'       => CommonITILActor::ASSIGN,
            ]);
        }
        // Attach every chat file to the ticket (they then also show in its Documents tab).
        foreach (array_unique($doc_ids) as $did) {
            (new Document_Item())->add([
                'documents_id' => $did,
                'itemtype'     => Ticket::class,
                'items_id'     => $tickets_id,
            ]);
        }
        // Problem link. The ticket already exists, so a failure here is reported, not fatal.
        $problems_id = 0;
        if ($problem === -1) {
            // Same as GLPI's "Create a problem from this ticket": _tickets_id makes Problem::post_addItem
            // add the Problem_Ticket link and copy the ticket's items.
            $problems_id = (int) (new Problem())->add([
                'name'              => $title,
                'content'           => $ticket->fields['content'],
                'entities_id'       => $ticket->fields['entities_id'],
                'itilcategories_id' => $category_id,
                'urgency'           => $urgency,
                '_users_id_assign'  => $me,
                '_tickets_id'       => $tickets_id,
            ]);
        } elseif ($problem > 0) {
            $problems_id = (new Problem_Ticket())->add(['problems_id' => $problem, 'tickets_id' => $tickets_id]) ? $problem : 0;
        }
        $DB->update(
            ITCHAT_CONV,
            ['tickets_id' => $tickets_id, 'users_id_tech' => $conv['users_id_tech'] ?: $me],
            ['id' => $conv['id']]
        );
        $add_msg((int) $conv['id'], 0, sprintf('เปิด Ticket #%d จากการสนทนานี้แล้ว', $tickets_id));
        return new JsonResponse([
            'ok'          => true,
            'tickets_id'  => $tickets_id,
            'ticket_url'  => Ticket::getFormURLWithID($tickets_id),
            'problems_id' => $problems_id,
            'problem_failed' => $problem !== 0 && $problems_id === 0,
        ]);
}

return $fail('unknown action');
