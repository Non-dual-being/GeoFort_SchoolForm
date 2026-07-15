<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80100 && PHP_SAPI === 'cli') {
    $candidate = trim((string) getenv('MIGRATION_PHP_BINARY'));
    $restarted = getenv('GEOFORT_MIGRATION_PHP_RESTARTED') === '1';
    $current = realpath(PHP_BINARY) ?: PHP_BINARY;
    $resolved = $candidate === '' ? false : realpath($candidate);
    if ($restarted) {
        fwrite(STDERR, "FOUT [PHP_RESTART_LOOP]: herstart is al uitgevoerd, maar PHP 8.1+ is niet actief.\n"); exit(2);
    }
    if ($candidate === '' || $resolved === false || !is_file($resolved) || !is_executable($resolved)) {
        fwrite(STDERR, "FOUT [PHP_BINARY]: stel MIGRATION_PHP_BINARY expliciet in op een uitvoerbare PHP 8.1+-binary.\n"); exit(2);
    }
    if (strcasecmp($resolved, $current) === 0) {
        fwrite(STDERR, "FOUT [PHP_BINARY]: MIGRATION_PHP_BINARY is gelijk aan de actieve, te oude PHP-binary.\n"); exit(2);
    }
    $versionOutput = [];$versionCode = 0;
    exec(escapeshellarg($resolved) . ' -r ' . escapeshellarg('echo PHP_VERSION_ID;'), $versionOutput, $versionCode);
    $candidateVersion = (int) implode('', $versionOutput);
    if ($versionCode !== 0 || $candidateVersion < 80100) {
        fwrite(STDERR, "FOUT [PHP_BINARY_VERSION]: MIGRATION_PHP_BINARY moet PHP 8.1 of hoger zijn.\n"); exit(2);
    }
    putenv('GEOFORT_MIGRATION_PHP_RESTARTED=1');
    $arguments = array_map('escapeshellarg', array_slice($argv, 1));
    passthru(escapeshellarg($resolved) . ' ' . escapeshellarg(__FILE__) . ' ' . implode(' ', $arguments), $code);
    exit($code);
}

use Dotenv\Dotenv;
use GeoFort\Services\Migration\LegacyBookingDumpParser;
use GeoFort\Services\Migration\LegacyBookingImportService;
use GeoFort\Services\Migration\LegacyBookingMapper;
use GeoFort\Services\Migration\LegacyDatabaseConfigGuard;
use GeoFort\Services\Migration\PdoLegacyMigrationTarget;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/vendor/autoload.php';
fwrite(STDOUT, 'PHP runtime: ' . PHP_BINARY . ' (' . PHP_VERSION . ")\n");

/** @return never */
function failMigration(string $code, string $message, int $exitCode = 1): void
{
    fwrite(STDERR, "FOUT [{$code}]: {$message}\n"); exit($exitCode);
}

function printHelp(): void
{
    fwrite(STDOUT, <<<'TXT'
Gebruik:
  php scripts/migrate-legacy-bookings.php --audit-dump=<bestand.sql>
  php scripts/migrate-legacy-bookings.php [--dry-run]
  php scripts/migrate-legacy-bookings.php --execute

De standaardmodus is dry-run. Audit-dump opent geen database. Execute is append-only.
TXT
    ); fwrite(STDOUT, "\n");
}

/** @return array<string, mixed> */
function options(array $argv): array
{
    $result=['mode'=>'dry-run','dump'=>null];
    foreach(array_slice($argv,1)as$arg){if($arg==='--help'||$arg==='-h'){$result['mode']='help';}elseif($arg==='--dry-run'){$result['mode']='dry-run';}elseif($arg==='--execute'){$result['mode']='execute';}elseif(strpos($arg,'--audit-dump=')===0){$result['mode']='audit';$result['dump']=substr($arg,13);}else failMigration('UNKNOWN_OPTION','Onbekende optie.',2);}
    return$result;
}

