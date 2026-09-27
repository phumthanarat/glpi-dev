<?php

function plugin_itmonitor_install(): bool
{
    return true;
}

function plugin_itmonitor_uninstall(): bool
{
    return true; // the monitor keeps running with its Secret
}
