<?php

function plugin_itchat_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // One row per chat thread. users_id = the requester (end user),
    // users_id_tech = technician who claimed it (0 = waiting in queue).
    // last_read_* hold the id of the last message each side has seen,
    // used for unread badges.
    if (!$DB->tableExists('glpi_plugin_itchat_conversations')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_itchat_conversations` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id_tech` int {$sign} NOT NULL DEFAULT '0',
            `status` varchar(16) NOT NULL DEFAULT 'open',
            `tickets_id` int {$sign} NOT NULL DEFAULT '0',
            `last_read_user` int {$sign} NOT NULL DEFAULT '0',
            `last_read_tech` int {$sign} NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `users_id_tech` (`users_id_tech`),
            KEY `status` (`status`),
            KEY `tickets_id` (`tickets_id`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // users_id = 0 marks a system message (claimed / closed / ticket created).
    if (!$DB->tableExists('glpi_plugin_itchat_messages')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_itchat_messages` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_itchat_conversations_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `content` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_itchat_conversations_id` (`plugin_itchat_conversations_id`),
            KEY `users_id` (`users_id`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // 1.2.0: chat attachments (GLPI Document per file).
    $migration = new Migration(PLUGIN_ITCHAT_VERSION);
    $migration->addField('glpi_plugin_itchat_messages', 'documents_id', 'fkey', ['after' => 'content']);
    $migration->addKey('glpi_plugin_itchat_messages', 'documents_id');
    $migration->executeMigration();

    // 1.6.0: requester satisfaction rating (1-5) after a chat is closed.
    $migration = new Migration(PLUGIN_ITCHAT_VERSION);
    $migration->addField('glpi_plugin_itchat_conversations', 'satisfaction', 'tinyint NOT NULL DEFAULT 0', ['after' => 'tickets_id']);
    $migration->addField('glpi_plugin_itchat_conversations', 'date_satisfaction', 'timestamp NULL DEFAULT NULL', ['after' => 'satisfaction']);
    $migration->addKey('glpi_plugin_itchat_conversations', 'date_satisfaction');
    $migration->executeMigration();

    // 1.5.0: hourly automatic action (idle auto-close + retention), run by the external cron.
    CronTask::register(\GlpiPlugin\Itchat\Maintenance::class, \GlpiPlugin\Itchat\Maintenance::TASK, HOUR_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'IT Chat: close idle chats, delete old ones (settings: Setup > Plugins > IT Chat)',
    ]);

    // 1.7.0: request source "Chat" for tickets opened from a chat, so reports can tell the
    // channels apart (they used to get the default "Helpdesk", like the Helpdesk forms).
    $source = new RequestType();
    if (!$source->getFromDBByCrit(['name' => PLUGIN_ITCHAT_REQUEST_TYPE])) {
        $source->add([
            'name'            => PLUGIN_ITCHAT_REQUEST_TYPE,
            'is_active'       => 1,
            'is_ticketheader' => 1,
            'is_itilfollowup' => 1,
            'comment'         => 'Tickets opened from an IT Chat conversation',
        ]);
    }

    return true;
}

function plugin_itchat_uninstall(): bool
{
    global $DB;

    foreach (['glpi_plugin_itchat_messages', 'glpi_plugin_itchat_conversations'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    return true;
}
