<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\SchoolContact\{BookingSchoolContactChangeCode,BookingSchoolContactChangeResult};
use GeoFort\Services\Http\Api\Admin\{BookingSchoolContactUpdateRequest,BookingSchoolContactUpdateRequestException,BookingSchoolContactUpdateResponseMapper};

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$values=['schoolName'=>'School','country'=>'Nederland','address'=>'Dijk 1','postalCode'=>'1234 AB','city'=>'Plaats','schoolPhone'=>'010 123 4567','contactFirstName'=>'Jan','contactLastName'=>'Jansen','contactEmail'=>'jan@example.nl','contactPhone'=>'06 12345678'];
$json=json_encode(['bookingId'=>5,'expected'=>$values,'proposed'=>$values],JSON_THROW_ON_ERROR);
$request=BookingSchoolContactUpdateRequest::fromJson($json);$assert($request->bookingId===5&&$request->proposed->contactEmail==='jan@example.nl','Requestmapping faalt.');
foreach(['{',json_encode(['bookingId'=>5,'expected'=>$values],JSON_THROW_ON_ERROR),json_encode(['bookingId'=>5,'expected'=>$values,'proposed'=>$values+['unknown'=>'x']],JSON_THROW_ON_ERROR)] as $invalid){try{BookingSchoolContactUpdateRequest::fromJson($invalid);throw new RuntimeException('Ongeldige request geaccepteerd.');}catch(BookingSchoolContactUpdateRequestException){}}
$mapper=new BookingSchoolContactUpdateResponseMapper();
foreach([[BookingSchoolContactChangeCode::Success,200],[BookingSchoolContactChangeCode::NoChange,200],[BookingSchoolContactChangeCode::BookingNotFound,404],[BookingSchoolContactChangeCode::Conflict,409],[BookingSchoolContactChangeCode::InvalidDetails,422],[BookingSchoolContactChangeCode::DatabaseError,500]] as [$code,$status])$assert($mapper->status($code)===$status,"Statusmapping {$code->value} faalt.");
$mapped=$mapper->map(new BookingSchoolContactChangeResult(BookingSchoolContactChangeCode::Success,true,5));$assert(array_keys($mapped['payload'])===['ok','code','bookingId','previous','current','changedFields','validationIssues','changeHistoryId'],'Responsecontract wijkt af.');
$endpoint=(string)file_get_contents(dirname(__DIR__,2).'/public/api/admin/requests/update-booking-school-contact.php');$assert(str_contains($endpoint,'DashboardBookingSchoolContactUpdateAction'),'Endpoint routeert verkeerd.');
echo "BookingSchoolContactHttpContractTest OK\n";
