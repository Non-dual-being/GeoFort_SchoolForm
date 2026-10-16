<?php
declare(strict_types=1);
require_once __DIR__.'/Support/RosterLifecycleFixtures.php';
use GeoFort\Services\Dashboard\Roster\{StaffDeploymentService as Service, StaffDeploymentXlsxWriter};
use GeoFort\Services\Sql\StaffDeploymentSqlRepository;
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$base=['staffId'=>1,'name'=>'=Test medewerker','employment'=>'volunteer','date'=>'2028-10-10','planId'=>1,'school'=>'School','schoolKey'=>'school|plaats','status'=>'Definitief','rosterStatus'=>'published','start'=>'10:00','end'=>'10:45','module'=>'Earth-Watch','moduleLabel'=>'Earth Watch','role'=>'Begeleider','sessionId'=>1];
$entries=[$base,array_replace($base,['planId'=>2]),array_replace($base,['start'=>'10:30','end'=>'11:00']),array_replace($base,['start'=>'11:15','end'=>'12:00','role'=>'Kok','module'=>'Voedsel-Innovatie','moduleLabel'=>'Voedsel Innovatie','status'=>'In optie','rosterStatus'=>'concept']),array_replace($base,['date'=>'2028-10-11','status'=>'Afgewezen'])];
$r=Service::summarize($entries)[0];
check($r['minutes']===105 && $r['statuses']['Afgewezen']===45,'Overlap, breaks and rejected totals');
check($r['cookMinutes']===45 && $r['guideMinutes']===60 && $r['schools']===1 && $r['days']===1,'Roles and unique school/day');
foreach([['from'=>'2028-02-30'],['from'=>'2028-10-11','to'=>'2028-10-10'],['status'=>'bad'],['staff'=>[]]] as $invalid){try{Service::filters($invalid);throw new RuntimeException('Invalid filter accepted');}catch(InvalidArgumentException){}}
$xlsx=tempnam(sys_get_temp_dir(),'inzet-test-');
try {
 (new StaffDeploymentXlsxWriter())->write($xlsx,['filters'=>Service::filters(['from'=>'2028-01-01','to'=>'2028-12-31']),'rows'=>[$r],'entries'=>$entries]);
 $zip=new ZipArchive();check($zip->open($xlsx)===true,'XLSX opens');
 for($i=1;$i<=4;$i++){ $xml=$zip->getFromName('xl/worksheets/sheet'.$i.'.xml');check(simplexml_load_string($xml)!==false,'Valid worksheet XML');check(!str_contains($xml,'<f>'),'No formulas from source text'); }
 check(str_contains($zip->getFromName('xl/worksheets/sheet1.xml'),'<v>1.75</v>'),'Numeric hours');$zip->close();
}finally{unlink($xlsx);}
echo "Domain and XLSX PASS\n";
$rates=[['id'=>1,'costRates'=>[['validFrom'=>'2028-01-01','validTo'=>null,'hourlyCostCents'=>2400]]]];
$filters=Service::filters(['from'=>'2028-01-01','to'=>'2028-12-31','costBasis'=>'span','extraMinutes'=>'15']);
$revenue=['confirmedCents'=>10000,'optionCents'=>0,'missing'=>0,'unlinked'=>0,'count'=>1];
$opportunities=[1=>[['startTime'=>'10:00','endTime'=>'12:00']],2=>[]];
$insights=\GeoFort\Services\Dashboard\Roster\StaffDeploymentInsights::build($entries,[$r],$rates,$opportunities,$filters,$revenue);
check($insights['daily'][0]['costMinutes']===135 && $insights['daily'][0]['costCents']===5400,'Span and extra minutes charged once per employee day');
check($insights['daily'][0]['gapMinutes']===15 && $insights['finance']['balanceCents']===4600,'Gaps and financial scenario');
$unknown=\GeoFort\Services\Dashboard\Roster\StaffDeploymentInsights::build($entries,[$r],[],$opportunities,$filters,$revenue);
check($unknown['finance']['unknownRateDays']===1 && $unknown['finance']['balanceCents']===null,'Unknown rates never count as zero');
$filters['staff']='1';$filtered=\GeoFort\Services\Dashboard\Roster\StaffDeploymentInsights::build($entries,[$r],$rates,$opportunities,$filters,$revenue);
check($filtered['finance']['balanceCents']===null,'No misleading per-person margin');
echo "Insights and financial scenario PASS\n";
check($insights['spanMinutes']===120 && $insights['minutes']+$insights['gapMinutes']+$insights['breakMinutes']===120,'Time components exactly reconcile');
$scopeEntries=[$base,array_replace($base,['date'=>'2029-05-03','staffId'=>2,'name'=>'Beta','status'=>'In optie','employment'=>'paid','rosterStatus'=>'concept']),array_replace($base,['date'=>'2030-02-04','staffId'=>3,'status'=>'Afgewezen'])];
$select=fn(array $q)=>\GeoFort\Services\Dashboard\Roster\StaffDeploymentScope::select($scopeEntries,Service::filters($q));
$all=$select([]);check($all['filters']['from']==='2028-10-10' && $all['filters']['to']==='2030-02-04','Initial dates use all available rosters');
$definitive=$select(['status'=>'Definitief']);check($definitive['dateBounds']===['min'=>'2028-10-10','max'=>'2028-10-10'],'Status changes automatic date limits');
check(array_column($definitive['options']['staff'],'value')===['1'] && array_column($definitive['options']['year'],'value')===['2028'],'Status constrains employees and years');
$paid=$select(['employment'=>'paid']);check($paid['filters']['from']==='2029-05-03' && array_column($paid['options']['staff'],'value')===['2'],'Employment updates dates and staff');
$year=$select(['year'=>'2029']);check(count($year['entries'])===1 && $year['entries'][0]['staffId']===2,'Year controls source rows');
$range=$select(['from'=>'2029-01-01','to'=>'2029-12-31']);check(array_column($range['options']['staff'],'value')===['2'],'Manual dates narrow other filters');
check(count($all['entries'])===2 && count($select(['status'=>'Afgewezen'])['entries'])===1,'Rejected shown only when explicitly selected');
$empty=$select(['status'=>'Definitief','employment'=>'paid']);check($empty['entries']===[] && $empty['dateBounds']['min']===null,'Impossible combinations remain empty; never broaden silently');
foreach([['year'=>[]],['year'=>'foo'],['range'=>'bad'],['from'=>[]]] as $invalid){try{Service::filters($invalid);throw new RuntimeException('Invalid scope accepted');}catch(InvalidArgumentException){}}
$xlsx=tempnam(sys_get_temp_dir(),'hours-test-');
try {
 (new StaffDeploymentXlsxWriter())->write($xlsx,['filters'=>$filters,'rows'=>[$r],'entries'=>$entries,'insights'=>$insights]);
 $zip=new ZipArchive();check($zip->open($xlsx)===true,'Hours export opens');
 check(!str_contains($zip->getFromName('xl/workbook.xml'),'Financieel'),'No financial export tab');
 for($i=1;$i<=6;$i++) { $xml=$zip->getFromName('xl/worksheets/sheet'.$i.'.xml');check(simplexml_load_string($xml)!==false,'Hours worksheet valid');check(!str_contains($xml,'EUR')&&!str_contains($xml,'Kosten')&&!str_contains($xml,'€'),'No misleading amounts in Excel'); }
 $zip->close();
}finally{unlink($xlsx);}
echo "Reactive scope, time reconciliation and hours-only XLSX PASS\n";
if(getenv('STATUS_TEST_DB_CONFIRM')!=='YES_DISPOSABLE')exit(0);
$db=DisposableBookingMariaDb::create('staff_deployment');
try {
 $pdo=$db->pdo;RosterLifecycleFixtures::schema($pdo);$s=RosterLifecycleFixtures::services($pdo);
 $plan=RosterLifecycleFixtures::validPlan($pdo,$s,18,1,2,'2028-10-10');$id=$plan['id'];
 $svc=new Service(new StaffDeploymentSqlRepository($pdo),$s['validator']);$f=['from'=>'2028-10-10','to'=>'2028-10-10'];
 $before=$svc->report($f);check(count($before['rows'])===2,'Two staff in concept');check(array_sum(array_column($before['rows'],'minutes'))===90,'Two 45 minute lessons');
 $member=$before['rows'][0]['id'];check(count($svc->report($f+['staff'=>(string)$member])['rows'])===1,'Staff filter');
 check($svc->report(['from'=>'2028-10-11','to'=>'2028-10-11'])['rows']===[],'Date filter');
 check($svc->report($f+['status'=>'Definitief'])['rows']===[],'Manual plan without request remains separate');
 $s['lifecycle']->finalize($id,1,1);
 $frozen=$svc->report($f+['roster'=>'published']);check(count($frozen['rows'])===2,'Published snapshot');
 $pdo->exec('DELETE FROM roster_session_staff WHERE roster_session_id IN (SELECT id FROM roster_sessions WHERE roster_plan_id='.$id.')');
 check($svc->report($f+['roster'=>'published'])===$frozen,'Published report stays frozen after raw changes');
 check($svc->report($f+['roster'=>'concept'])['rows']===[],'Roster status filter');
 $pdo->exec("INSERT INTO aanvragen(schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,status) VALUES('Test school','NL','Test','1234AB','Testplaats','','','Test','Planner','test@example.test','2028-10-10','primairOnderwijs','dag','In optie')");
 $booking=(int)$pdo->lastInsertId();$pdo->exec('UPDATE roster_plans SET booking_id='.$booking.' WHERE id='.$id);
 check(count($svc->report($f+['status'=>'In optie'])['rows'])===2,'Current option status');
 $pdo->exec("UPDATE aanvragen SET status='Afgewezen' WHERE id=".$booking);
 $rejected=$svc->report($f+['status'=>'Afgewezen']);check(count($rejected['rows'])===2 && array_sum(array_column($rejected['rows'],'minutes'))===90 && $rejected['insights']['minutes']===90,'Explicit rejected filter consistently shows original planned hours');
 check(!isset($rejected['insights']['finance'])&&!isset($rejected['insights']['daily'][0]['costCents']),'API exposes hours only');
 $pdo->exec("UPDATE aanvragen SET status='Definitief' WHERE id=".$booking);
 check(count($svc->report($f+['status'=>'Definitief'])['rows'])===2,'Current definitive status');
 check($s['service']->listPlans()[0]['bookingStatus']==='Definitief','Roster list exposes booking status independently');
 $pdo->exec("UPDATE roster_staff_members SET employment_type='volunteer'");
 $pdo->exec("UPDATE roster_staff_members SET employment_type='paid' WHERE id=".$member);
 check(count($svc->report($f+['employment'=>'paid'])['rows'])===1,'Employment filter');
 echo "Disposable database and frozen snapshot PASS\n";
} finally {$db->drop();}
