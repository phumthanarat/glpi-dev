<?php

/** Download the internal CA certificate (to deploy by AD GPO / MDM). Config update right only. */

use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Ithttps\Tls;
use Symfony\Component\HttpFoundation\Response;

Session::checkRight('config', UPDATE);

$ca = Tls::caCertificate();
if ($ca === null) {
    throw new NotFoundHttpException();
}
return new Response($ca, 200, [
    'Content-Type'        => 'application/x-x509-ca-cert',
    'Content-Disposition' => 'attachment; filename="itsm-internal-ca.crt"',
    'Cache-Control'       => 'no-store',
]);
