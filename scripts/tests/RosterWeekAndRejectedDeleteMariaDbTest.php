<?php
declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{RosterWeekExportService, RosterPlanException};
use GeoFort\Services\Sql\RosterPairSqlRepository;

$db = DisposableBookingMariaDb::create('roster_week_delete');
$assert = RosterLifecycleFixtures::assert(...);
$reject = static function(callable $call, string $code) use ($assert): void {
    try { $call(); throw new RuntimeException('Expected rejection: ' . $code); }
    catch (RosterPlanException $error) { $assert($error->publicCode === $code, 'Unexpected error: ' . $error->publicCode); }
};
try {
    $pdo = $db->pdo; RosterLifecycleFixtures::schema($pdo); $s = RosterLifecycleFixtures::services($pdo);
    $insert = $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,aantal_leerlingen) VALUES (?,?,'NL','Teststraat 1','1234AB','Testplaats','0612345678','0612345678','Test','Planner','test@example.test',?,'primairOnderwijs','dag',28)");
    $plans = []; $books = [];
    foreach ([['In optie','2027-01-11'], ['Definitief','2027-01-11'], ['Afgewezen','2027-01-18'], ['In optie','2027-01-13']] as $i => [$status,$date]) {
        $insert->execute([$status, 'Testschool ' . $i, $date]); $books[$i] = (int)$pdo->lastInsertId();
        $plans[$i] = RosterLifecycleFixtures::validPlan($pdo, $s, 28, 2, 2, $date, $books[$i]);
    }
    $final = $s['lifecycle']->finalize($plans[0]['id'], $plans[0]['revision'], 1);
    $snapshot = $s['snapshots']->state($final['id'])['current'];
    $week = new RosterWeekExportService($pdo, $s['service']);
    $assert(array_column($week->weeks(), 'key') === ['2027-W02'], 'Only eligible weeks');
    $assert($week->weeks()[0]['rosterCount'] === 3, 'Only eligible schools');
    $export = $week->export('2027-W02');
    $assert(count($export['plans']) === 3, 'All eligible rosters exported');
    $assert($export['plans'][0]['status'] === 'published', 'Final snapshot retained');
    $reject(fn() => $week->export('2027-W03'), 'EMPTY_WEEK');
    $reject(fn() => $s['service']->deleteConcept($final['id'], 1), 'PLAN_NOT_DELETABLE');
    $pairs = new RosterPairSqlRepository($pdo);
    $pdo->beginTransaction(); $pair = $pairs->create([$final['id'], $plans[1]['id']], '2027-01-11', 'lessons_first', 1); $pdo->commit();
    $other = $s['raw']->load($plans[1]['id']);
    $pdo->prepare("UPDATE aanvragen SET status='Afgewezen' WHERE id=?")->execute([$books[0]]);
    $s['service']->deleteConcept($final['id'], 1);
    $assert($s['service']->getPlan($final['id']) === null, 'Deleted final unavailable');
    $assert(!in_array($final['id'], array_column($s['service']->listPlans(),'id'),true), 'Deleted final hidden');
    $assert(!in_array($final['id'], array_column($s['raw']->otherPlans('2027-01-11', $plans[1]['id']), 'id')), 'Deleted plan no longer occupies staff');
    $assert($s['raw']->load($final['id'])['sessions'] === [], 'Mutable lessons cleared');
    $assert($s['snapshots']->find($final['id'], (int)$snapshot['id'])['content_json'] === $snapshot['content_json'], 'Final history unchanged');
    $after = $s['raw']->load($plans[1]['id']);
    foreach (['sessions','sessionStaff','groupStaff','groups'] as $key) $assert($other[$key] === $after[$key], 'Other school changed: ' . $key);
    $assert($pairs->forPlan($plans[1]['id']) === null, 'Surviving school independent');
    $assert($s['bookings']->findById($books[0])?->status === 'Afgewezen', 'Booking retained');
    $assert($s['plans']->findIdByBookingId($books[0]) === null, 'Booking released');
    $reject(fn() => $s['service']->deleteConcept($final['id'], 1), 'PLAN_NOT_FOUND');
    $s['service']->deleteConcept($plans[2]['id'], 1);
    $assert($s['plans']->findById($plans[2]['id']) === null, 'Rejected concept removed');
    $assert($pdo->query('SELECT COUNT(*) FROM aanvragen')->fetchColumn() === 4, 'All requests retained');
    $assert(!$pdo->inTransaction(), 'Transaction leaked');
    echo "PASS: week eligibility, published snapshots, rejected deletion and independent surviving school\n";
} finally { $db->drop(); }
