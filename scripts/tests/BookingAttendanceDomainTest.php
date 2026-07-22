<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Http\Api\Admin\BookingAttendanceUpdateRequest;
use GeoFort\Services\Http\Api\Admin\BookingAttendanceUpdateRequestException;

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$valid='{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":95,"supervisorCount":7,"overrides":[]}';
$request=BookingAttendanceUpdateRequest::fromJson($valid);
$assert($request->studentCount===95&&$request->supervisorCount===7,'Geldige gehele aantallen zijn niet behouden.');
$invalid=[
    '{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":0,"supervisorCount":7,"overrides":[]}',
    '{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":95.5,"supervisorCount":7,"overrides":[]}',
    '{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":"95","supervisorCount":7,"overrides":[]}',
    '{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":95,"supervisorCount":null,"overrides":[]}',
    '{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":95,"supervisorCount":7,"actingAdminId":1,"overrides":[]}',
];
foreach($invalid as $json){try{BookingAttendanceUpdateRequest::fromJson($json);throw new RuntimeException('Ongeldige payload werd geaccepteerd.');}catch(BookingAttendanceUpdateRequestException){}}
$duplicate='{"bookingId":157,"expectedStudentCount":80,"expectedSupervisorCount":6,"studentCount":95,"supervisorCount":7,"overrides":[{"ruleCode":"STUDENT_LIMIT_EXCEEDED","reason":"Dit is een voldoende lange reden."},{"ruleCode":"STUDENT_LIMIT_EXCEEDED","reason":"Dit is nog een voldoende lange reden."}]}';
try{BookingAttendanceUpdateRequest::fromJson($duplicate);throw new RuntimeException('Dubbele overridecode werd geaccepteerd.');}catch(BookingAttendanceUpdateRequestException){}
echo "Booking attendance domain and request tests passed.\n";
