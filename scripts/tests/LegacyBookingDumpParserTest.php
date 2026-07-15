<?php
declare(strict_types=1);
if(PHP_VERSION_ID<80100){fwrite(STDERR,"SKIP/FAIL: voer migratietests expliciet uit met PHP 8.1 of hoger.\n");exit(2);}
use GeoFort\Services\Migration\LegacyBookingDumpParser;
require dirname(__DIR__,2).'/vendor/autoload.php';

$parser=new LegacyBookingDumpParser();$columns='`id`,`status`,`opmerkingen`,`hoe_kent_u_geofort`';
$sql="-- HeidiSQL export\nINSERT INTO `aanvragen` ({$columns}) VALUES\n".
    "(1,'Definitief','escaped \\'quote, komma; (haakjes) en \\\\pad\\r\\nregel','NULL'),\n".
    "(2,'Afgewezen',NULL,'tekst met echte\r\nmultiline');\n";
$rows=$parser->parseSql($sql);$fail=[];$assert=static function(bool$c,string$m)use(&$fail):void{if(!$c)$fail[]=$m;};
$assert(count($rows)===2,'Twee tuples moeten worden gelezen.');$assert($rows[0]['opmerkingen']==="escaped 'quote, komma; (haakjes) en \\pad\r\nregel",'Quotes, komma, puntkomma, haakjes, backslash en escapes moeten exact worden verwerkt.');$assert($rows[0]['hoe_kent_u_geofort']==='NULL','Gequote NULL moet tekst blijven.');$assert($rows[1]['opmerkingen']===null,'Ongequote NULL moet null worden.');$assert($rows[1]['hoe_kent_u_geofort']==="tekst met echte\r\nmultiline",'Letterlijke multiline tekst moet blijven.');
$multiple=$sql."INSERT INTO `aanvragen` ({$columns}) VALUES (3,'Definitief','x','y');\n";$blocked=false;try{$parser->parseSql($multiple);}catch(RuntimeException$e){$blocked=strpos($e->getMessage(),'Meerdere')!==false;}$assert($blocked,'Meerdere aanvragen-INSERT-blokken moeten duidelijk blokkeren.');
if($fail!==[]){foreach($fail as$f)fwrite(STDERR,"FAIL: {$f}\n");exit(1);}fwrite(STDOUT,"OK: dumpparserchecks geslaagd.\n");
