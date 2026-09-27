<?php

/**
 * IT Monitor - Setup > Monitoring: what glpi-monitor sees (k8s/base/monitor-deployment.yaml),
 * where its alerts go (e-mail, Teams, Google Chat, Slack, LINE, webhook), send a test alert.
 * Settings live in the Secret glpi-monitor, which the monitor re-reads every round.
 * Config update right only.
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ITMONITOR_VERSION', '1.0.0');

function plugin_version_itmonitor(): array
{
    return [
        'name'         => 'IT Monitor',
        'version'      => PLUGIN_ITMONITOR_VERSION,
        'author'       => 'IT Dev',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '11.99.99']],
    ];
}

function plugin_init_itmonitor(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['itmonitor'] = true;
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['itmonitor'] = ['config' => [\GlpiPlugin\Itmonitor\Menu::class]];
}
