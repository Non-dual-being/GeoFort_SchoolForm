<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\Cjp\{BookingCjpChangeCode,BookingCjpChangeResult};
use GeoFort\Services\Http\Api\Admin\{BookingCjpUpdateRequest,BookingCjpUpdateRequestException,BookingCjpUpdateResponseMapper};

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$values=['useCjp'=>'ja','contactName'=>'Jan de Vries','cardNumber'=>'12345678'];
$request=BookingCjpUpdateRequest::fromJson(json_encode(['bookingId'=>5,'expected'=>$values,'proposed'=>$values],JSON_THROW_ON_ERROR));
$assert($request->bookingId===5&&$request->proposed->cardNumber==='12345678','Requestmapping faalt.');
foreach(['{',json_encode(['bookingId'=>5,'expected'=>$values],JSON_THROW_ON_ERROR),json_encode(['bookingId'=>5,'expected'=>$values,'proposed'=>$values+['unknown'=>'x']],JSON_THROW_ON_ERROR),json_encode(['bookingId'=>5,'expected'=>$values,'proposed'=>array_merge($values,['cardNumber'=>123])],JSON_THROW_ON_ERROR)]as$invalid){try{BookingCjpUpdateRequest::fromJson($invalid);throw new RuntimeException('Ongeldige request geaccepteerd.');}catch(BookingCjpUpdateRequestException){}}
$mapper=new BookingCjpUpdateResponseMapper();
foreach([[BookingCjpChangeCode::Success,200],[BookingCjpChangeCode::NoChange,200],[BookingCjpChangeCode::BookingNotFound,404],[BookingCjpChangeCode::Conflict,409],[BookingCjpChangeCode::InvalidDetails,422],[BookingCjpChangeCode::DatabaseError,500]]as[$code,$status]){$mapped=$mapper->map(new BookingCjpChangeResult($code,$code===BookingCjpChangeCode::Success||$code===BookingCjpChangeCode::NoChange,5));$assert($mapped['status']===$status,"Statusmapping {$code->value} faalt.");}
$endpoint=(string)file_get_contents(dirname(__DIR__,2).'/public/api/admin/requests/update-booking-cjp.php');
$action=(string)file_get_contents(dirname(__DIR__,2).'/src/Services/Http/Api/Admin/DashboardBookingCjpUpdateAction.php');
$assert(str_contains($endpoint,'DashboardBookingCjpUpdateAction'),'Endpoint routeert verkeerd.');
foreach(['UNAUTHENTICATED','INVALID_CSRF','INVALID_REQUEST','METHOD_NOT_ALLOWED']as$code)$assert(str_contains($action,$code),"HTTP-code {$code} ontbreekt.");
echo "BookingCjpHttpContractTest OK\n";