/** @param list<array<string, mixed>> $rows @return array<string, mixed> */
function analyseRows(array $rows, LegacyBookingMapper $mapper): array
{
    $result=['mapped'=>[],'errors'=>[],'warnings'=>[],'status'=>[],'sector'=>[],'program'=>[],'module'=>[],'max_lengths'=>[]];
    foreach($rows as$row){$id=(int)($row['id']??0);$mapped=$mapper->map($row);foreach($mapped['error_codes']as$code)$result['errors'][$code][]=$id;foreach($mapped['warning_codes']as$code)$result['warnings'][$code][]=$id;$booking=$mapped['booking'];foreach(['status'=>$booking['status'],'sector'=>$booking['onderwijs_sector']??'[onbekend]','program'=>$booking['programma'],'module'=>$booking['keuzemodule_key']??'[geen]']as$key=>$value)$result[$key][(string)$value]=($result[$key][(string)$value]??0)+1;foreach(['schoolnaam','adres','plaats','email','hoe_kent_u_geofort','opmerkingen']as$field)$result['max_lengths'][$field]=max($result['max_lengths'][$field]??0,mb_strlen((string)($booking[$field]??''),'UTF-8'));$result['mapped'][]=['legacy_id'=>$id,'source_checksum'=>LegacyBookingDumpParser::rowChecksum($row),'booking'=>$booking,'selections'=>$mapped['selections']];}
    foreach(['status','sector','program','module']as$key)ksort($result[$key]);return$result;
}

/** @param array<string, mixed> $analysis */
function printAnalysis(array $analysis): void
{
    $selectionCount=array_sum(array_map(static fn(array $item):int=>count($item['selections']),$analysis['mapped']));
    fwrite(STDOUT,'Bronrecords: '.count($analysis['mapped'])."\nVerwachte selectieregels: {$selectionCount}\n");
    foreach(['status'=>'Statusverdeling','sector'=>'Sectorverdeling','program'=>'Programmaverdeling','module'=>'Moduleverdeling']as$key=>$label)fwrite(STDOUT,$label.': '.json_encode($analysis[$key],JSON_UNESCAPED_UNICODE)."\n");
    foreach(['warnings'=>'Waarschuwingen','errors'=>'Blokkerende fouten']as$key=>$label){fwrite(STDOUT,$label.":\n");if($analysis[$key]===[])fwrite(STDOUT,"  (geen)\n");foreach($analysis[$key]as$code=>$ids)fwrite(STDOUT,"  {$code}: aantal=".count($ids).'; legacy_ids='.implode(',',$ids)."\n");}
    $manual=[];foreach($analysis['warnings']as$ids)$manual=array_merge($manual,$ids);$manual=array_values(array_unique($manual));sort($manual);fwrite(STDOUT,'Handmatige-controlerecords: aantal='.count($manual).'; legacy_ids='.implode(',',$manual)."\n");
    fwrite(STDOUT,'Langste tekstlengtes: '.json_encode($analysis['max_lengths'])."\n");
    printDuplicateCandidates($analysis['mapped']);
}

/** @param list<array<string, mixed>> $mapped */
function printDuplicateCandidates(array $mapped): void
{
    $profiles=['school_date'=>['schoolnaam','bezoekdatum'],'email_date'=>['email','bezoekdatum'],'school_date_students'=>['schoolnaam','bezoekdatum','aantal_leerlingen']];
    foreach($profiles as$label=>$fields){$groups=[];foreach($mapped as$item){$parts=[];foreach($fields as$field)$parts[]=mb_strtolower(trim((string)($item['booking'][$field]??'')),'UTF-8');$groups[implode('|',$parts)][]=$item['legacy_id'];}$duplicates=array_values(array_filter($groups,static fn(array $ids):bool=>count($ids)>1));fwrite(STDOUT,"Potentiële dubbelen {$label}: groepen=".count($duplicates).'; legacy_ids='.json_encode($duplicates)."\n");}
}

