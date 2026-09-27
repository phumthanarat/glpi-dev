<?php

/**
 * Download one file of a backup: ?backup=<name>&file=database.sql.gz|files.tar.gz
 * Super-Admin / config update right only; every download is logged.
 */

use Glpi\Event;
use Glpi\Exception\Http\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use GlpiPlugin\Itbackup\Backup;

Session::checkRight('config', UPDATE);

$name = (string) ($_GET['backup'] ?? '');
$file = (string) ($_GET['file'] ?? '');
$path = Backup::BACKUP_DIR . '/' . $name . '/' . $file;
if (!Backup::isValidName($name) || !in_array($file, ['database.sql.gz', 'files.tar.gz'], true) || !is_file($path)) {
    throw new NotFoundHttpException();
}

Event::log(0, 'system', 3, 'setup', sprintf('%s downloaded %s/%s', $_SESSION['glpiname'], $name, $file));

$response = new BinaryFileResponse($path, 200, [
    'Content-Type'           => 'application/gzip',
    'X-Content-Type-Options' => 'nosniff',
    'Cache-Control'          => 'no-store',
]);
$response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'glpi-' . $name . '-' . $file);
return $response;
