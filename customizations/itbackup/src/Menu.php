<?php

namespace GlpiPlugin\Itbackup;

use CommonGLPI;
use Session;

/** Setup > Backups (only for who may change GLPI's configuration). */
class Menu extends CommonGLPI
{
    public static function getMenuName()
    {
        return 'Backups';
    }

    public static function getIcon()
    {
        return 'ti ti-database-export';
    }

    public static function getMenuContent()
    {
        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }
        return [
            'title' => self::getMenuName(),
            'page'  => '/plugins/itbackup/front/backup.php',
            'icon'  => self::getIcon(),
        ];
    }
}
