<?php

/**
 * IT HTTPS - the certificate of GLPI's web address, managed from GLPI: Setup > HTTPS.
 * Upload the organisation's certificate, or use an internal CA (renewed automatically, CA
 * certificate downloadable for AD GPO). Writes the Ingress Secret glpi-tls through the
 * Kubernetes API (k8s/base/https-rbac.yaml). Super-Admin / "config" update right only.
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ITHTTPS_VERSION', '1.0.0');

function plugin_version_ithttps(): array
{
    return [
        'name'         => 'IT HTTPS',
        'version'      => PLUGIN_ITHTTPS_VERSION,
        'author'       => 'IT Dev',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => ['glpi' => ['min' => '11.0.0', 'max' => '11.99.99']],
    ];
}

function plugin_init_ithttps(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['ithttps'] = true;
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['ithttps'] = ['config' => [\GlpiPlugin\Ithttps\Menu::class]];
}
