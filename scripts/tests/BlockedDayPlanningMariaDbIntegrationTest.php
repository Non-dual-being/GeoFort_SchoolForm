<?php
declare(strict_types=1);

use GeoFort\Services\Booking\Availability\BookingAvailabilityService;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAdvancedAnalyticsCalculator;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteria;
use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsService;
use GeoFort\Services\Dashboard\Booking\Analytics\CapacityTargetAnalyticsCalculator;
use GeoFort\Services\Dashboard\Booking\DashboardBookingFilters;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportCriteria;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteria;
use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueReportService;
use GeoFort\Services\Dashboard\Calendar\DashboardCalendarOverviewService;
use GeoFort\Services\Dashboard\Calendar\DashboardCalendarService;
use GeoFort\Services\Sql\BookingAnalyticsRepository;
use GeoFort\Services\Sql\BookingCalendarSqlService;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\BookingExportSqlRepository;
use GeoFort\Services\Sql\BookingRevenueReportSqlRepository;
use GeoFort\Services\Sql\CapacityTargetSqlRepository;
use GeoFort\Services\Sql\DashboardBookingDetailSqlService;
use GeoFort\Services\Sql\DashboardBookingSqlService;
use GeoFort\Services\Sql\DashboardCalendarOverviewSqlRepository;
use GeoFort\Services\Sql\DashboardCalendarSqlService;
use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Sql\AdminUsersSqlService;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Http\Api\Admin\DashboardCalendarAction;
use GeoFort\Services\Http\Api\Admin\DashboardCalendarOverviewAction;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Http\Redirect\HeaderRedirector;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Validation\FieldValidationException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

