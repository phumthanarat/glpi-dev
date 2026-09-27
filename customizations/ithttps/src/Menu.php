<?php

namespace GlpiPlugin\Ithttps;

use CommonGLPI;
use Session;

/** Setup > HTTPS (only for who may change GLPI's configuration). */
class Menu extends CommonGLPI
{
    public static function getMenuName()
    {
        return 'HTTPS';
    }

    public static function getIcon()
    {
        return 'ti ti-lock';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }
        return ['title' => self::getMenuName(), 'page' => '/plugins/ithttps/front/https.php', 'icon' => self::getIcon()];
    }
}
