<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$fixtures=require __DIR__.'/fixtures/RosterXlsxPlans.php';
$plans=[];
foreach([11,12] as $i=>$id){
 $p=$fixtures['modules-5'];$p['id']=$id;$p['bookingId']=$id;$p['school']['name']='Week pair school '.($i+1);$p['visitDate']='2027-01-12';
 $p['jointPlanning']=['id'=>7,'planIds'=>[11,12]];
 $p['planning']['staffCatalog'][]=['id'=>900,'name'=>'Shared cook'];$p['planning']['staffingSettings']['cookStaffId']=900;
 foreach($p['planning']['sessions'] as &$session)if($i===1)foreach(['startTime','endTime'] as $key)$session[$key]=date('H:i',strtotime($session[$key])+600);
 unset($session);$plans[]=$p;
}
$before=serialize($plans);$files=[tempnam(sys_get_temp_dir(),'week-pair-'),tempnam(sys_get_temp_dir(),'joint-source-')];
$read=static function(string $path,int $sheet):array{
 $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Invalid workbook');
 $doc=new DOMDocument();$doc->loadXML($zip->getFromName('xl/worksheets/sheet'.$sheet.'.xml'),LIBXML_NONET);$zip->close();
 $xp=new DOMXPath($doc);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
 return [$doc,$xp];
};
$teacherRows=static function(DOMXPath $xp):array{
 $rows=[];$active=false;
 foreach($xp->query('//s:sheetData/s:row') as $row){
  $values=[];foreach($xp->query('s:c',$row) as $cell)$values[]=$cell->textContent;
  if(in_array('Docentenrooster',$values,true))$active=true;
  if($active && array_filter($values,static fn(string $v):bool=>$v!=='')!==[])$rows[]=$values;
 }
 return $rows;
};
try{
 $w=new RosterXlsxWriter();$w->writeWeek($files[0],['week'=>'2027-W02','startDate'=>'2027-01-11','plans'=>$plans]);$w->writeJoint($files[1],$plans);
 [$doc,$xp]=$read($files[0],3);[, $source]=$read($files[1],1);
 $assert($xp->query('//s:c/s:is/s:t[text()="Lesrooster"]')->length===2,'Both school lesson timetables must remain');
 $assert($xp->query('//s:c/s:is/s:t[text()="Docentenrooster"]')->length===1,'Linked week must contain exactly one combined teacher timetable');
 $assert($teacherRows($xp)===$teacherRows($source),'Weekly and standalone joint teacher timetables differ');
 $assert(str_contains($doc->textContent,'Schoolbegeleiding 1') || str_contains($doc->textContent,'[School 1]'),'School identity absent');
 $assert(str_contains($doc->textContent,'[School 2]'),'Second school identity absent');
 // A rejected, deleted or otherwise omitted partner must never be pulled into the export.
 $w->writeWeek($files[0],['week'=>'2027-W02','startDate'=>'2027-01-11','plans'=>[$plans[0]]]);[$doc,$xp]=$read($files[0],3);
 $assert($xp->query('//s:c/s:is/s:t[text()="Docentenrooster"]')->length===1 && !str_contains($doc->textContent,'Week pair school 2'),'Filtered partner leaked into week');
 $unpaired=$plans;foreach($unpaired as &$p)unset($p['jointPlanning']);unset($p);
 $w->writeWeek($files[0],['week'=>'2027-W02','startDate'=>'2027-01-11','plans'=>$unpaired]);[, $xp]=$read($files[0],3);
 $assert($xp->query('//s:c/s:is/s:t[text()="Docentenrooster"]')->length===2,'Unrelated schools must keep separate staff timetables');
 $assert(serialize($plans)===$before,'Export changed its source');
 echo "PASS: weekly linked schools use exact combined teacher view; independent schools and filtered partners preserved\n";
}finally{foreach($files as $file)unlink($file);}