// Never load .env/bootstrap or connect to an existing application database.
if (!in_array(getenv('STATUS_TEST_DB_HOST') ?: '127.0.0.1', ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Alleen een lokale disposable testdatabase is toegestaan.');
}
// A child process is required because the real unauthenticated redirect exits.
// It may connect only to this suite's freshly created disposable database.
if (($argv[1] ?? '') === '--probe-calendar-read') {
    $databaseName = (string) getenv('STATUS_TEST_DB_NAME');
    if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
        || strtolower((string) getenv('APP_ENV')) === 'production'
        || preg_match('/^geofort_blocked_planning_disposable_test_[a-f0-9]{8}$/', $databaseName) !== 1) {
        throw new RuntimeException('Read-probe vereist de disposable database van deze suite.');
    }
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('STATUS_TEST_DB_HOST'), getenv('STATUS_TEST_DB_PORT'), $databaseName),
        (string) getenv('STATUS_TEST_DB_USER'), (string) (getenv('STATUS_TEST_DB_PASSWORD') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    ini_set('session.save_path', sys_get_temp_dir());
    $_SERVER['HTTP_USER_AGENT'] = 'blocked-planning-test';
    $auth = new AuthMiddleware('geofort_blocked_planning_probe', 'Lax', false);
    $auth->startPublicSession();
    if (($argv[3] ?? '') !== 'anonymous') {
        $auth->establishAuthenticatedSession($pdo->query('SELECT id,email,name,role FROM admin_users WHERE id=1')->fetch(), null, 'blocked-planning-test');
        if (($argv[3] ?? '') === 'invalid') unset($_SESSION['user_id']);
    }
    ob_start();
    register_shutdown_function(static function (): void {
        $body = (string) ob_get_clean();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        echo json_encode(['status' => http_response_code(), 'body' => $body === '' ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
    });
    $baseUrl = new EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test');
    $private = new PrivatePageBootstrapper($pdo, $auth, new SessionGuard(new AdminUsersSqlService($pdo, 10), 1800, 300), new HeaderRedirector($baseUrl));
    $disabled = new DisabledDatesSqlService($pdo);
    $method = $argv[4] ?? 'GET';
    if (($argv[2] ?? '') === 'overview') {
        (new DashboardCalendarOverviewAction($private, new DashboardCalendarOverviewService(new DashboardCalendarOverviewSqlRepository($pdo), $disabled, new BookingDaySettingsSqlRepository($pdo)), new JsonResponse($baseUrl)))
            ->send($method, ['year'=>'2027','month'=>'3']);
    } else {
        (new DashboardCalendarAction($private, new DashboardCalendarService(new DashboardCalendarSqlService($pdo), new BookingCalendarSqlService($pdo), new BookingDaySettingsSqlRepository($pdo), $disabled), new JsonResponse($baseUrl)))
            ->send($method, ['startDate'=>'2027-03-08','endDate'=>'2027-03-08']);
    }
    exit;
}
$database = DisposableBookingMariaDb::create('blocked_planning');
$pdo = $database->pdo;
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException($message);
};

try {
    $assert(str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB'), 'MariaDB vereist.');
    $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/sql/2026-08-07_create_capacity_targets.sql'));
    $pdo->exec("INSERT INTO capacity_targets(effective_date,students_per_available_day,bookings_per_available_day,created_by_admin_id,updated_by_admin_id)
        VALUES ('2027-03-01',100,1.0,1,1),('2027-03-10',120,1.5,1,1)");
    $disabled = new DisabledDatesSqlService($pdo);
    $settings = new BookingDaySettingsSqlRepository($pdo);
    $capacity = new BookingCalendarSqlService($pdo);
    $exports = new BookingExportSqlRepository($pdo);
    $analytics = new BookingAnalyticsService(new BookingAnalyticsRepository($pdo), $exports,
        advancedCalculator: new BookingAdvancedAnalyticsCalculator($disabled, $settings, clock: new DateTimeImmutable('2027-04-01')),
        targetCalculator: new CapacityTargetAnalyticsCalculator(new CapacityTargetSqlRepository($pdo)));
    $calendar = new DashboardCalendarService(new DashboardCalendarSqlService($pdo), $capacity, $settings, $disabled);
    $overview = new DashboardCalendarOverviewService(new DashboardCalendarOverviewSqlRepository($pdo), $disabled, $settings);
    $revenue = new BookingRevenueReportService(new BookingRevenueReportSqlRepository($pdo));
    $analyze = static fn (string $date, string $population = 'planning', string $program = 'all', string $sector = 'all') => $analytics->analyze(
        new BookingAnalyticsCriteria($date, $date, $date, $date, new BookingExportDateBounds($date, $date), $sector, $population, $program));
    $report = static fn (string $date) => $revenue->report(new BookingRevenueCriteria($date, $date, 'combined'), new BookingExportDateBounds($date, $date));
    $insert = $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,aantal_leerlingen)
        VALUES (?, 'Synthetische testschool','NL','Teststraat 1','1234AB','Testplaats','1','2','Test','Contact','test@example.test',?,'primairOnderwijs',?,?)");
    $book = static function (string $date, string $status = 'Definitief', int $students = 60, string $program = 'dag') use ($pdo, $insert): int {
        $insert->execute([$status, $date, $program, $students]);
        return (int) $pdo->lastInsertId();
    };
    $block = static function (string $date, string $type = 'manual', string $source = 'planner') use ($pdo): void {
        $pdo->prepare('INSERT INTO disabled_dates(datum,type,source) VALUES (?,?,?) ON DUPLICATE KEY UPDATE type=VALUES(type),source=VALUES(source)')->execute([$date, $type, $source]);
    };
    $snapshot = $pdo->prepare("INSERT INTO booking_price_snapshots(booking_id,sequence_number,previous_snapshot_id,snapshot_reason,calculation_state,pricing_version,currency_code,vat_meaning,vat_basis_points,visit_amount_incl_vat_cents,catering_amount_incl_vat_cents,total_amount_incl_vat_cents,total_amount_excl_vat_cents,vat_amount_cents,canonical_input_json,calculation_details_json,input_checksum,checksum_format_version)
        VALUES (?,?,?,'submission','complete','test','EUR','included',900,60000,5400,65400,60000,5400,'{}','{}',?,1)");
    $price = static function (int $id) use ($snapshot, $pdo): void {
        $snapshot->execute([$id, 1, null, str_repeat('a', 64)]);
        $previous = (int) $pdo->lastInsertId();
        $snapshot->execute([$id, 2, $previous, str_repeat('b', 64)]);
    };
    $date = '2027-03-08';
    $id = $book($date);
    $price($id);
    // Multiple education and price-history records must not duplicate a booking.
    $pdo->exec("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,level_key,group_key) VALUES ({$id},'test','a'),({$id},'test','b')");
    $free = $analyze($date);
    $freeRevenue = $report($date);
    // Sector filters select actuals, never the day capacity or official target.
    $freeBySector = [];
    foreach (['all', 'primairOnderwijs', 'voortgezetOnderbouw'] as $sector) {
        $freeBySector[$sector] = $analyze($date, population: 'confirmed', program: 'dag', sector: $sector);
        $selectedBookings = $sector === 'voortgezetOnderbouw' ? 0 : 1;
        $selectedStudents = $selectedBookings * 60;
        $result = $freeBySector[$sector];
        $row = $result->capacityTargetByMonth[0];
        $assert($result->summary->activeBookings === $selectedBookings && $result->summary->plannedStudents === $selectedStudents, "Vrije dag: sector {$sector} selecteert aantallen niet correct.");
        $assert($row['availableDays'] === 1 && $row['evaluatedAvailableDays'] === 1 && $row['technicalCapacity'] === ['students'=>160,'bookings'=>2], "Vrije dag: sector {$sector} wijzigt technische daggrondslag.");
        $assert($row['targetAvailableDays'] === 1 && $row['studentsTargetComparison']['target'] === 100 && $row['bookingsTargetComparison']['target'] === 1.0, "Vrije dag: sector {$sector} wijzigt targetgrondslag.");
        $assert($row['students']['actual'] === $selectedStudents && $row['bookingSlots']['actual'] === $selectedBookings && $row['studentsTargetComparison']['actual'] === $selectedStudents && $row['bookingsTargetComparison']['actual'] === $selectedBookings, "Vrije dag: sector {$sector} lekt aantallen in capaciteit of targetvergelijking.");
    }
    $exportCriteria = new BookingExportCriteria($date, $date, $date, $date, new BookingExportDateBounds($date, $date));
    $freeExport = $exports->summarize($exportCriteria);
    $freeRows = $exports->openExportCursor($exportCriteria)->fetchAll();
    foreach ([['manual','planner'], ['school_vacation','planner'], ['school_vacation','generated']] as [$type, $source]) {
        $block($date, $type, $source);
        $closed = $analyze($date);
        $assert($closed->summary->toArray() === $free->summary->toArray(), 'Blokkade wijzigt aantallen of leerlingen.');
        $assert($closed->capacityByMonth === $free->capacityByMonth && $closed->capacityTargetByMonth === $free->capacityTargetByMonth, 'Blokkade verwijdert dagcapaciteit of target.');
        foreach ($freeBySector as $sector => $freeSector) {
            $closedSector = $analyze($date, population: 'confirmed', program: 'dag', sector: $sector);
            $assert($closedSector->summary->toArray() === $freeSector->summary->toArray(), "{$type}/{$source}: sector {$sector} wijzigt geselecteerde aantallen na blokkeren.");
            $assert($closedSector->capacityByMonth === $freeSector->capacityByMonth, "{$type}/{$source}: sector {$sector} wijzigt technische capaciteit na blokkeren.");
            $assert($closedSector->capacityTargetByMonth === $freeSector->capacityTargetByMonth, "{$type}/{$source}: sector {$sector} wijzigt officiële targets na blokkeren.");
            $snapshots = $closedSector->capacityTargetContext['daySnapshots'];
            $assert(count($snapshots) === 1 && !$snapshots[0]['available'] && $snapshots[0]['countsForCapacity'] && $snapshots[0]['studentsActual'] === $freeSector->summary->plannedStudents, "{$type}/{$source}: sector {$sector} verwijdert de daggrondslag uit targetscenario's.");
        }
        $assert($report($date) == $freeRevenue, 'Blokkade wijzigt definitieve omzet.');
        $assert($exports->summarize($exportCriteria) === $freeExport && $exports->openExportCursor($exportCriteria)->fetchAll() === $freeRows, 'Export sluit geblokkeerde planning uit.');
        $assert(count($freeRows) === 1 && count($revenue->exportRows(new BookingRevenueCriteria($date, $date))) === 1, 'Historie/selecties dupliceren exportboekingen.');
        $day = $calendar->get($date, $date, new DateTimeImmutable('2027-03-01'))['days'][0];
        $assert($day['state'] === 'blocked' && $day['bookingCount'] === 1 && $day['confirmedStudentCount'] === 60 && count($day['bookings']) === 1 && $day['maximumCapacity'] === 160, 'Dagdetails verbergen of verkleinen bestaande planning.');
        $days = $overview->get(2027, 3)->toArray()['days'];
        $cell = array_values(array_filter($days, static fn (array $day): bool => $day['date'] === $date))[0];
        $assert($cell['disabled'] !== null && $cell['hasBookingsOnBlockedDate'] && $cell['aggregates'][0]['studentCount'] === 60, 'Overzicht mist blokkade of planning.');
    }
    $assert($analyze($date, population: 'confirmed', program: 'ochtend', sector: 'voortgezetOnderbouw')->capacityTargetByMonth[0]['availableDays'] === 0, 'Planning buiten de programmascope activeert een gesloten dag.');
    $filters = new DashboardBookingFilters(null, null, null, null, null, $date, $date, 1, 25);
    $assert((new DashboardBookingSqlService($pdo))->countBookings($filters) === 1 && count((new DashboardBookingSqlService($pdo))->findBookings($filters,25,0)) === 1, 'Aanvragenoverzicht verliest geblokkeerde boeking.');
    $assert((new DashboardBookingDetailSqlService($pdo))->findBooking($id) !== null, 'Boekingsdetails onbereikbaar.');
    $assert($freeRevenue->definitiveRevenue['totalInclVatCents'] === 65400 && $freeRevenue->counts['studentsTotal'] === 60, 'Testomzet of aantal wordt dubbel geteld.');
    putenv('STATUS_TEST_DB_NAME=' . $database->database);
    foreach (['overview', 'details'] as $endpoint) {
        foreach ([['anonymous','GET',303], ['invalid','GET',303], ['valid','POST',405], ['valid','GET',200]] as [$session, $method, $expectedStatus]) {
            $process = proc_open([PHP_BINARY, __FILE__, '--probe-calendar-read', $endpoint, $session, $method], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Read-probe kon niet starten.');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $assert(proc_close($process) === 0, 'Read-probe faalt: ' . $stderr);
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $assert($result['status'] === $expectedStatus, "Werkelijke {$endpoint}-action bewaart sessie- of methoderegel niet.");
            if ($expectedStatus === 303) $assert($result['body'] === null, 'Niet-geautoriseerde lezer ontvangt kalenderdata.');
            if ($expectedStatus === 200) {
                $days = $result['body']['data']['calendar']['days'];
                $readDay = array_values(array_filter($days, static fn (array $day): bool => $day['date'] === '2027-03-08'))[0];
                $assert(($readDay['aggregates'][0]['bookingCount'] ?? $readDay['bookingCount']) === 1, 'Geautoriseerde kalenderaction verbergt planning.');
            }
        }
    }
    $second = $book($date, students: 40);
    $price($second);
    $two = $analyze($date);
    $row = $two->capacityTargetByMonth[0];
    $assert($row['availableDays'] === 1 && $row['students']['actual'] === 100 && $row['bookingSlots']['actual'] === 2, 'Meerdere boekingen verdubbelen dagen of tellen niet mee.');
    $assert($row['technicalCapacity'] === ['students'=>160,'bookings'=>2] && $row['studentsTargetComparison']['target'] === 100 && $row['bookingsTargetComparison']['target'] === 1.0, 'Dagcapaciteit of effectief target wordt dubbel geteld.');
    $snap = $two->capacityTargetContext['daySnapshots'][0];
    $assert(!$snap['available'] && $snap['countsForCapacity'], 'Boekbaarheid en capaciteitsgrondslag zijn niet gescheiden.');
    $pdo->prepare("UPDATE aanvragen SET status='Afgewezen' WHERE id IN (?,?)")->execute([$id,$second]);
    $assert($analyze($date)->capacityByMonth[0]['availableDays'] === 0 && $report($date)->counts['bookingsTotal'] === 0, 'Laatste afwijzing laat gesloten dag of omzet meetellen.');
    $assert($analyze($date, 'all')->capacityByMonth[0]['availableDays'] === 0, 'Afgewezen records activeren gesloten dag in alle-statussenweergave.');
    $assert($analyze($date, population: 'all', sector: 'voortgezetOnderbouw')->capacityTargetByMonth[0]['targetAvailableDays'] === 0, 'Afgewezen records uit een andere sector activeren een targetdag.');
    $option = $book($date, 'In optie', 30);
    $price($option);
    $assert($analyze($date)->capacityByMonth[0]['availableDays'] === 1 && $analyze($date, 'confirmed')->capacityByMonth[0]['availableDays'] === 0, 'Optie volgt geselecteerde populatie niet.');
    $assert($capacity->getBookingStatsForDate($date)['bookedSchools'] === 0, 'Optie reserveert ineens technische capaciteit.');
    $assert($report($date)->potentialRevenue['totalInclVatCents'] === 65400 && $report($date)->definitiveRevenue['totalInclVatCents'] === 0, 'Optie wordt geen afzonderlijke potentiële omzet.');
    $optionOtherSector = $analyze($date, sector: 'voortgezetOnderbouw')->capacityTargetByMonth[0];
    $assert($optionOtherSector['availableDays'] === 1 && $optionOtherSector['technicalCapacity'] === ['students'=>160,'bookings'=>2] && $optionOtherSector['targetAvailableDays'] === 1 && $optionOtherSector['studentsTargetComparison']['target'] === 100 && $optionOtherSector['students']['actual'] === 0, 'Sectorfilter verwijdert capaciteits- of targetdag van meetellende optie.');
    $assert($analyze($date, population: 'confirmed', sector: 'voortgezetOnderbouw')->capacityTargetByMonth[0]['availableDays'] === 0, 'Optie uit een andere sector activeert een dag buiten de statuspopulatie.');
    $pdo->prepare("UPDATE aanvragen SET bezoekdatum='2027-03-09' WHERE id=?")->execute([$option]);
    $assert($analyze($date)->capacityByMonth[0]['availableDays'] === 0, 'Verplaatsing laatste optie laat gesloten dag meetellen.');
    $pdo->prepare("UPDATE aanvragen SET status='Afgewezen' WHERE id=?")->execute([$option]);
    $assert($analyze('2027-03-09')->capacityByMonth[0]['availableDays'] === 1, 'Gewone beschikbare dag verdwijnt na laatste afwijzing.');
    $block('2027-03-11');
    $assert($analyze('2027-03-11')->capacityByMonth[0]['availableDays'] === 0, 'Lege blokkade activeert dag.');

    foreach (['2027-03-13','2027-03-14'] as $weekend) {
        $book($weekend);
        // Test both an explicit weekend record and a weekend without seeded record.
        if ($weekend === '2027-03-13') $block($weekend, 'weekend', 'generated');
        $row = $analyze($weekend)->capacityTargetByMonth[0];
        $assert($row['availableDays'] === 1 && $row['technicalCapacity'] === ['students'=>160,'bookings'=>2], 'Weekendplanning verliest onderliggende capaciteit.');
        $assert($row['studentsTargetComparison']['target'] === 120 && $row['bookingsTargetComparison']['target'] === 1.5, 'Weekend gebruikt niet het target van de bezoekdatum.');
        $assert(array_sum(array_column($analyze($weekend)->weekdayDistribution, 'activeBookings')) === 1, 'Weekdagrapport verbergt weekendbezoek.');
    }
    $block('2027-03-10', 'school_vacation', 'generated');
    $book('2027-03-10', students: 60, program: 'ochtend');
    $pdo->exec("INSERT INTO booking_day_settings(visit_date,max_schools_override,max_students_override) VALUES ('2027-03-10',1,100),('2027-03-13',3,220)");
    $assert($analyze('2027-03-10')->capacityTargetByMonth[0]['technicalCapacity'] === ['students'=>100,'bookings'=>1], 'Expliciete dagoverride wordt vervangen.');
    $assert($analyze('2027-03-10', program: 'ochtend')->capacityTargetByMonth[0]['technicalCapacity'] === ['students'=>80,'bookings'=>1], 'Ochtendgrens vervalt op vakantiedag.');
    $assert($analyze('2027-03-13')->capacityTargetByMonth[0]['technicalCapacity'] === ['students'=>220,'bookings'=>3], 'Weekendoverride wordt door sluiting op nul gezet.');
    $overviewDays = array_column($overview->get(2027, 3)->toArray()['days'], null, 'date');
    foreach (['2027-03-10'=>100, '2027-03-13'=>220] as $visitDate => $studentsCapacity) {
        $assert($overviewDays[$visitDate]['capacity'] === ['totalDaily'=>$studentsCapacity,'programs'=>['dag'=>$studentsCapacity,'ochtend'=>80]], 'Kalendercel verliest expliciete dagcapaciteit of ochtendgrens.');
        $assert($calendar->get($visitDate, $visitDate)['days'][0]['maximumCapacity'] === $studentsCapacity, 'Dagdetails en kalendercapaciteit verschillen.');
    }
    $book('2027-03-15', program: 'ochtend');
    $assert($analyze('2027-03-15', program: 'ochtend')->capacityByMonth[0]['students']['capacity'] === 80, 'Niet-boekbare programmaweekdag verliest bestaande planningcapaciteit.');
    $block('2027-01-29');
    $book('2027-01-29');
    $missing = $analyze('2027-01-29')->capacityTargetByMonth[0];
    $assert($missing['availableDays'] === 1 && $missing['students']['capacity'] === 160 && $missing['studentsTargetComparison']['target'] === null && $missing['studentsTargetComparison']['assessment'] === 'missingTarget', 'Ontbrekend target wordt verzonnen of verwijdert capaciteit.');

    // Public validation still denies a future blocked date containing planning.
    $future = (new DateTimeImmutable('today'))->modify('+1 month')->modify('next monday')->format('Y-m-d');
    $availability = new BookingAvailabilityService($capacity, $disabled);
    foreach (['manual','school_vacation'] as $type) {
        $block($future, $type);
        if ($type === 'manual') $book($future);
        try { $availability->assertDateIsValid($future); throw new RuntimeException('Publieke blokkade omzeild.'); }
        catch (FieldValidationException $exception) { $assert($exception->getMessage() === 'De gekozen datum is niet beschikbaar.', 'Onverwachte publieke afwijzingsreden.'); }
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM booking_status_history')->fetchColumn() === 0, 'Leesberekeningen schrijven auditdata.');
    echo "OK: blocked-day planning ({$assertions} controles, MariaDB, PHP " . PHP_VERSION . ").\n";
    echo "Voorbeeld: 2 definitief / 100 leerlingen = 1 dag, capaciteit 160 / 2, target 100 / 1; omzet per testsnapshot EUR 654.00.\n";
} finally {
    $database->drop();
}
