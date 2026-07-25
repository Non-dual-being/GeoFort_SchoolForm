<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Http\Api\Admin\BookingVisitDateUpdateRequest;
use GeoFort\Services\Http\Api\Admin\BookingVisitDateUpdateRequestException;

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$request=BookingVisitDateUpdateRequest::fromJson('{"bookingId":181,"expected":{"visitDate":"2026-09-14"},"proposed":{"visitDate":"2026-09-21"},"overrides":[]}');
$assert($request->bookingId===181&&$request->expectedVisitDate==='2026-09-14'&&$request->proposedVisitDate==='2026-09-21','Expected/proposed contract wijkt af.');
$reorderedOverride=BookingVisitDateUpdateRequest::fromJson('{"bookingId":181,"expected":{"visitDate":"2026-09-14"},"proposed":{"visitDate":"2026-09-21"},"overrides":[{"reason":"Planner accepteert bewust deze afwijkende bezoekdatum.","ruleCode":"DISABLED_VISIT_DATE"}]}');
$assert($reorderedOverride->overrides[0]->ruleCode==='DISABLED_VISIT_DATE','Override-object is ten onrechte afhankelijk van JSON-propertyvolgorde.');
foreach([
    ['{}','INVALID_REQUEST'],
    ['{"bookingId":"181","expected":{"visitDate":"2026-09-14"},"proposed":{"visitDate":"2026-09-21"}}','INVALID_REQUEST'],
    ['{"bookingId":0,"expected":{"visitDate":"2026-09-14"},"proposed":{"visitDate":"2026-09-21"}}','INVALID_REQUEST'],
    ['{"bookingId":181,"expected":{"visitDate":"2026-09-14T00:00:00Z"},"proposed":{"visitDate":"2026-09-21"}}','INVALID_VISIT_DATE'],
    ['{"bookingId":181,"expected":{"visitDate":"2026-02-30"},"proposed":{"visitDate":"2026-09-21"}}','INVALID_VISIT_DATE'],
] as [$json,$expectedCode]){try{BookingVisitDateUpdateRequest::fromJson($json);throw new RuntimeException('Ongeldig request geaccepteerd.');}catch(BookingVisitDateUpdateRequestException $exception){$assert($exception->publicCode===$expectedCode,"Verkeerde requestcode voor {$json}.");}}
$endpoint=file_get_contents(dirname(__DIR__,2).'/public/api/admin/requests/update-booking-visit-date.php');
$action=file_get_contents(dirname(__DIR__,2).'/src/Services/Http/Api/Admin/DashboardBookingVisitDateUpdateAction.php');
$assert(str_contains($endpoint,'DashboardBookingVisitDateUpdateAction')&&str_contains($action,"CSRF_SCOPE='update-booking-visit-date'")&&str_contains($action,"\$method!=='POST'"),'Endpointbeveiligingscontract ontbreekt.');
fwrite(STDOUT,"OK: visit-date HTTP-contract geslaagd.\n");
