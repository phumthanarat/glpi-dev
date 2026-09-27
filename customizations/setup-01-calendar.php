<?php

/**
 * Step 1 of the ITSM rollout plan: Business Calendar.
 *
 * Creates the working-hours calendar that every SLA/OLA in later steps
 * will be attached to. Without this, GLPI computes SLA/OLA deadlines
 * against a 24/7 calendar, which is wrong for any real office.
 *
 * Monday-Friday 08:30-17:30, with a 12:00-13:00 lunch break excluded
 * (modeled as two segments per day, not one continuous block).
 * Saturday/Sunday: closed (no segments = non-working).
 *
 * Run inside the app container:
 *   php customizations/setup-01-calendar.php
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

$calendar = new Calendar();

// Idempotent: reuse the calendar if this script already ran.
if (!$calendar->getFromDBByCrit(['name' => 'IT Support Business Hours'])) {
    $calendars_id = $calendar->add([
        'name'        => 'IT Support Business Hours',
        'entities_id' => 0,
        'is_recursive' => 1,
        'comment'     => 'Mon-Fri 08:30-17:30, lunch break 12:00-13:00 excluded. Sat/Sun closed.',
    ]);
    if (!$calendars_id) {
        global $DB;
        fwrite(STDERR, "Failed to create calendar: " . $DB->error() . "\n");
        exit(1);
    }
    echo "Calendar #$calendars_id created: IT Support Business Hours\n";
} else {
    $calendars_id = $calendar->getID();
    echo "Calendar #$calendars_id already exists, reusing.\n";
}

// CalendarSegment::day uses PHP date('w'): 0=Sunday .. 6=Saturday.
$WEEKDAYS = [1, 2, 3, 4, 5]; // Monday..Friday
$segment = new CalendarSegment();

foreach ($WEEKDAYS as $day) {
    foreach ([['08:30:00', '12:00:00'], ['13:00:00', '17:30:00']] as [$begin, $end]) {
        $exists = $segment->getFromDBByCrit([
            'calendars_id' => $calendars_id,
            'day'          => $day,
            'begin'        => $begin,
            'end'          => $end,
        ]);
        if ($exists) {
            echo "  segment day=$day $begin-$end already exists, skipping\n";
            continue;
        }
        $id = $segment->add([
            'calendars_id' => $calendars_id,
            'day'          => $day,
            'begin'        => $begin,
            'end'          => $end,
        ]);
        echo "  CalendarSegment #$id: day=$day $begin-$end\n";
    }
}

echo "Done.\n";
