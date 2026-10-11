<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;
$fixtures=require __DIR__.'/fixtures/RosterXlsxPlans.php';
$a=$fixtures['modules-5']; $b=$a;
$make=static function(array $base,int $id,int $staff,string $start,string $end,string $key,string $label):array {
    return [...$base,'id'=>$id,'startTime'=>$start,'endTime'=>$end,'moduleKey'=>$key,'moduleLabel'=>$label,
        'groupIds'=>[1],'groupLabels'=>['Groep 1'],'groupStaffAssignments'=>[], 'groupSupervision'=>[],
        'staffAssignments'=>[['id'=>$staff,'name'=>'Docent '.$staff]], 'minimumGeoFortStaff'=>1];
};
$base=$a['planning']['sessions'][0];
$a['planning']['sessions']=[
    $make($base,1,901,'10:15','11:00','Earth-Watch','Earth Watch'),
    $make($base,2,901,'11:00','11:45','Earth-Watch','Earth Watch'),
];
$b['planning']['sessions']=[
    $make($base,3,902,'10:15','10:35','Zandtafel','Zandtafel'),
    $make($base,4,902,'10:35','10:55','Rising-Risk','Rising Risk'),
    $make($base,5,902,'10:55','11:15','Expedition-Earth','Expedition Earth'),
    $make($base,6,902,'11:15','11:45','Dynamische-Globe-Bios','Dynamische Globe Bios'),
];
foreach([&$a,&$b] as &$plan){$plan['planning']['rounds']=[];$plan['planning']['staffingSettings']['cookStaffId']=null;}
unset($plan);
$file=tempnam(sys_get_temp_dir(),'merged-roster');
try {
    $writer=new RosterXlsxWriter();$writer->writeJoint($file,[$a,$b]);
    $zip=new ZipArchive();$zip->open($file);
    $doc=new DOMDocument();$doc->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
    $xp=new DOMXPath($doc);$xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $headers=$xp->query('//s:row[s:c/s:is/s:t="Begintijd"]');
    $last=(int)$headers->item($headers->length-1)->getAttribute('r');
    $merges=[];foreach($xp->query('//s:mergeCell')as$m){if(preg_match('/^C(\d+):C(\d+)$/',$m->getAttribute('ref'),$v)&&$v[1]>$last)$merges[]=[$v[1],$v[2]];}
    if(count($merges)!==2)throw new RuntimeException('Expected two separate merged Earth Watch lessons: '.json_encode($merges));
    if($merges[0][1]-$merges[0][0]!==2)throw new RuntimeException('First 45 minute lesson must span three slices');
    $count=0;foreach($xp->query('//s:row[number(@r)>'.$last.']/s:c')as$c)if(str_starts_with($c->getAttribute('r'),'C')&&str_contains($c->textContent,'Earth Watch'))$count++;
    if($count!==2)throw new RuntimeException('Lesson text repeated across slices');
    $style=new ReflectionMethod($writer,'moduleStyle');
    $colors=[];foreach(['Zandtafel','Rising Risk','Expedition Earth','Dynamische Globe Bios','Dynamische Globe','Earth Watch','Klimaat Experience','Voedsel Innovatie']as$label)$colors[]=$style->invoke($writer,$label);
    if(count(array_unique($colors))!==count($colors))throw new RuntimeException('Morning/day modules share export color');
    echo "PASS merged lesson slices, separate consecutive lessons and distinct morning/day colors\n";
    $zip->close();
} finally {@unlink($file);}
