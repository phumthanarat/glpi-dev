<?php

/**
 * IT Security - login security settings on one page (Setup > Security):
 *   - idle timeout: a session unused for N minutes is closed (GLPI's own "session expired"
 *     flow: back to the login page, then to where the user was). Background polling (the chat
 *     widget, dashboards: XHR GET) doesn't count as activity, or nobody would ever time out.
 *   - 2FA (GLPI's built-in TOTP): which profiles must use it, the grace period to enrol,
 *     who hasn't enrolled yet, reset 2FA for someone who lost their phone.
 * Config update right only.
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ITSECURITY_VERSION', '1.0.0');

function plugin_version_itsecurity(): array
{
    return [
        'name'         => 'IT Security',
        'version'      => PLUGIN_ITSECURITY_VERSION,
        'author'       => 'IT Dev',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '11.99.99']],
    ];
}

function plugin_init_itsecurity(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['itsecurity'] = true;
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['itsecurity'] = ['config' => [\GlpiPlugin\Itsecurity\Menu::class]];
    $PLUGIN_HOOKS[Hooks::POST_INIT]['itsecurity'] = 'plugin_itsecurity_postinit';
}

function plugin_itsecurity_postinit(): void
{
    \GlpiPlugin\Itsecurity\Idle::check();
}
