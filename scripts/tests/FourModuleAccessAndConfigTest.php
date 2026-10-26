<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\{BookingProgramConfig, BookingProgramVersionConfig, FourModuleProgram};
use GeoFort\Services\Sql\BookingProgramAccessSqlRepository;
use GeoFort\Services\Booking\Program\BookingProgramAccessService;
use GeoFort\Validation\{FieldValidationException, ProgramVersionValidator};

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$versions = new ProgramVersionValidator();
$assert($versions->forNewRequest(null, 'dag', '2027-01-04') === null, 'Already opened legacy form keeps original offer');
$assert($versions->forNewRequest('auto', 'dag', '2027-01-03') === null, 'Before switch');
$assert($versions->forNewRequest('auto', 'dag', '2027-01-04') === FourModuleProgram::VERSION, 'Switch inclusive');
$assert($versions->forNewRequest('five', 'dag', '2027-01-04') === null, 'Explicit five retained');
$assert($versions->forNewRequest('auto', 'ochtend', '2027-01-06') === null, 'Morning unchanged');
foreach ([['four', '2027-01-03'], ['unknown', '2027-01-04'], [[], '2027-01-04']] as [$variant, $date]) {
    try { $versions->forNewRequest($variant, 'dag', $date); $assert(false, 'Invalid public variant accepted'); }
    catch (FieldValidationException) { $assert(true, 'Invalid public variant rejected'); }
}
$old = BookingProgramConfig::forFrontend();
$new = BookingProgramVersionConfig::forFrontend(FourModuleProgram::VERSION);
$assert(BookingProgramVersionConfig::forFrontend() === $old, 'Legacy config exactly preserved');
$assert($new['programs']['dag']['eindTijd'] === '14:00', 'New end time');
$assert($new['prices']['bezoek']['dag'] === ['basis'=>17.5,'voortgezet'=>20.99], 'Four-module prices');
$assert($new['prices']['snacks'] === $old['prices']['snacks'], 'Catering unchanged');
$assert($new['modules']['primairOnderwijs']['ochtend'] === $old['modules']['primairOnderwijs']['ochtend'], 'Morning modules preserved');
$assert(count($new['modules']['primairOnderwijs']['dag']['standaard']) === 3, 'Three fixed modules');
$assert($new['moduleFilters']['Earth-Watch']['primairOnderwijs']['regulier'] === ['groep5', 'groep6'], 'Client exclusions match backend');

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE booking_program_access (id INTEGER PRIMARY KEY, five_module_open INTEGER, revision INTEGER, updated_by INTEGER, updated_at TEXT)');
$pdo->exec('INSERT INTO booking_program_access VALUES (1, 1, 0, NULL, NULL)');
$service = new BookingProgramAccessService(new BookingProgramAccessSqlRepository($pdo));
$assert($service->get() === ['fiveModuleOpen' => true, 'revision' => 0], 'Initial open state');
$assert($service->save(false, 0, 1, 'viewer')['code'] === 'FORBIDDEN', 'Unauthorized change rejected');
$assert($service->save('false', 0, 1, 'admin')['code'] === 'INVALID_REQUEST', 'String boolean rejected');
$assert($service->save(false, '0', 1, 'admin')['code'] === 'INVALID_REQUEST', 'String revision rejected');
$assert($service->save(false, 0, 1, 'planner')['ok'], 'Planner can close');
$assert($service->save(true, 0, 2, 'admin')['code'] === 'CONFLICT', 'Stale update rejected');
$assert(!$service->get()['fiveModuleOpen'], 'Stale update did not reopen');
$pdo->beginTransaction();
try { $service->assertCanSubmit('dag', null); $assert(false, 'Closed form bypassed'); }
catch (FieldValidationException) { $assert(true, 'Closed legacy submission rejected'); }
$service->assertCanSubmit('dag', FourModuleProgram::VERSION);
$service->assertCanSubmit('ochtend', null);
$pdo->rollBack();
$assert($service->save(true, 1, 1, 'admin')['ok'], 'Reopen with current revision');
$pdo->beginTransaction();
$service->assertCanSubmit('dag', null);
$pdo->rollBack();
$assert((int) $pdo->query('SELECT updated_by FROM booking_program_access')->fetchColumn() === 1, 'Actor recorded');
fwrite(STDOUT, "PASS: {$checks} public version, config and access setting checks\n");
