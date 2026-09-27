<?php

namespace GlpiPlugin\Itsecurity;

use CommonGLPI;
use Session;

/** Setup > Security (only for who may change GLPI's configuration). */
class Menu extends CommonGLPI
{
    public static function getMenuName()
    {
        return 'Security';
    }

    public static function getIcon()
    {
        return 'ti ti-shield-lock';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }
        return ['title' => self::getMenuName(), 'page' => '/plugins/itsecurity/front/security.php', 'icon' => self::getIcon()];
    }
}
