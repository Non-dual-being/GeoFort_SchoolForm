<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Dashboard\Roster\RosterPlanService;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: pdo_sqlite ontbreekt.\n");
    exit(0);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec(<<<'SQL'
    CREATE TABLE aanvragen (
      id INTEGER PRIMARY KEY,
      status TEXT NOT NULL DEFAULT 'Definitief',
      schoolnaam TEXT NOT NULL DEFAULT '',
      land TEXT NOT NULL DEFAULT '',
      adres TEXT NOT NULL DEFAULT '',
      postcode TEXT NOT NULL DEFAULT '',
      plaats TEXT NOT NULL DEFAULT '',
      school_telefoonnummer TEXT NOT NULL DEFAULT '',
      contactpersoon_telefoonnummer TEXT NOT NULL DEFAULT '',
      contactpersoon_voornaam TEXT NOT NULL DEFAULT '',
      contactpersoon_achternaam TEXT NOT NULL DEFAULT '',
      email TEXT NOT NULL DEFAULT '',
      bezoekdatum TEXT NOT NULL,
      hoe_kent_u_geofort TEXT NULL,
      opmerkingen TEXT NULL,
      cjpPasGebruik TEXT NOT NULL DEFAULT 'nee',
      cjpContactpersoonNaam TEXT NULL,
      cjpPasnummer TEXT NULL,
      onderwijs_sector TEXT NOT NULL,
      programma TEXT NOT NULL,
      keuzemodule_key TEXT NULL,
      aantal_leerlingen INTEGER NULL,
      aantal_begeleiders INTEGER NULL,
      remise_break INTEGER NOT NULL DEFAULT 0,
      kazerne_break INTEGER NOT NULL DEFAULT 0,
      fortgracht_break INTEGER NOT NULL DEFAULT 0,
      glas_limonade INTEGER NOT NULL DEFAULT 0,
      waterijsje INTEGER NOT NULL DEFAULT 0,
      remise_lunch INTEGER NOT NULL DEFAULT 0,
      eigen_picknick INTEGER NOT NULL DEFAULT 1,
      voorwaarden_akkoord INTEGER NOT NULL DEFAULT 1,
      voorwaarden_akkoord_op TEXT NULL,
      source_system TEXT NULL,
      source_record_id INTEGER NULL,
      source_record_checksum TEXT NULL,
      source_import_run_id INTEGER NULL
    )
    SQL);
$pdo->exec(<<<'SQL'
    CREATE TABLE aanvraag_onderwijs_selecties (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      aanvraag_id INTEGER NOT NULL,
      sector_key TEXT NOT NULL,
      level_key TEXT NOT NULL,
      group_key TEXT NOT NULL,
      level_position INTEGER NOT NULL,
      group_position INTEGER NOT NULL
    )
    SQL);
$pdo->exec(<<<'SQL'
    CREATE TABLE roster_plans (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      booking_id INTEGER NULL UNIQUE,
      visit_date TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'concept',
      revision INTEGER NOT NULL DEFAULT 1,
      source_booking_fingerprint TEXT NULL,
      created_by_admin_id INTEGER NOT NULL,
      updated_by_admin_id INTEGER NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
    SQL);
$pdo->exec(<<<'SQL'
    CREATE TABLE roster_groups (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      roster_plan_id INTEGER NOT NULL,
      label TEXT NOT NULL,
      position INTEGER NOT NULL,
      student_count INTEGER NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (roster_plan_id, position)
    )
    SQL);

$insert = $pdo->prepare(<<<'SQL'
    INSERT INTO aanvragen (
      id, schoolnaam, plaats, bezoekdatum, onderwijs_sector, programma,
      keuzemodule_key, aantal_leerlingen, aantal_begeleiders
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    SQL);
$insert->execute([
    1,
    'Testschool',
    'Gorinchem',
    '2026-10-01',
    'primairOnderwijs',
    'dag',
    'Earth-Watch',
    100,
    7,
]);
$insert->execute([
    2,
    'Te kleine school',
    'Gorinchem',
    '2026-10-02',
    'primairOnderwijs',
    'dag',
    'Earth-Watch',
    20,
    2,
]);

$bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
$plans = new RosterPlanSqlRepository($pdo);
$service = new RosterPlanService(
    $pdo,
    $bookings,
    $plans,
    new RosterGroupCountResolver(),
);

$created = $service->createFromBooking(1, 10);
$assert($created['created'] === true, 'Eerste creatie is niet als nieuw gemarkeerd.');
$assert(count($created['plan']['groups']) === 6, '100 leerlingen moeten zes operationele groepen krijgen.');
$assert($created['plan']['groups'][0]['label'] === 'Groep 1', 'Eerste groepslabel klopt niet.');
$assert($created['plan']['groups'][5]['label'] === 'Groep 6', 'Laatste groepslabel klopt niet.');
$assert($created['plan']['sourceCurrent'] === true, 'Nieuwe planning moet gelijk zijn aan de bronaanvraag.');

$again = $service->createFromBooking(1, 10);
$assert($again['created'] === false, 'Tweede creatie moet het bestaande rooster hergebruiken.');
$assert($again['plan']['id'] === $created['plan']['id'], 'Tweede creatie opent niet hetzelfde rooster.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM roster_plans')->fetchColumn() === 1, 'Er is een dubbel roosterplan aangemaakt.');

$pdo->exec('UPDATE aanvragen SET aantal_leerlingen = 120 WHERE id = 1');
$changed = $service->getPlan((int) $created['plan']['id']);
$assert(is_array($changed) && $changed['needsReview'] === true, 'Gewijzigde bronaanvraag markeert het rooster niet voor herbeoordeling.');

try {
    $service->createFromBooking(2, 10);
    throw new RuntimeException('Te klein leerlingaantal had moeten blokkeren.');
} catch (RosterPlanException $exception) {
    $assert($exception->publicCode === 'GROUP_COUNT_UNAVAILABLE', 'Verkeerde foutcode voor niet-roosterbaar leerlingaantal.');
}

fwrite(STDOUT, "OK: roosterfundering domeintest geslaagd.\n");