<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\{ProgramAvailabilityPolicy as Policy, FourModuleProgram};
use GeoFort\Services\Booking\Program\BookingProgramAccessService;
use GeoFort\Services\Sql\BookingProgramAccessSqlRepository;

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$rule = ['program'=>'morning','from'=>'2026-12-08','until'=>'2026-12-08','weekdays'=>[2],'open'=>true,'note'=>'Isselborgh'];
$policy = new Policy(true, Policy::validatePeriods([$rule]));
foreach (['2026-12-07'=>false,'2026-12-08'=>true,'2026-12-09'=>true,'2026-12-15'=>false,'2026-12-12'=>false] as $date=>$expected) $assert($policy->allows('ochtend', null, $date)===$expected, 'Morning '.$date);
$assert(!$policy->allows('dag', FourModuleProgram::VERSION, '2027-01-01'), 'Four must retain start');
$assert($policy->allows('dag', FourModuleProgram::VERSION, '2027-01-04'), 'Four start inclusive');
$closed = new Policy(false, Policy::validatePeriods([['program'=>'five','from'=>'2026-12-07','until'=>'2026-12-11','weekdays'=>[1,2,3,4,5],'open'=>true,'note'=>''] , $rule]));
foreach (['2026-12-04'=>false,'2026-12-07'=>true,'2026-12-11'=>true,'2026-12-14'=>false] as $date=>$expected) $assert($closed->allows('dag', null, $date)===$expected, 'Five '.$date);
$assert($closed->allows('ochtend',null,'2026-12-08'), 'Five closure must not close short morning lessons');
$assert($closed->allows('dag',FourModuleProgram::VERSION,'2027-01-04'), 'Five closure must not close four');
$assert(!$closed->anyOn('2026-12-14'), 'No available offer');
$assert($closed->anyOn('2026-12-16'), 'Wednesday morning still available');
$assert(!$closed->anyOn('2027-01-05','dag','day-five'), 'Analytics five must not count four');
$assert($closed->anyOn('2027-01-05','dag','day-four'), 'Analytics four');
$assert(!(new Policy(true, [array_replace($rule,['from'=>'2026-12-09','until'=>'2026-12-09','weekdays'=>[3],'open'=>false])]))->allows('ochtend',null,'2026-12-09'), 'Close default Wednesday');
foreach (['2026-02-30','wrong','2026-12-8','2026-12-08T00:00:00'] as $date) $assert(!$policy->allows('ochtend',null,$date), 'Invalid date');
foreach ([null, [], '2', [6], [2,2]] as $days) {
    try { Policy::validatePeriods([array_replace($rule,['weekdays'=>$days])]); $assert(false,'Bad weekdays'); } catch (InvalidArgumentException) { $assert(true,'Bad weekdays rejected'); }
}
foreach ([['from'=>'2026-12-09'],['until'=>'2026-02-30'],['open'=>'false'],['program'=>'four'],['extra'=>1],['note'=>str_repeat('x',161)],['weekdays'=>[3]]] as $bad) {
    try { Policy::validatePeriods([array_replace($rule,$bad)]); $assert(false,'Bad rule'); } catch (InvalidArgumentException) { $assert(true,'Bad rule rejected'); }
}
try { Policy::validatePeriods([$rule,array_replace($rule,['open'=>false])]); $assert(false,'Overlap'); } catch (InvalidArgumentException) { $assert(true,'Overlap rejected'); }
$assert(count(Policy::validatePeriods([$rule,array_replace($rule,['from'=>'2026-12-09','until'=>'2026-12-09','weekdays'=>[3]])]))===2,'Adjacent periods allowed');

$pdo = new PDO('sqlite::memory:', options:[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE booking_program_access (id INTEGER PRIMARY KEY, five_module_open INTEGER, revision INTEGER, updated_by INTEGER, updated_at TEXT)');
$pdo->exec('INSERT INTO booking_program_access VALUES(1,1,0,NULL,NULL)');
$pdo->exec("CREATE TABLE booking_program_schedule (id INTEGER PRIMARY KEY, periods_json TEXT); INSERT INTO booking_program_schedule VALUES(1,'[]')");
$pdo->exec('CREATE TABLE booking_program_access_history (id INTEGER PRIMARY KEY, revision INTEGER UNIQUE, before_json TEXT, after_json TEXT, changed_by INTEGER)');
$service = new BookingProgramAccessService(new BookingProgramAccessSqlRepository($pdo));
$assert($service->saveConfiguration(true,[$rule],0,1,'viewer')['code']==='FORBIDDEN','Viewer');
$assert($service->saveConfiguration('true',[$rule],0,1,'planner')['code']==='INVALID_REQUEST','Strict bool');
$assert($service->saveConfiguration(true,[$rule],0,1,'planner')['ok'],'Save');
$assert($service->saveConfiguration(false,[],0,1,'planner')['code']==='CONFLICT','Stale write');
$assert($service->configuration()['periods']===[$rule],'Conflict must preserve periods');
$assert($service->saveConfiguration(true,[$rule],1,1,'planner')['data']['revision']===1,'No-op no revision');
$assert((int)$pdo->query('SELECT COUNT(*) FROM booking_program_access_history')->fetchColumn()===1,'Exactly one audit');
$assert($service->saveConfiguration(false,[],1,1,'admin')['ok'],'Close and remove');
$history=json_decode($pdo->query('SELECT before_json FROM booking_program_access_history WHERE revision=2')->fetchColumn(),true);
$assert($history['periods']===[$rule], 'Removed period retained in audit');
echo "PASS {$checks} availability boundaries, overlaps, defaults, versions, roles, revision conflicts and audit checks\n";
