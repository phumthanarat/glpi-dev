<?php

function plugin_ithttps_install(): bool
{
    // daily: renew an internal certificate 30 days before expiry, warn about an uploaded one
    CronTask::register(\GlpiPlugin\Ithttps\Tls::class, 'ithttpsRenew', DAY_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'state'   => CronTask::STATE_WAITING,
        'comment' => 'IT HTTPS: certificate renewal / expiry warning',
    ]);
    return true;
}

function plugin_ithttps_uninstall(): bool
{
    CronTask::unregister('ithttps');
    // the certificate stays in its Secret: uninstalling must not break HTTPS
    return true;
}
