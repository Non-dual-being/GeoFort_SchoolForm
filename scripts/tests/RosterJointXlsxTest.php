<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$fixtures=require __DIR__.'/fixtures/RosterXlsxPlans.php';
$a=$fixtures['modules-5'];
$a['planning']['staffCatalog'][]=['id'=>998,'name'=>'Niet ingezet'];
$a['planning']['selectedStaffIds'][]=998;
$a['planning']['staffCatalog'][]=['id'=>999,'name'=>'Testkok'];
$a['planning']['staffingSettings']['cookStaffId']=999;
$a['planning']['staffCatalog'][]=['id'=>997,'name'=>'Alleen eerste ronde'];
$a['planning']['sessions'][0]['staffAssignments'][]=['id'=>997,'name'=>'Alleen eerste ronde'];
$schoolSession=$a['planning']['sessions'][1];
$schoolSession['staffAssignments']=[];
$schoolSession['groupSupervision']=array_map(static fn($id)=>['groupId'=>$id,'kind'=>'school'],$schoolSession['groupIds']);
$a['planning']['sessions'][]=$schoolSession;
$b=$a;
$a['school']['name']='Alpha & school';$b['school']['name']='Beta school';
foreach($b['planning']['sessions'] as &$session){
 foreach(['startTime','endTime'] as $key){$session[$key]=date('H:i',strtotime($session[$key])+600);}
}unset($session);
$before=serialize([$a,$b]);$path=tempnam(sys_get_temp_dir(),'joint-xlsx-');
try{
 (new RosterXlsxWriter())->writeJoint($path,[$a,$b]);
 $assert($before===serialize([$a,$b]),'Export mutated source plans');
 $zip=new ZipArchive();$assert($zip->open($path,ZipArchive::CHECKCONS)===true,'Invalid ZIP');
 $workbook=$zip->getFromName('xl/workbook.xml');
 foreach(['School 1','School 2','Gezamenlijk']as$name)$assert(str_contains($workbook,$name),'Missing sheet '.$name);
 $documents=[];
 for($i=1;$i<=3;$i++){
  $d=new DOMDocument();$assert($d->loadXML($zip->getFromName('xl/worksheets/sheet'.$i.'.xml'),LIBXML_NONET),'Invalid XML');$documents[]=$d;
 }
 $joint=$documents[0]->textContent;
 $assert(str_contains($joint,'School 1')&&str_contains($joint,'Alpha & school')&&str_contains($joint,'School 2')&&str_contains($joint,'Beta school'),'School identities missing');
 foreach([$a,$b]as$p)foreach($p['planning']['sessions']as$s)$assert(str_contains($joint,$s['startTime'])&&str_contains($joint,$s['endTime']),'Lost shifted time boundary');
 $xp=new DOMXPath($documents[0]);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
 $headers=$xp->query('//s:row[s:c/s:is/s:t="Begintijd"]');$assert($headers->length===3,'Wrong shared header');
 $headerText=$headers->item(2)->textContent;
 $assert(!str_contains($headerText,'Niet ingezet'),'Unused teacher leaked into joint header');
 $assert(str_contains($headerText,'Testkok'),'Used cook missing');
 $assert(str_contains($headerText,'Schoolbegeleiding 1')&&str_contains($headerText,'Schoolbegeleiding 2'),'Separate school supervision columns missing');
 $assert($xp->query('//s:c/s:is/s:r/s:rPr/s:b')->length>0,'Bold module runs missing');
 $assert(str_contains($joint,'[School 1] = Alpha & school'),'Nearby legend missing');
 $schoolColumns=[];foreach($headers->item(2)->getElementsByTagName('c')as$c)if(preg_match('/Schoolbegeleiding ([12])/',$c->textContent,$m))$schoolColumns[$m[1]]=preg_replace('/[0-9]/','',$c->getAttribute('r'));
 foreach($schoolColumns as$n=>$column)foreach($xp->query('//s:row[number(@r)>'.$headers->item(2)->getAttribute('r').']/s:c')as$c)if(preg_replace('/[0-9]/','',$c->getAttribute('r'))===$column)$assert(!str_contains($c->textContent,'[School '.(3-$n).']'),'Other school leaked into supervision column');
 foreach([1,2] as $i){$hx=new DOMXPath($documents[$i]);$hx->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$hs=$hx->query('//s:row[s:c/s:is/s:t="Begintijd"]');$assert(!str_contains($hs->item(1)->textContent,'Niet ingezet'),'Unused teacher leaked into school header');}
 foreach($a['planning']['staffCatalog']as$person)$assert(substr_count($headerText,$person['name'])<=1,'Duplicate teacher column');
 foreach($schoolColumns as$n=>$column){$content='';foreach($xp->query('//s:row[number(@r)>'.$headers->item(2)->getAttribute('r').']/s:c')as$c)if(preg_replace('/[0-9]/','',$c->getAttribute('r'))===$column)$content.=$c->textContent;$assert(str_contains($content,'Earth Watch')&&str_contains($content,'[School '.$n.']'),'School supervision lesson lost');}
 $assert(str_contains($joint,'Groep')&&str_contains($joint,'Kok'),'Groups/cook missing');
 $assert(!str_contains($documents[1]->textContent,'Beta school'),'Other school leaked into school 1 sheet');
 $assert(!str_contains($documents[2]->textContent,'Alpha & school'),'Other school leaked into school 2 sheet');
 $styles=new DOMDocument();$assert($styles->loadXML($zip->getFromName('xl/styles.xml'),LIBXML_NONET),'Invalid styles');
 $assert($styles->getElementsByTagName('fill')->length>=10,'Module colors lost');
 $teacherRows=$xp->query('//s:row[number(@r) > '. $headers->item(2)->getAttribute('r') .']');
 $teacherText='';foreach($teacherRows as $row)$teacherText.=$row->textContent;
 $assert(!str_contains($teacherText,'Alpha & school')&&!str_contains($teacherText,'Beta school'),'Full school name repeated in teacher cells');
 $assert(str_contains($teacherText,'(Groep1)'),'Compact parenthesized groups missing');
 foreach([1,2]as$i){
  $assert(str_contains($documents[$i]->textContent,'Lesrooster')&&str_contains($documents[$i]->textContent,'Docentenrooster'),'Individual tab incomplete');
 }
 $fillColor=static function(DOMElement $cell)use($styles):string{
  $xf=$styles->getElementsByTagName('cellXfs')->item(0)->getElementsByTagName('xf')->item((int)$cell->getAttribute('s'));
  return $styles->getElementsByTagName('fills')->item(0)->getElementsByTagName('fill')->item((int)$xf->getAttribute('fillId'))->getElementsByTagName('fgColor')->item(0)?->getAttribute('rgb')??'';
 };
 $sharedWelcome=false;$free=false;
 foreach($teacherRows as$row)foreach($row->getElementsByTagName('c')as$cell){if($cell->textContent==='Vrij'){$free=true;$assert($fillColor($cell)==='FFEEF0F2','Free cell must have neutral fill');}if(str_contains($cell->textContent,'Kok ·'))$assert(preg_match('/^Kok ·[^\n]+\n\[School [12]\]/',$cell->textContent)===1,'Cook school label has inconsistent order');}
 $assert($free,'Expected free periods for used cook');
 foreach($teacherRows as$row)foreach($row->getElementsByTagName('c')as$cell){
  if(str_contains($cell->textContent,'Aankomst')&&str_contains($cell->textContent,'[School 1] · [School 2]')){
   $assert(substr_count($cell->textContent,'Aankomst')===1,'Duplicated joint welcome');$sharedWelcome=true;
   $assert($fillColor($cell)==='FFFFFFFF','Welcome cell is not white');
   $timeCells=$row->getElementsByTagName('c');
   $assert($fillColor($timeCells->item(0))==='FFFFFFFF'&&$fillColor($timeCells->item(1))==='FFFFFFFF','Neutral time background is not white');
  }
 }
 $assert($sharedWelcome,'Missing merged welcome for shared teacher');
 if($output=getenv('ROSTER_JOINT_XLSX_OUTPUT'))copy($path,$output);
 $zip->close();
 echo "PASS joint XLSX: five modules, ten groups, shifted boundaries, unique staff, cook, school labels, separate lessons, colors, unchanged source\n";
}finally{@unlink($path);}
