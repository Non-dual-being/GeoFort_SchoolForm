<?php
declare(strict_types=1);

if(PHP_VERSION_ID<80100){fwrite(STDERR,"FOUT [PHP_VERSION]: rollbacktool vereist expliciet PHP 8.1 of hoger.\n");exit(2);}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

use Dotenv\Dotenv;
use GeoFort\Services\Migration\LegacyBookingRollbackService;
use GeoFort\Services\Migration\PdoLegacyRollbackTarget;

require dirname(__DIR__).'/vendor/autoload.php';
fwrite(STDOUT,'PHP runtime: '.PHP_BINARY.' ('.PHP_VERSION.")\n");

$runId=null;$execute=false;$help=false;
foreach(array_slice($argv,1)as$arg){if(strpos($arg,'--run-id=')===0)$runId=filter_var(substr($arg,9),FILTER_VALIDATE_INT);elseif($arg==='--execute')$execute=true;elseif($arg==='--help'||$arg==='-h')$help=true;else{fwrite(STDERR,"FOUT [UNKNOWN_OPTION]: onbekende optie.\n");exit(2);}}
if($help){fwrite(STDOUT,"Gebruik:\n  php scripts/rollback-legacy-booking-import.php --run-id=<id> [--execute]\nStandaard is read-only dry-run.\n");exit(0);}
if(!is_int($runId)||$runId<=0){fwrite(STDERR,"FOUT [RUN_ID]: expliciete positieve --run-id is vereist.\n");exit(2);}

try{Dotenv::createImmutable(dirname(__DIR__))->load();$get=static function(string$key,bool$required=true):string{$v=$_ENV[$key]??$_SERVER[$key]??getenv($key);$v=$v===false?'':trim((string)$v);if($required&&$v==='')throw new RuntimeException("{$key} ontbreekt.");return$v;};$pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',$get('DB_HOST'),$get('DB_PORT'),$get('DB_NAME')),$get('DB_USER'),$get('DB_PASSWORD',false),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$target=new PdoLegacyRollbackTarget($pdo);$service=new LegacyBookingRollbackService();$plan=$service->plan($runId,$target);fwrite(STDOUT,'Rollbackplan: run_id='.$runId.', aanvragen='.$plan['booking_count'].', selecties='.$plan['selection_count'].', technische_aanvraag_ids='.implode(',',$plan['booking_ids'])."\n");if(!$execute){fwrite(STDOUT,"Transactiestatus: GEEN MUTATIES (read-only dry-run)\n");exit(0);}$result=$service->execute($runId,$target);fwrite(STDOUT,'Rollback voltooid: run_id='.$result['run_id'].', aanvragen='.$result['deleted_bookings'].', selecties='.$result['deleted_selections']."\n");}catch(Throwable$e){fwrite(STDERR,'FOUT [ROLLBACK_FAILED]: '.$e->getMessage()."\n");exit(1);}
