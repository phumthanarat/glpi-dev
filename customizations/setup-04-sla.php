<?php

/**
 * Step 4 of the ITSM rollout plan: SLA (customer-facing Time To Own /
 * Time To Resolve), one pair per priority level:
 *
 *   Priority   TTO          TTR
 *   Critical   15 minutes   2 hours
 *   High       30 minutes   4 hours
 *   Medium     2 hours      8 hours
 *   Low        4 hours      3 days
 *
 * All 8 SLAs are grouped under one SLM ("IT Support SLM") and use the
 * "IT Support Business Hours" calendar created in setup-01-calendar.php
 * — so a ticket filed Friday 16:30 does NOT count Saturday/Sunday
 * toward its deadline.
 *
 * OLA (internal/team-level TTO/TTR) is a separate GLPI object from SLA
 * and is intentionally not created here — nothing in the original plan
 * calls for internal team SLAs; add it later the same way if needed.
 *
 * Business Rules that assign these SLAs by Category/Priority are a
 * later step (5/6), not this one.
 *
 * Run inside the app container:
 *   php customizations/setup-04-sla.php
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Super-Admin session for this CLI run, without a password: these scripts only run inside the
// app container (which has the database credentials anyway), and must keep working after the
// admin password is changed (setup-21).
$auth = new Auth();
$auth->user = new User();
if (PHP_SAPI !== 'cli' || !$auth->user->getFromDBbyName('glpi')) {
    fwrite(STDERR, "Run from the command line in the GLPI container ('glpi' user needed).\n");
    exit(1);
}
$auth->auth_succeded = true;
$auth->user_present  = true;
Session::init($auth);

$calendar = new Calendar();
if (!$calendar->getFromDBByCrit(['name' => 'IT Support Business Hours'])) {
    fwrite(STDERR, "Calendar 'IT Support Business Hours' not found — run setup-01-calendar.php first.\n");
    exit(1);
}
$calendars_id = (int) $calendar->getID();

$slm = new SLM();
if ($slm->getFromDBByCrit(['name' => 'IT Support SLM'])) {
    $slms_id = (int) $slm->getID();
    echo "SLM #$slms_id already exists, reusing.\n";
} else {
    $slms_id = $slm->add([
        'name'                => 'IT Support SLM',
        'entities_id'         => 0,
        'is_recursive'        => 1,
        'use_ticket_calendar' => 0,
        'calendars_id'        => $calendars_id,
        'comment'             => 'SLA container for the IT Support priority-based SLA table.',
    ]);
    if (!$slms_id) {
        global $DB;
        fwrite(STDERR, "Failed to create SLM: " . $DB->error() . "\n");
        exit(1);
    }
    echo "SLM #$slms_id created: IT Support SLM\n";
}

// SLM::TTO = 1, SLM::TTR = 0
$table = [
    'Critical' => ['tto' => [15, 'minute'], 'ttr' => [2, 'hour']],
    'High'     => ['tto' => [30, 'minute'], 'ttr' => [4, 'hour']],
    'Medium'   => ['tto' => [2, 'hour'],    'ttr' => [8, 'hour']],
    'Low'      => ['tto' => [4, 'hour'],    'ttr' => [3, 'day']],
];

$sla = new SLA();
foreach ($table as $priority => $times) {
    foreach (['tto' => SLM::TTO, 'ttr' => SLM::TTR] as $key => $type) {
        [$number_time, $definition_time] = $times[$key];
        $name = "$priority - " . strtoupper($key);

        if ($sla->getFromDBByCrit(['slms_id' => $slms_id, 'name' => $name])) {
            echo "SLA #{$sla->getID()} already exists: $name, skipping\n";
            continue;
        }

        $id = $sla->add([
            'name'                => $name,
            'slms_id'             => $slms_id,
            'entities_id'         => 0,
            'is_recursive'        => 1,
            'type'                => $type,
            'number_time'         => $number_time,
            'definition_time'     => $definition_time,
            'use_ticket_calendar' => 0,
            'calendars_id'        => $calendars_id,
            'comment'             => "$priority priority: " . strtoupper($key) . " = $number_time $definition_time(s).",
        ]);
        if (!$id) {
            global $DB;
            fwrite(STDERR, "Failed to create SLA '$name': " . $DB->error() . "\n");
            continue;
        }
        echo "SLA #$id created: $name ($number_time $definition_time)\n";
    }
}

echo "Done.\n";
