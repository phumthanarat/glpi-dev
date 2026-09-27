<?php

function plugin_itsecurity_install(): bool
{
    if (!isset(Config::getConfigurationValues('plugin:itsecurity')['idle_minutes'])) {
        Config::setConfigurationValues('plugin:itsecurity', ['idle_minutes' => 60]);
    }
    return true;
}

function plugin_itsecurity_uninstall(): bool
{
    Config::deleteConfigurationValues('plugin:itsecurity', ['idle_minutes']);
    return true;
}
