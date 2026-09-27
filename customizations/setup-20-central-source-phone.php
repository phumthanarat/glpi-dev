<?php

/**
 * Request source "Phone" by default when a technician opens a ticket from the Central
 * interface (Assistance > Tickets > + Add), so reports can tell the channels apart:
 *
 *   Helpdesk forms -> Helpdesk   E-mail -> E-Mail (setup-19)   IT Chat -> Chat (itchat 1.7.0)
 *   QR labels -> QR code (itqr)  Central "+ Add" -> Phone (this script; the technician can
 *   still switch it to Direct / Written / Other in the form)
 *
 * How: a ticket template "Central (technicians)" = the Default template's mandatory / hidden
 * fields + a predefined "Request source" = Phone, set as the ticket template of the Technician,
 * Hotliner and Supervisor profiles. Self-Service users keep the Default template; tickets from
 * e-mail / chat / QR set their source explicitly and are not affected.
 *
 * Idempotent. Run inside the app container:
 *   php customizations/setup-20-central-source-phone.php
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

$auth = new Auth();
if (!$auth->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Login as 'glpi' failed.\n");
    exit(1);
}
Session::init($auth);

global $DB;

$phone = new RequestType();
if (!$phone->getFromDBByCrit(['name' => 'Phone'])) {
    fwrite(STDERR, "Request type 'Phone' not found\n");
    exit(1);
}

$name = 'Central (technicians)';
$tpl = new TicketTemplate();
if ($tpl->getFromDBByCrit(['name' => $name])) {
    $tpl_id = $tpl->getID();
    echo "Template #$tpl_id '$name' exists\n";
} else {
    $tpl_id = $tpl->add([
        'name'         => $name,
        'entities_id'  => 0,
        'is_recursive' => 1,
        'comment'      => 'Tickets opened by technicians from the Central form: request source Phone by default. Managed by customizations/setup-20-central-source-phone.php',
    ]);
    // same mandatory / hidden fields as the Default template, so only the source changes
    $default = new TicketTemplate();
    $default->getFromDBByCrit(['name' => 'Default']);
    foreach ([TicketTemplateMandatoryField::class, TicketTemplateHiddenField::class] as $class) {
        foreach ($DB->request(['FROM' => $class::getTable(), 'WHERE' => ['tickettemplates_id' => $default->getID()]]) as $r) {
            (new $class())->add(['tickettemplates_id' => $tpl_id, 'num' => $r['num']]);
        }
    }
    echo "Template #$tpl_id '$name' created (fields copied from Default #{$default->getID()})\n";
}

// "Request source" search option of Ticket
$num = null;
foreach ((new Ticket())->searchOptions() as $id => $opt) {
    if (is_array($opt) && ($opt['field'] ?? null) === 'name' && ($opt['table'] ?? null) === RequestType::getTable()) {
        $num = (int) $id;
        break;
    }
}
if ($num === null) {
    fwrite(STDERR, "Search option for the request source not found\n");
    exit(1);
}
$predef = new TicketTemplatePredefinedField();
if ($predef->getFromDBByCrit(['tickettemplates_id' => $tpl_id, 'num' => $num])) {
    $predef->update(['id' => $predef->getID(), 'value' => $phone->getID()]);
} else {
    $predef->add(['tickettemplates_id' => $tpl_id, 'num' => $num, 'value' => $phone->getID()]);
}
echo "Predefined: request source (option $num) = Phone #{$phone->getID()}\n";

foreach (['Technician', 'Hotliner', 'Supervisor'] as $profile_name) {
    $profile = new Profile();
    if ($profile->getFromDBByCrit(['name' => $profile_name])) {
        $profile->update(['id' => $profile->getID(), 'tickettemplates_id' => $tpl_id]);
        echo "Profile $profile_name -> template '$name'\n";
    }
}
echo "Done. Technicians get it at their next login.\n";
