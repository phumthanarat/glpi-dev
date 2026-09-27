<?php

function plugin_itbackup_install(): bool
{
    $current = Config::getConfigurationValues('plugin:itbackup');
    if (!isset($current['keep_days'])) {
        Config::setConfigurationValues('plugin:itbackup', ['keep_days' => 14]);
    }
    return true;
}

function plugin_itbackup_uninstall(): bool
{
    // settings are removed, the backups themselves are left on their volume
    Config::deleteConfigurationValues('plugin:itbackup', ['keep_days']);
    return true;
}
