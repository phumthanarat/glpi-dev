<?php

namespace GlpiPlugin\Itchat;

use CronTask;
use Document;
use Document_Item;

/**
 * Hourly automatic action "ItchatMaintenance" (Setup > Automatic actions), registered by hook.php:
 *   - closes open chats with no activity for `idle_close_hours` (0 = never)
 *   - deletes closed chats older than `retention_days` (0 = keep forever), plus the files
 *     uploaded in them unless a ticket still uses the file
 * Thresholds come from the plugin config page.
 */
class Maintenance
{
    public const TASK = 'ItchatMaintenance';

    public static function cronInfo($name): array
    {
        return ['description' => 'IT Chat: ปิดแชทที่ไม่มีความเคลื่อนไหว และลบแชทเก่า'];
    }

    public static function cronItchatMaintenance(?CronTask $task = null): int
    {
        global $DB;

        $conf   = plugin_itchat_config();
        $now    = \Session::getCurrentTime();
        $closed = 0;
        $purged = 0;

        if ($conf['idle_close_hours'] > 0) {
            $limit = date('Y-m-d H:i:s', strtotime($now) - $conf['idle_close_hours'] * HOUR_TIMESTAMP);
            foreach (
                $DB->request([
                    'SELECT' => 'id',
                    'FROM'   => 'glpi_plugin_itchat_conversations',
                    'WHERE'  => ['status' => 'open', 'date_mod' => ['<', $limit]],
                ]) as $row
            ) {
                $DB->update('glpi_plugin_itchat_conversations', ['status' => 'closed', 'date_mod' => $now], ['id' => $row['id']]);
                $DB->insert('glpi_plugin_itchat_messages', [
                    'plugin_itchat_conversations_id' => $row['id'],
                    'users_id'      => 0,
                    'content'       => sprintf('ปิดอัตโนมัติ: ไม่มีความเคลื่อนไหว %d ชั่วโมง', $conf['idle_close_hours']),
                    'date_creation' => $now,
                ]);
                $closed++;
            }
        }

        if ($conf['retention_days'] > 0) {
            $limit = date('Y-m-d H:i:s', strtotime($now) - $conf['retention_days'] * DAY_TIMESTAMP);
            $ids = array_column(iterator_to_array($DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_plugin_itchat_conversations',
                'WHERE'  => ['status' => 'closed', 'date_mod' => ['<', $limit]],
            ])), 'id');
            foreach (array_chunk($ids, 200) as $chunk) {
                $doc_ids = array_filter(array_map('intval', array_column(iterator_to_array($DB->request([
                    'SELECT' => 'documents_id',
                    'FROM'   => 'glpi_plugin_itchat_messages',
                    'WHERE'  => ['plugin_itchat_conversations_id' => $chunk, 'documents_id' => ['>', 0]],
                ])), 'documents_id')));
                foreach (array_unique($doc_ids) as $did) {
                    // keep files that were attached to a ticket (เปิด Ticket links them)
                    if (countElementsInTable(Document_Item::getTable(), ['documents_id' => $did]) === 0) {
                        (new Document())->delete(['id' => $did], true);
                    }
                }
                $DB->delete('glpi_plugin_itchat_messages', ['plugin_itchat_conversations_id' => $chunk]);
                $DB->delete('glpi_plugin_itchat_conversations', ['id' => $chunk]);
                $purged += count($chunk);
            }
        }

        if ($task !== null) {
            $task->addVolume($closed + $purged);
            $task->log(sprintf('closed %d idle chat(s), deleted %d old chat(s)', $closed, $purged));
        }
        return ($closed + $purged) > 0 ? 1 : 0;
    }
}
