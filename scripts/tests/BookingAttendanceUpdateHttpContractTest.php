<?php
declare(strict_types=1);

use GeoFort\Booking\Attendance\BookingAttendanceChangeCode;
use GeoFort\Booking\Attendance\BookingAttendanceChangeResult;
use GeoFort\Services\Http\Api\Admin\BookingAttendanceUpdateRequest;
use GeoFort\Services\Http\Api\Admin\BookingAttendanceUpdateRequestException;
use GeoFort\Services\Http\Api\Admin\BookingAttendanceUpdateResponseMapper;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$rejects=static function(string $json,string $code='INVALID_REQUEST')use($assert):void{try{BookingAttendanceUpdateRequest::fromJson($json);}catch(BookingAttendanceUpdateRequestException $e){$assert($e->publicCode===$code,"Verwacht {$code}, kreeg {$e->publicCode}");return;}throw new RuntimeException("Payload had {$code} moeten geven: {$json}");};
$base='"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":95,"supervisorCount":7';
$valid=BookingAttendanceUpdateRequest::fromJson('{'.$base.',"overrides":[]}');
$assert([$valid->bookingId,$valid->expectedStudentCount,$valid->expectedSupervisorCount,$valid->studentCount,$valid->supervisorCount,$valid->overrides]===[157,80,6,95,7,[]],'Exacte payload parseert niet.');
$assert(BookingAttendanceUpdateRequest::fromJson('{'.$base.'}')->overrides===[],'Ontbrekende overrides normaliseren niet naar [].');
$assert(BookingAttendanceUpdateRequest::fromJson('{'.$base.',"overrides":[]}')->overrides===[],'Lege overrides blijven niet leeg.');
$rejects('{'.$base.',"extra":true}');
$rejects('{'.$base.',"actingAdminId":9}');
foreach(['95.5','"95"','true','null','[]'] as $invalid)$rejects('{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":'.$invalid.',"supervisorCount":7}');
foreach(['6.5','"6"','false','null','[]'] as $invalid)$rejects('{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":95,"supervisorCount":'.$invalid.'}');
$rejects('{'.$base.',"overrides":[{"ruleCode":"X","reason":"123456789012345","extra":true}]}','INVALID_OVERRIDE_REQUEST');
$rejects('{'.$base.',"overrides":[{"ruleCode":"X","reason":"123456789012345"},{"ruleCode":"X","reason":"123456789012345"}]}','INVALID_OVERRIDE_REQUEST');
$rejects('{'.$base.',"overrides":[{"ruleCode":"X","reason":"12345678901234"}]}','INVALID_OVERRIDE_REQUEST');
$accepted=BookingAttendanceUpdateRequest::fromJson('{'.$base.',"overrides":[{"ruleCode":"X","reason":"123456789012345"}]}');
$assert(strlen($accepted->overrides[0]->reason)===15,'Reden van 15 tekens wordt niet geaccepteerd.');
$rejects('{'.$base.',"overrides":[{"ruleCode":"X","reason":"'.str_repeat('x',501).'"}]}','INVALID_OVERRIDE_REQUEST');
$mapper=new BookingAttendanceUpdateResponseMapper();
$statuses=[[BookingAttendanceChangeCode::Success,200],[BookingAttendanceChangeCode::BookingNotFound,404],[BookingAttendanceChangeCode::AttendanceConflict,409],[BookingAttendanceChangeCode::OverrideRequired,409],[BookingAttendanceChangeCode::InvalidRequest,422],[BookingAttendanceChangeCode::NoChanges,422],[BookingAttendanceChangeCode::OverridePermissionDenied,403],[BookingAttendanceChangeCode::DatabaseError,500]];
foreach($statuses as [$code,$status]){$assert($mapper->status($code)===$status,"Verkeerde HTTP-status voor {$code->value}");$mapped=$mapper->map(new BookingAttendanceChangeResult($code,$code===BookingAttendanceChangeCode::Success,157));$json=json_encode($mapped,JSON_THROW_ON_ERROR);foreach(['exception','sqlstate','pdoexception','runtimeexception','BookingAttendanceChangeService'] as $secret)$assert(!str_contains(strtolower($json),strtolower($secret)),'Response lekt technische details.');}
$endpoint=(string)file_get_contents(dirname(__DIR__,2).'/public/api/admin/requests/update-booking-attendance.php');
$client=(string)file_get_contents(dirname(__DIR__,2).'/resources/js/admin/services/dashboardBookingAttendanceApi.ts');
$assert(str_contains($client,'/api/admin/requests/update-booking-attendance.php'),'Frontend gebruikt niet de definitieve endpointnaam.');
$assert(str_contains($endpoint,'DashboardBookingAttendanceUpdateAction'),'Endpoint routeert niet naar attendance-action.');
echo "Booking attendance update HTTP contract tests passed.\n";
