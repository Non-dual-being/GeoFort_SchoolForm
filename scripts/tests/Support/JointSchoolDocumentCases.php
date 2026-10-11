<?php
use GeoFort\Services\Dashboard\Roster\{RosterSchoolProjection, RosterPlanException};
use GeoFort\Booking\Status\BookingStatusChangeCode;
// Included by the document integration test; uses its disposable database and capturing mail transport.
$pairBookingA = $book(); $pairBookingB = $book();
$pairDate = $s['bookings']->findById($pairBookingA)->visitDate;
$db->pdo->prepare('UPDATE aanvragen SET bezoekdatum=?, schoolnaam=?, email=? WHERE id=?')->execute([$pairDate,'School Maan','maan@example.test',$pairBookingA]);
$db->pdo->prepare('UPDATE aanvragen SET bezoekdatum=?, schoolnaam=?, email=? WHERE id=?')->execute([$pairDate,'School Zon','zon@example.test',$pairBookingB]);
$pairPlanA = RosterLifecycleFixtures::validPlan($db->pdo,$s,28,1,2,$pairDate,$pairBookingA);
$pairPlanB = RosterLifecycleFixtures::validPlan($db->pdo,$s,28,1,2,$pairDate,$pairBookingB);
foreach ([[$pairPlanA,'School Maan'],[$pairPlanB,'School Zon']] as [$p,$name]) $db->pdo->prepare('UPDATE roster_manual_plan_context SET school_name=? WHERE roster_plan_id=?')->execute([$name,$p['id']]);
$pairPlans = [$pairPlanA,$pairPlanB]; $pairRevisions = array_fill_keys(array_column($pairPlans,'id'),1);
$db->pdo->beginTransaction();
(new GeoFort\Services\Sql\RosterPairSqlRepository($db->pdo))->create(array_keys($pairRevisions),$pairDate,'lessons_first',1);
$db->pdo->commit();
$pairCheck=$s['lifecycle']->joint($pairRevisions,'preview',null,false,1);
$assert($pairCheck['canFinalize'],'Paired fixture must be valid');
$s['lifecycle']->joint($pairRevisions,'finalize',$pairCheck['signature'],true,1);
$pairDocuments=[]; $pairSnapshots=[];
foreach ([[$pairBookingA,$pairPlanA,'School Maan','School Zon','maan@example.test'],[$pairBookingB,$pairPlanB,'School Zon','School Maan','zon@example.test']] as [$bid,$p,$own,$other,$email]) {
    $meta=$documents->available($bid); $assert(($meta['jointPlanning']??false)===true,'Paired final missing automatic mail selection metadata');
    $sid=$meta['snapshotId'];$pairSnapshots[$bid]=$sid;
    $school=(new RosterSchoolProjection())->fromSnapshot($s['snapshots']->find($p['id'],$sid)['content']);
    $assert($school['school']===$own && !str_contains(json_encode($school),$other),'Other school leaked into school projection');
    $assert(count($school['groups'])===1 && !str_contains(json_encode($school),'V1 '),'Internal staffing leaked');
    $doc=$documents->ensurePrepared($bid,$sid);$pairDocuments[$bid]=$doc;
    $assert($documents->readPrepared($bid,$sid)->bytes===$doc->bytes,'Preview bytes differ');
    $calls=count($mail->calls);$result=$status->change($command($bid,$sid));
    $assert($result->code===BookingStatusChangeCode::Success,'Paired confirmation failed: '.$result->code->value);
    $sent=$mail->calls[$calls];
    $assert($sent['toEmail']===$email && $sent['attachments'][0]->bytes===$doc->bytes,'Wrong recipient or paired PDF');
    $assert(count($sent['attachments'])===2,'Unexpected attachment count');
    file_put_contents($output.'/paired-'.($bid===$pairBookingA?'maan':'zon').'.pdf',$doc->bytes);
    file_put_contents($output.'/paired-'.($bid===$pairBookingA?'maan':'zon').'-mail.txt',$sent['textBody']);
}
try {$documents->readPrepared($pairBookingA,$pairSnapshots[$pairBookingB]); throw new LogicException('Cross-school document allowed');} catch (RosterPlanException) {}
$s['lifecycle']->joint(array_fill_keys(array_keys($pairRevisions),2),'reopen',null,false,1);
foreach ([$pairBookingA,$pairBookingB] as $bid) {
    $assert($documents->available($bid)===null,'Reopened paired PDF still available');
    try {$documents->readPrepared($bid,$pairSnapshots[$bid]);throw new LogicException('Reopened PDF allowed');} catch (RosterPlanException) {}
}
$pairRevisions=array_fill_keys(array_keys($pairRevisions),3);$pairCheck=$s['lifecycle']->joint($pairRevisions,'preview',null,false,1);
$s['lifecycle']->joint($pairRevisions,'finalize',$pairCheck['signature'],true,1);
foreach ([$pairBookingA,$pairBookingB] as $bid) {
    $new=$documents->available($bid)['snapshotId'];$assert($new!==$pairSnapshots[$bid],'Refinalization reused old snapshot');
    $assert($documents->ensurePrepared($bid,$new)->snapshotId===$new,'Missing refreshed school document');
    $assert($repository->find($pairSnapshots[$bid])->bytes===$pairDocuments[$bid]->bytes,'Old school document changed');
}
file_put_contents($output.'/paired-mail-check.json',json_encode(['passed'=>true,'recipients'=>['maan@example.test','zon@example.test'],'ownSchoolOnly'=>true,'previewEqualsMail'=>true,'crossSchoolRejected'=>true,'jointReopenRejectsBothOldDocuments'=>true,'refinalizationCreatesBothNewDocuments'=>true,'externalMailSent'=>false],JSON_PRETTY_PRINT));