function envValue(string $key, bool $required = true): string
{
    $value=$_ENV[$key]??$_SERVER[$key]??getenv($key);$value=$value===false?'':trim((string)$value);if($required&&$value==='')failMigration('MISSING_ENV',"{$key} ontbreekt.",2);return$value;
}

function database(string $prefix): PDO
{
    $base=$prefix===''?'DB_':$prefix.'_DB_';
    $password=envValue($base.'PASSWORD',false);$dsn=sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',envValue($base.'HOST'),envValue($base.'PORT'),envValue($base.'NAME'));
    return new PDO($dsn,envValue($base.'USER'),$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}

/** @return list<array<string, mixed>> */
function sourceRows(PDO $source): array{return$source->query('SELECT * FROM aanvragen ORDER BY id')->fetchAll();}

function assertSeparatedConfiguration(): void
{
    try{LegacyDatabaseConfigGuard::assertSeparated(envValue('LEGACY_DB_HOST'),envValue('LEGACY_DB_PORT'),envValue('LEGACY_DB_NAME'),envValue('DB_HOST'),envValue('DB_PORT'),envValue('DB_NAME'));}catch(RuntimeException $e){failMigration('SOURCE_EQUALS_TARGET',$e->getMessage(),2);}
}

$opts=options($argv);
if($opts['mode']==='help'){printHelp();exit(0);}
try{
    $mapper=new LegacyBookingMapper();
    if($opts['mode']==='audit'){$path=(string)$opts['dump'];$rows=(new LegacyBookingDumpParser())->parseFile($path);$analysis=analyseRows($rows,$mapper);printAnalysis($analysis);exit($analysis['errors']===[]?0:1);}
    Dotenv::createImmutable(dirname(__DIR__))->load();assertSeparatedConfiguration();
    $source=database('LEGACY');$targetPdo=database('');LegacyDatabaseConfigGuard::assertDistinctConnections($source,$targetPdo);$analysis=analyseRows(sourceRows($source),$mapper);printAnalysis($analysis);if($analysis['errors']!==[])failMigration('MAPPER_ERRORS','Blokkerende mapperfouten gevonden.');
    $target=new PdoLegacyMigrationTarget($targetPdo);$service=new LegacyBookingImportService();$plan=$service->plan($analysis['mapped'],$target);
    fwrite(STDOUT,'Importplan: invoegen='.count($plan['insert']).', overslaan='.count($plan['skip']).', gewijzigd='.count($plan['changed']).', legacy-ID-conflicten='.count($plan['legacy_id_conflicts'])."\n");
    if($plan['legacy_id_conflicts']!==[])fwrite(STDOUT,'Legacy-ID-conflicten zijn veilig omdat nieuwe doel-ID’s worden gegenereerd; legacy_ids='.implode(',',$plan['legacy_id_conflicts'])."\n");
    if($plan['changed']!==[])failMigration('SOURCE_RECORD_CHANGED','Eerder geïmporteerde bronrecords zijn gewijzigd; legacy_ids='.implode(',',$plan['changed']));
    if($opts['mode']==='dry-run'){fwrite(STDOUT,"Transactiestatus: GEEN MUTATIES (read-only dry-run)\n");exit(0);}
    $warningCount=array_sum(array_map('count',$analysis['warnings']));$result=$service->execute($analysis['mapped'],$target,basename(envValue('LEGACY_SOURCE_FILENAME')),hash('sha256',json_encode(array_column($analysis['mapped'],'source_checksum'))),$warningCount);
    fwrite(STDOUT,'Import voltooid: run_id='.$result['run_id'].', geïmporteerd='.$result['imported'].', overgeslagen='.$result['skipped'].', selecties='.$result['selections']."\n");
}catch(Throwable $e){failMigration('MIGRATION_FAILED',$e->getMessage());}
