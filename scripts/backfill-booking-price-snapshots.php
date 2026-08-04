<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Booking\Pricing\Backfill\BookingPriceBackfillAnalysis;
use GeoFort\Services\Booking\Pricing\Backfill\BookingPriceSnapshotBackfillService;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshotService;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInputFactory;
use GeoFort\Services\Sql\BookingPriceBackfillSqlRepository;
use GeoFort\Services\Sql\BookingPriceSnapshotSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/vendor/autoload.php';

/** @return never */
function failBackfill(string $code,string $message,int $exitCode=1):void{fwrite(STDERR,"FOUT [{$code}]: {$message}\n");exit($exitCode);}
function envBackfill(string $key,bool $required=true):string{$value=$_ENV[$key]??$_SERVER[$key]??getenv($key);$value=$value===false?'':trim((string)$value);if($required&&$value==='')failBackfill('MISSING_ENV',"{$key} ontbreekt.",2);return$value;}
/** @return 'dry-run'|'execute'|'help' */
function backfillMode(array $argv):string{$mode='dry-run';$seen=false;foreach(array_slice($argv,1)as$arg){if($arg==='--help'||$arg==='-h')return'help';if($arg!=='--dry-run'&&$arg!=='--execute')failBackfill('UNKNOWN_OPTION',"Onbekende optie: {$arg}",2);if($seen)failBackfill('CONFLICTING_OPTIONS','Gebruik precies één modusvlag.',2);$mode=$arg==='--execute'?'execute':'dry-run';$seen=true;}return$mode;}
function printBackfillHelp():void{fwrite(STDOUT,"Gebruik:\n  php scripts/backfill-booking-price-snapshots.php --dry-run\n  php scripts/backfill-booking-price-snapshots.php --execute\n\nStandaardmodus zonder vlag: dry-run. Alleen DB_* wordt gebruikt; de legacybron wordt niet geopend.\n");}
function printBackfillAnalysis(BookingPriceBackfillAnalysis $result,string $mode):void
{
    $matches=$result->reconciliationMatches(BookingPriceSnapshotBackfillService::EXPECTED_TOTAL,BookingPriceSnapshotBackfillService::EXPECTED_LEGACY,BookingPriceSnapshotBackfillService::EXPECTED_NATIVE,BookingPriceSnapshotBackfillService::EXPECTED_MINIMUM_SOURCE_RECORD_ID,BookingPriceSnapshotBackfillService::EXPECTED_MAXIMUM_SOURCE_RECORD_ID);
    fwrite(STDOUT,"Modus: {$mode}\nTotaal gevonden boekingen: {$result->totalBookings}\nHerkomst: legacy={$result->legacyBookings}, native={$result->nativeBookings}, onbekend={$result->unknownSourceBookings}\nLegacy source_record_id: aanwezig={$result->legacyWithSourceRecordId}, uniek={$result->uniqueLegacySourceRecordIds}, ontbrekend={$result->legacyWithoutSourceRecordId}, minimum=".($result->minimumLegacySourceRecordId??'geen').", maximum=".($result->maximumLegacySourceRecordId??'geen')."\nVerwachte initiële productiereconciliatie: 140 totaal = 140 legacy-school-db + 0 native; 140 unieke source_record_id's; bereik 1-155; resultaat=".($matches?'AKKOORD':'AFWIJKING')."\nZonder snapshot: {$result->withoutSnapshot}\nReeds met snapshot: {$result->withSnapshot}\nBerekenbaar: {$result->calculable}\nNiet berekenbaar: {$result->notCalculable}\nCalculation states: ".json_encode($result->calculationStates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nToegevoegd: {$result->added}\n");
    fwrite(STDOUT,"Uitzonderingen:\n");if($result->exceptions===[])fwrite(STDOUT,"  (geen)\n");foreach($result->exceptions as$item)fwrite(STDOUT,'  booking_id='.$item['bookingId'].'; source='.$item['sourceCategory'].'; source_record_id='.($item['sourceRecordId']??'geen').'; state='.$item['calculationState'].'; reden='.$item['reason'].'; velden='.implode(',',(array)$item['invalidFields'])."\n");
    fwrite(STDOUT,"Geplande snapshots:\n");if($result->candidates===[])fwrite(STDOUT,"  (geen)\n");foreach($result->candidates as$item)fwrite(STDOUT,'  booking_id='.$item['bookingId'].'; source='.$item['sourceCategory'].'; source_record_id='.($item['sourceRecordId']??'geen').'; reason='.$item['snapshotReason']."\n");
}

$mode=backfillMode($argv);if($mode==='help'){printBackfillHelp();exit(0);}
try{
    Dotenv::createImmutable(dirname(__DIR__))->load();
    $host=envBackfill('DB_HOST');$port=envBackfill('DB_PORT');$database=envBackfill('DB_NAME');$user=envBackfill('DB_USER');$password=envBackfill('DB_PASSWORD',false);
    fwrite(STDOUT,"Doeldatabaseconfiguratie: host={$host}; port={$port}; database={$database}; gebruiker={$user}\n");
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $stored=new StoredBookingSqlRepository($pdo,new StoredBookingAssembler());$calculator=new BookingPriceCalculator();
    $service=new BookingPriceSnapshotBackfillService($pdo,new BookingPriceBackfillSqlRepository($pdo),$stored,new StoredBookingPricingInputFactory(),$calculator,new BookingPriceSnapshotService(new BookingPriceSnapshotSqlRepository($pdo),$calculator));
    if($mode==='dry-run'){$result=$service->analyze();printBackfillAnalysis($result,$mode);fwrite(STDOUT,"Transactiestatus: GEEN MUTATIES (read-only dry-run)\n");exit($service->productionReconciliationMatches($result)?0:1);}
    $result=$service->execute();printBackfillAnalysis($result,$mode);fwrite(STDOUT,"Transactiestatus: COMMIT VOLTOOID\n");
}catch(Throwable $exception){failBackfill('BACKFILL_FAILED',$exception->getMessage());}
