<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Mail\MailConfig;
$assert=static function(bool $ok):void{if(!$ok)throw new RuntimeException('Recipient routing failed');};
$make=static fn(string $env,?string $receiver)=>new MailConfig('localhost',27025,'test','test','','planner@example.test','Test','planner@example.test',$receiver,['copy@example.test'],$env,0,['blind@example.test']);
$school='school@example.test';$cc=['copy@example.test'];$bcc=['blind@example.test'];
$dev=$make('development','geofortplanner@gmail.com');
$assert($dev->recipients($school,$cc,$bcc)===['to'=>'geofortplanner@gmail.com','cc'=>[],'bcc'=>[]]);
$assert($school==='school@example.test' && $cc===['copy@example.test'] && $bcc===['blind@example.test']);
$assert($make('production',null)->recipients($school,$cc,$bcc)===['to'=>$school,'cc'=>$cc,'bcc'=>$bcc]);
foreach([null,'','invalid'] as $receiver){try{$make('development',$receiver)->recipients($school,$cc,$bcc);}catch(RuntimeException){continue;}throw new RuntimeException('Invalid development receiver accepted');}
echo "PASS: development To/CC/BCC isolation, unchanged source contacts and production routing.\n";
