<?php

namespace GlpiPlugin\Itchat;

/**
 * Providers for the IT Chat dashboard cards (declared in plugin_itchat_dashboard_cards()).
 * Each returns the bigNumber widget shape: number / label / icon / url.
 */
class Dashboard
{
    private const CONV = 'glpi_plugin_itchat_conversations';

    public static function csat(array $params = []): array
    {
        global $DB;
        $since = date('Y-m-d H:i:s', strtotime(\Session::getCurrentTime()) - 30 * DAY_TIMESTAMP);
        $row = $DB->request([
            'SELECT' => [
                new \Glpi\DBAL\QueryExpression('AVG(`satisfaction`) AS avg'),
                new \Glpi\DBAL\QueryExpression('COUNT(*) AS n'),
            ],
            'FROM'  => self::CONV,
            'WHERE' => ['satisfaction' => ['>', 0], 'date_satisfaction' => ['>=', $since]],
        ])->current();
        $n = (int) ($row['n'] ?? 0);
        return [
            'number' => $n > 0 ? number_format((float) $row['avg'], 1) . ' / 5' : '-',
            'label'  => sprintf('คะแนนความพึงพอใจแชท (30 วัน, %d คน)', $n),
            'icon'   => 'ti ti-star',
            'url'    => '',
        ];
    }

    public static function chatsToday(array $params = []): array
    {
        return [
            'number' => countElementsInTable(self::CONV, ['date_creation' => ['>=', date('Y-m-d 00:00:00')]]),
            'label'  => 'แชทใหม่วันนี้',
            'icon'   => 'ti ti-messages',
            'url'    => '',
        ];
    }

    public static function waiting(array $params = []): array
    {
        return [
            'number' => countElementsInTable(self::CONV, ['status' => 'open', 'users_id_tech' => 0]),
            'label'  => 'แชทที่รอช่างรับเรื่อง',
            'icon'   => 'ti ti-hourglass',
            'url'    => '',
        ];
    }
}
