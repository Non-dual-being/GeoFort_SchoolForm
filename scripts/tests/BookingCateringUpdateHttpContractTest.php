<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use GeoFort\Services\Booking\Catering\{BookingCateringChangeCode,BookingCateringChangeResult};
use GeoFort\Services\Http\Api\Admin\{BookingCateringUpdateRequest,BookingCateringUpdateRequestException,BookingCateringUpdateResponseMapper};
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$json='{"bookingId":5,"expected":{"remiseBreak":0,"kazerneBreak":0,"fortgrachtBreak":0,"waterIce":0,"lemonade":0,"remiseLunch":0,"ownPicnic":false},"proposed":{"remiseBreak":1,"kazerneBreak":0,"fortgrachtBreak":0,"waterIce":0,"lemonade":0,"lunchChoice":"eigen_picknick","remiseLunch":0}}';
$request=BookingCateringUpdateRequest::fromJson($json);$assert($request->bookingId===5&&$request->proposed->eigenPicknick,'Nested requestmapping faalt.');
$contradictory=BookingCateringUpdateRequest::fromJson(str_replace('"remiseLunch":0}}','"remiseLunch":50}}',$json));
$assert($contradictory->proposed->eigenPicknick&&$contradictory->proposed->remiseLunch===50,'Semantisch lunchconflict wordt ten onrechte als technische requestfout afgewezen.');
try{BookingCateringUpdateRequest::fromJson(str_replace('"bookingId":5','"bookingId":5,"unknown":1',$json));throw new RuntimeException('Onbekende key geaccepteerd.');}catch(BookingCateringUpdateRequestException){}
$mapper=new BookingCateringUpdateResponseMapper();
foreach([[BookingCateringChangeCode::Success,200],[BookingCateringChangeCode::NoCateringChange,200],[BookingCateringChangeCode::BookingNotFound,404],[BookingCateringChangeCode::CateringConflict,409],[BookingCateringChangeCode::InvalidRequest,422],[BookingCateringChangeCode::InvalidCateringSelection,422],[BookingCateringChangeCode::InvalidStoredBooking,422],[BookingCateringChangeCode::DatabaseError,500]] as [$code,$status])$assert($mapper->status($code)===$status,"Verkeerde status voor {$code->value}.");
$mapped=$mapper->map(new BookingCateringChangeResult(BookingCateringChangeCode::Success,true,5,changeHistoryId:44));
$encoded=json_encode($mapped,JSON_THROW_ON_ERROR);$assert(str_contains($encoded,'"changeHistoryId":44')&&!str_contains($encoded,'mailMode')&&!str_contains($encoded,'overrideCount')&&!str_contains($encoded,'capacity'),'Responsecontract lekt uitgesloten velden.');
$endpoint=file_get_contents(dirname(__DIR__,2).'/public/api/admin/requests/update-booking-catering.php');$assert(str_contains((string)$endpoint,'DashboardBookingCateringUpdateAction'),'Endpoint routeert verkeerd.');
echo "Booking catering update HTTP contract tests passed.\n";
