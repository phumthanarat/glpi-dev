<?php

/**
 * IT QR - "scan to report a problem" QR labels for assets.
 *
 * - Technicians: a "QR แจ้งปัญหา" tab on Computers / Monitors / Printers / Network devices /
 *   Peripherals / Phones, and a "Print QR labels" massive action for many assets at once.
 * - End users: scanning the label opens a short report page for *that* asset (log in first if
 *   needed). The ticket is created with the asset linked, a category from the asset type (so the
 *   team / SLA business rules apply) and the request source "QR code".
 *
 * The QR carries an HMAC signature (GLPI's own key), so only printed labels work: a user can't
 * enumerate asset ids to list asset names. No core file is touched; see README.md.
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ITQR_VERSION', '1.0.0');

function plugin_version_itqr(): array
{
    return [
        'name'         => 'IT QR',
        'version'      => PLUGIN_ITQR_VERSION,
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

function plugin_init_itqr(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['itqr'] = true;
    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['itqr'] = true;

    Plugin::registerClass(\GlpiPlugin\Itqr\Label::class, ['addtabon' => \GlpiPlugin\Itqr\Label::ITEMTYPES]);

    // report.php checks the login itself: GLPI's default for a visitor without a session is
    // "Your session has expired" + a "Log in again" button, confusing for someone who just
    // scanned a label for the first time. report.php sends them to the normal login page instead.
    \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('itqr', '#^/front/report\.php$#', \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
}
