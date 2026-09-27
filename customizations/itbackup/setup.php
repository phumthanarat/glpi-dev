<?php

/**
 * IT Backup - database + files backups of GLPI, with a page to see and manage them
 * (Setup > Backups, Super-Admin / "config" update right only).
 *
 * Backups are made daily by the glpi-backup CronJob (k8s/base/backup-cronjob.yaml) and on
 * demand from the page; they live on their own volume, mounted at
 * /var/lib/glpi-backups (see src/Backup.php for the layout). No restore button on purpose:
 * restoring replaces everything, it's done from the command line (see README.md).
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ITBACKUP_VERSION', '1.0.0');

function plugin_version_itbackup(): array
{
    return [
        'name'         => 'IT Backup',
        'version'      => PLUGIN_ITBACKUP_VERSION,
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

function plugin_init_itbackup(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['itbackup'] = true;
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['itbackup'] = ['config' => [\GlpiPlugin\Itbackup\Menu::class]];
    // the file share password is stored encrypted with GLPI's key
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['itbackup'] = ['smb_pass'];
}
