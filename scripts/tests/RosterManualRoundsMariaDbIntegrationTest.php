<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Http\Api\Admin\DashboardRosterManualProgramSaveRequest;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$root = dirname(__DIR__, 2);
$disposable = null;
$databaseName = (string) getenv('STATUS_TEST_DB_NAME');
if ($databaseName !== '') {
    if (
        $databaseName !== 'geoform_roster_test'
        || getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
        || strtolower((string) getenv('APP_ENV')) === 'production'
        || !in_array(getenv('STATUS_TEST_DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)
    ) {
        throw new RuntimeException('Alleen de expliciete lokale geoform_roster_test is toegestaan.');
    }
    $connect = static fn (): PDO => new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=geoform_roster_test;charset=utf8mb4', getenv('STATUS_TEST_DB_HOST'), getenv('STATUS_TEST_DB_PORT')),
        (string) getenv('STATUS_TEST_DB_USER'),
        (string) (getenv('STATUS_TEST_DB_PASSWORD') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $pdo = $connect();
} else {
    $disposable = DisposableBookingMariaDb::create('roster_manual_rounds_v8b2c1');
    $pdo = $disposable->pdo;
    $connect = $disposable->connect(...);
}

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$makeService = static fn (PDO $connection): RosterPlanService => new RosterPlanService(
    $connection,
    new StoredBookingSqlRepository($connection, new StoredBookingAssembler()),
    new RosterPlanSqlRepository($connection),
    new RosterGroupCountResolver(),
    new RosterSessionSqlRepository($connection),
    new RosterPlanningConfig(),
    new RosterGenerationTemplateProvider(),
);
$migrate = static fn (string $file) => $pdo->exec((string) file_get_contents($root . '/database/sql/' . $file));
$fixturePlanIds = [];
$fixtureBookingId = null;
$existingBookings = null;
$existingPlans = null;
$existingContexts = null;

try {
    if ($disposable !== null) {
        foreach ([
            '2026-09-21_create_roster_planning_foundation.sql',
            '2026-09-21_create_roster_session_planning.sql',
            '2026-09-26_create_roster_manual_plan_context.sql',
        ] as $migration) $migrate($migration);
    }

    $existingBookings = $pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll();
    $existingPlans = $pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll();
    $existingContexts = $pdo->query('SELECT * FROM roster_manual_plan_context ORDER BY roster_plan_id')->fetchAll();
    $adminId = (int) $pdo->query('SELECT id FROM admin_users ORDER BY id LIMIT 1')->fetchColumn();
    $assert($adminId > 0, 'Een testbeheerder ontbreekt.');
    $service = $makeService($pdo);
    $plans = new RosterPlanSqlRepository($pdo);
    $templates = new RosterGenerationTemplateProvider();
    $create = static function (string $sector = 'primairOnderwijs', string $program = 'dag') use ($service, $adminId, &$fixturePlanIds): array {
        $result = $service->createManual('V8B2C1 Tijdlijn Testschool', 'Gorinchem', '2026-11-11', $sector, $program, 90, 6, $adminId);
        $fixturePlanIds[] = $result['plan']['id'];
        return $result['plan'];
    };
    $save = static function (array $payload) use ($service, $adminId): array {
        $request = DashboardRosterManualProgramSaveRequest::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
        return $service->saveManualProgram($request->planId, $request->expectedRevision, $request->moduleKeys, $adminId, $request->timeline);
    };
    $reject = static function (array $payload, string $code, int $status) use ($save, $assert): void {
        try {
            $save($payload);
            throw new RuntimeException('Ongeldige roosterwijziging werd geaccepteerd: ' . $code);
        } catch (RosterPlanException $exception) {
            $assert($exception->publicCode === $code && $exception->httpStatus === $status, 'Onverwachte foutcode: ' . $exception->publicCode);
        }
    };
    $rounds = $templates->defaultTimeline('dag', 4);
    $roundsFive = $templates->defaultTimeline('dag', 5);
    $fourModules = ['Klimaat-Experience', 'Klimparcours', 'Voedsel-Innovatie', 'Dynamische-Globe'];
    $fiveModules = ['Minecraft-Programmeren', ...$fourModules];

    $created = $create();
    $planId = $created['id'];
    $assert($created['planning']['rounds'] === $templates->defaultTimeline('dag'), 'Default-dagindeling ontbreekt.');
    $assert($created['planning']['roundsSource'] === 'default', 'Nieuwe context moet defaults herkennen.');
    if ($disposable !== null) {
        $legacy = $plans->manualContextForPlan($planId);
        $assert($legacy['module_keys_json'] === null && $legacy['rounds_json'] === null, 'Lezen vóór beide JSON-migraties faalt.');
        $migrate('2026-09-26_add_roster_manual_program_modules.sql');
    }
    $saved = $save(['planId' => $planId, 'expectedRevision' => 1, 'moduleKeys' => $fourModules]);
    $moduleJson = $plans->manualContextForPlan($planId)['module_keys_json'];
    $assert(array_column($saved['planning']['modules'], 'key') === $fourModules, 'Vier modules zijn niet geldig.');
    $assert($saved['planning']['rounds'] === $templates->defaultTimeline('dag', 4), 'Vier modules moeten een vier-ronde default krijgen.');

    if ($disposable !== null) {
        // Een ontbrekende rounds-kolom mag een gecombineerde wijziging niet half opslaan.
        try {
            $save(['planId' => $planId, 'expectedRevision' => 2, 'moduleKeys' => $fiveModules, 'rounds' => $roundsFive]);
            throw new LogicException('Schrijven zonder rounds-migratie had moeten falen.');
        } catch (RuntimeException $exception) {
            $assert(!$exception instanceof RosterPlanException, 'Ontbrekende kolom gaf een onverwachte domeinfout.');
        }
        $assert($service->getPlan($planId)['revision'] === 2 && $plans->manualContextForPlan($planId)['module_keys_json'] === $moduleJson, 'Mislukte gecombineerde wijziging is niet teruggedraaid.');
        $migrate('2026-09-26_add_roster_manual_rounds.sql');
    }
    $assert($plans->manualContextForPlan($planId)['rounds_json'] === null, 'Bestaande context moet NULL behouden na migreren/lezen.');
    $assert($plans->manualContextForPlan($planId)['module_keys_json'] === $moduleJson, 'Migratie veranderde modules.');

    $saved = $save(['planId' => $planId, 'expectedRevision' => 2, 'rounds' => $rounds]);
    $assert($saved['revision'] === 3 && $saved['planning']['roundsSource'] === 'saved', 'Dagindeling opslaan moet revisie en bron bijwerken.');
    $assert($saved['planning']['rounds'] === $rounds, 'Opgeslagen betekenis/volgorde wijkt af.');
    $context = $plans->manualContextForPlan($planId);
    $assert($context['module_keys_json'] === $moduleJson, 'Alleen dagindeling wijzigen overschrijft modules.');
    $assert(json_decode($context['rounds_json'], true, 32, JSON_THROW_ON_ERROR) == $rounds, 'rounds_json heeft een andere inhoud.');
    $assert($saved['planning']['sessions'] === [], 'Tijdlijn opslaan mag geen sessies aanmaken.');

    $reopened = $makeService($connect())->getPlan($planId);
    $assert($reopened['planning']['rounds'] === $rounds && $reopened['planning']['roundsSource'] === 'saved', 'Heropenen via nieuwe verbinding verliest de opgeslagen tijdlijn.');
    $roundsJson = $context['rounds_json'];
    $saved = $save(['planId' => $planId, 'expectedRevision' => 3, 'moduleKeys' => $fiveModules]);
    $assert(array_column($saved['planning']['modules'], 'key') === $fiveModules, 'Vijf modules zijn niet geldig.');
    $assert(
    $saved['planning']['rounds'] === $templates->defaultTimeline('dag', 5),
    'Wijziging van vier naar vijf modules moet de standaarddag opnieuw afleiden.',
);
$assert(
    $plans->manualContextForPlan($planId)['rounds_json'] === null,
    'Een opgeslagen dagindeling moet bij gewijzigd moduleaantal worden gereset.',
);

    $base = ['planId' => $planId, 'expectedRevision' => 4];
    $invalidCases = [
        ['INVALID_MANUAL_ROUNDS', 'not-an-array'],
        ['INVALID_MANUAL_BLOCK_TYPE', [array_replace($rounds[1], ['type' => 'unknown'])]],
        ['INVALID_MANUAL_BLOCK_LABEL', [array_replace($rounds[1], ['label' => ' '])]],
        ['INVALID_TIME', [array_replace($rounds[1], ['startTime' => '25:00'])]],
        ['INVALID_TIME_RANGE', [array_replace($rounds[1], ['endTime' => '10:00'])]],
        ['INVALID_TIME_RANGE', [array_replace($rounds[1], ['endTime' => '10:15'])]],
        ['INVALID_MANUAL_BLOCK_ORDER', [$rounds[1], array_replace($rounds[3], ['startTime' => '10:55'])]],
        ['INVALID_MANUAL_BLOCK_ORDER', [$rounds[3], $rounds[1]]],
        ['MANUAL_ROUND_REQUIRED', [$rounds[0]]],
    ];
    foreach ($invalidCases as [$code, $invalid]) $reject($base + ['rounds' => $invalid], $code, 422);
    $reject(
    $base + ['moduleKeys' => ['Klimaat-Experience'], 'rounds' => $rounds],
    'INVALID_MANUAL_MODULE_COUNT',
    422,
);
$reject(
    $base + ['moduleKeys' => ['unknown', 'Klimaat-Experience'], 'rounds' => $rounds],
    'INVALID_MANUAL_MODULE_FOR_PROGRAM',
    422,
);
    $wrongStructure = $roundsFive;
    $wrongStructure[2]['type'] = 'round';
    $reject(
        $base + ['rounds' => $wrongStructure],
        'MANUAL_TIMELINE_STRUCTURE_MISMATCH',
        422,
    );
    $reject(['planId' => $planId, 'expectedRevision' => 3, 'rounds' => $rounds], 'REVISION_CONFLICT', 409);
    $assert($service->getPlan($planId)['revision'] === 4 && $plans->manualContextForPlan($planId)['rounds_json'] === null, 'Afgewezen invoer veranderde het plan.');

    $updatedRounds = $rounds;
    $updatedRounds[1]['label'] = 'Gewijzigde ronde';
    $saved = $save($base + ['moduleKeys' => $fourModules, 'rounds' => $updatedRounds]);
    $assert($saved['revision'] === 5 && $saved['planning']['rounds'] === $updatedRounds && array_column($saved['planning']['modules'], 'key') === $fourModules, 'Gecombineerde update is niet atomair opgeslagen.');
    $assert($makeService($connect())->getPlan($planId)['planning']['rounds'] === $updatedRounds, 'Opnieuw opslaan/heropenen verliest de wijziging.');

    foreach ([['primairOnderwijs', 'ochtend'], ['voortgezetOnderbouw', 'dag'], ['voortgezetBovenbouw', 'dag']] as [$sector, $program]) {
        $plan = $create($sector, $program);
        $assert($plan['planning']['rounds'] === $templates->defaultTimeline($program), 'Default past niet bij sector/programma.');
        $assert($plans->manualContextForPlan($plan['id'])['rounds_json'] === null, 'Defaults mogen niet ongemerkt worden opgeslagen.');
    }

    $pdo->exec(<<<'SQL'
        INSERT INTO aanvragen (
            schoolnaam, land, adres, postcode, plaats, school_telefoonnummer,
            contactpersoon_telefoonnummer, contactpersoon_voornaam, contactpersoon_achternaam,
            email, bezoekdatum, onderwijs_sector, programma, keuzemodule_key, aantal_leerlingen
        ) VALUES (
            'V8B2C1 Booking Fixture', 'Nederland', 'Testweg 1', '1234 AB', 'Gorinchem', '0100000000',
            '0612345678', 'Test', 'Planner', 'v8b2c1@example.test', '2026-11-11',
            'primairOnderwijs', 'dag', 'Earth-Watch', 90
        )
        SQL);
    $fixtureBookingId = (int) $pdo->lastInsertId();
    $bookingPlan = $service->createFromBooking($fixtureBookingId, $adminId)['plan'];
    $fixturePlanIds[] = $bookingPlan['id'];
    $assert(!array_key_exists('rounds', $bookingPlan['planning']), 'Booking-based response mag geen zelfstandige dagindeling krijgen.');
    $reject(['planId' => $bookingPlan['id'], 'expectedRevision' => 1, 'rounds' => $rounds], 'MANUAL_PROGRAM_ONLY', 409);
    $assert($service->getPlan($bookingPlan['id']) === $bookingPlan, 'Booking-based rooster is veranderd.');
    $assert($plans->manualContextForPlan($bookingPlan['id']) === null, 'Booking-based rooster kreeg een handmatige context.');

    $status = $pdo->prepare("UPDATE roster_plans SET status = 'checked' WHERE id = ?");
    $status->execute([$planId]);
    $reject(['planId' => $planId, 'expectedRevision' => 5, 'rounds' => $rounds], 'MANUAL_PROGRAM_LOCKED', 409);
    $pdo->prepare("UPDATE roster_plans SET status = 'concept' WHERE id = ?")->execute([$planId]);
    (new RosterSessionSqlRepository($pdo))->insertActivity($planId, 'Klimaat-Experience', '10:15', '11:00', null, $adminId);
    $reject(['planId' => $planId, 'expectedRevision' => 5, 'rounds' => $rounds], 'MANUAL_PROGRAM_HAS_SESSIONS', 409);
    $assert($plans->manualContextForPlan($planId)['rounds_json'] !== null && $service->getPlan($planId)['revision'] === 5, 'Geblokkeerde wijziging veranderde de revisie.');

    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($disposable !== null) {
        $disposable->drop();
    } else {
        // Alleen de in deze test aangemaakte fixtures verwijderen; bestaande records blijven staan.
        $deletePlan = $pdo->prepare('DELETE FROM roster_plans WHERE id = ?');
        foreach ($fixturePlanIds as $fixtureId) $deletePlan->execute([$fixtureId]);
        if ($fixtureBookingId !== null) $pdo->prepare('DELETE FROM aanvragen WHERE id = ?')->execute([$fixtureBookingId]);
        if ($existingBookings !== null) $assert($pdo->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll() === $existingBookings, 'Bestaande boekingen zijn veranderd.');
        if ($existingPlans !== null) $assert($pdo->query('SELECT * FROM roster_plans ORDER BY id')->fetchAll() === $existingPlans, 'Bestaande roosters zijn veranderd.');
        if ($existingContexts !== null) $assert($pdo->query('SELECT * FROM roster_manual_plan_context ORDER BY roster_plan_id')->fetchAll() === $existingContexts, 'Bestaande roostercontexten zijn veranderd.');
    }
}

fwrite(STDOUT, "OK: V8B2C1 persistence, validation, compatibility ({$checks} checks); {$database}; server {$version}.\n");
