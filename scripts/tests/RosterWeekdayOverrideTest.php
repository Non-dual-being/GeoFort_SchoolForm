<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Booking\BookingProgramConfig;
$db = DisposableBookingMariaDb::create('roster_weekday_override');
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $assert = RosterLifecycleFixtures::assert(...);
    $args = ['schoolName'=>'Afwijkende ochtend','city'=>'Test','visitDate'=>'2028-10-10', 'sector'=>'primairOnderwijs','program'=>'ochtend','studentCount'=>60,'groupCount'=>4,'actingAdminId'=>1];
    try { $s['service']->createManual(...$args); throw new RuntimeException('Missing explicit confirmation accepted'); }
    catch (RosterPlanException $e) { $assert($e->publicCode === 'INVALID_MANUAL_PROGRAM_DATE','Wrong refusal'); }
    $plan = $s['service']->createManual(...[...$args,'allowWeekdayOverride'=>true])['plan'];
    $data = $s['raw']->load($plan['id']);
    $result = $s['validator']->validateData($data, $plan['revision']);
    $assert(in_array('PROGRAM_WEEKDAY_OVERRIDE',array_column($result->warnings,'code'),true),'Missing visible weekday warning');
    $assert(!in_array('INVALID_CONTEXT',array_column($result->errors,'code'),true),'Confirmed weekday still blocks');
    $assert(!BookingProgramConfig::isProgramAllowedForWeekday('ochtend',2),'Public booking weekday policy changed');
    try { $s['service']->createManual(...[...$args,'sector'=>'voortgezetOnderbouw','allowWeekdayOverride'=>true]); throw new RuntimeException('Sector restriction bypassed'); }
    catch (RosterPlanException $e) { $assert($e->publicCode === 'INVALID_MANUAL_PROGRAM_FOR_SECTOR','Wrong sector refusal'); }
    echo "PASS explicit dashboard weekday exception, visible warning, public policy and sector restrictions\n";
} finally { $db->drop(); }
