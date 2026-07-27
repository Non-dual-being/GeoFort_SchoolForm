<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Http\Api\Admin\{BookingProgramUpdateRequest,BookingProgramUpdateRequestException};
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$request=BookingProgramUpdateRequest::fromJson('{"bookingId":181,"expected":{"program":"dag"},"proposed":{"program":"ochtend"},"overrides":[]}');
$assert($request->bookingId===181&&$request->expectedProgram==='dag'&&$request->proposedProgram==='ochtend','Expected/proposed contract wijkt af.');
foreach(['{}','{"bookingId":0,"expected":{"program":"dag"},"proposed":{"program":"ochtend"}}','{"bookingId":1,"expected":{"program":[]},"proposed":{"program":"ochtend"}}','{"bookingId":1,"expected":{"program":"dag"},"proposed":{"program":{}}}','{"bookingId":1,"expected":{"program":"dag"},"proposed":{"program":"ochtend"},"adminId":4}'] as $json){try{BookingProgramUpdateRequest::fromJson($json);throw new RuntimeException('Ongeldig request geaccepteerd.');}catch(BookingProgramUpdateRequestException $e){$assert($e->publicCode==='INVALID_REQUEST','Verkeerde requestcode.');}}
$endpoint=file_get_contents(dirname(__DIR__,2).'/public/api/admin/requests/update-booking-program.php');$action=file_get_contents(dirname(__DIR__,2).'/src/Services/Http/Api/Admin/DashboardBookingProgramUpdateAction.php');
$assert(str_contains($endpoint,'DashboardBookingProgramUpdateAction')&&str_contains($action,"CSRF_SCOPE='update-booking-program'")&&str_contains($action,"\$method!=='POST'")&&str_contains($action,"\$_SESSION['user_id']"),'Endpointbeveiliging of sessie-admin ontbreekt.');
fwrite(STDOUT,"OK: programma HTTP-contract geslaagd.\n");
