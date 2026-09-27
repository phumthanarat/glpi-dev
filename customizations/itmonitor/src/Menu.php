<?php

namespace GlpiPlugin\Itmonitor;

use CommonGLPI;
use Session;

/** Setup > Monitoring (only for who may change GLPI's configuration). */
class Menu extends CommonGLPI
{
    public static function getMenuName()
    {
        return 'Monitoring';
    }

    public static function getIcon()
    {
        return 'ti ti-heartbeat';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }
        return ['title' => self::getMenuName(), 'page' => '/plugins/itmonitor/front/monitoring.php', 'icon' => self::getIcon()];
    }
}
