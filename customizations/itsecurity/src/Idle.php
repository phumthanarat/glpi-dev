<?php

namespace GlpiPlugin\Itsecurity;

use Config;
use Session;

/** Idle timeout of logged-in web sessions. */
class Idle
{
    private const KEY = 'itsecurity_last_activity';

    public static function minutes(): int
    {
        return max(0, (int) (Config::getConfigurationValues('plugin:itsecurity', ['idle_minutes'])['idle_minutes'] ?? 60));
    }

    public static function check(): void
    {
        if (isCommandLine() || isAPI() || !Session::getLoginUserID()) {
            return;
        }
        $minutes = self::minutes();
        $now = time();
        $last = (int) ($_SESSION[self::KEY] ?? $now);
        if ($minutes > 0 && $now - $last > $minutes * 60) {
            // Empty the session (new id), don't throw: this runs while GLPI boots, before
            // requests are handled. GLPI's own session check then finds no valid session and
            // does the usual "session expired": login page and back afterwards, 401 for AJAX.
            session_unset();
            session_regenerate_id(true);
            return;
        }
        // activity = pages and actions (non-XHR, or POST); background XHR polling isn't
        $xhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
        if (!$xhr || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || !isset($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = $now;
        }
    }
}
