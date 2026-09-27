<?php

use GlpiPlugin\Itqr\Label;

function plugin_itqr_install(): bool
{
    // Request source shown on the ticket and usable in reports / business rules.
    $type = new RequestType();
    if (!$type->getFromDBByCrit(['name' => Label::REQUEST_TYPE])) {
        $type->add([
            'name'         => Label::REQUEST_TYPE,
            'is_active'    => 1,
            'is_ticketheader' => 1,
            'is_itilfollowup' => 0,
            'comment'      => 'Tickets reported by scanning an asset QR label (IT QR plugin)',
        ]);
    }
    return true;
}

function plugin_itqr_uninstall(): bool
{
    // The "QR code" request type is kept: existing tickets refer to it.
    return true;
}

function plugin_itqr_MassiveActions($itemtype): array
{
    if (!in_array($itemtype, Label::ITEMTYPES, true) || Session::getCurrentInterface() !== 'central') {
        return [];
    }
    return [
        Label::class . MassiveAction::CLASS_ACTION_SEPARATOR . 'print_labels' => "<i class='ti ti-qrcode'></i>" . __s('Print QR labels'),
    ];
}
