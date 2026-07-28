<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use GeoFort\Booking\SchoolContact\BookingSchoolContactDetails;
use GeoFort\Services\Booking\SchoolContact\BookingSchoolContactValidator;

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$validator=new BookingSchoolContactValidator();
$details=static fn(string $country='Nederland',string $postcode='1234ab',string $schoolPhone='010 123 4567',string $contactPhone='06 12345678',string $email='planner@example.nl',string $school=' Testschool '):BookingSchoolContactDetails=>new BookingSchoolContactDetails($school,$country,'Dijk 1',''.$postcode,'Herwijnen',$schoolPhone,'José','de Vries',$email,$contactPhone);
$nl=$validator->validate($details());
$assert($nl['issues']===[]&&$nl['details']?->postalCode==='1234 AB'&&$nl['details']?->schoolName==='Testschool','NL-validatie of normalisatie faalt.');
$be=$validator->validate($details('België','9700','09 123 45 67','0471 12 34 56','planner@example.be'));
$assert($be['issues']===[]&&$be['details']?->postalCode==='9700','BE-validatie faalt.');
foreach([
    [$details(school:''),'MISSING_SCHOOL_NAME'],
    [$details('Duitsland'),'INVALID_COUNTRY'],
    [$details(postcode:'0123 AB'),'INVALID_SCHOOL_POSTCODE'],
    [$details('België','1234 AB','09 123 45 67','0471 12 34 56'),'INVALID_SCHOOL_POSTCODE'],
    [$details(email:'ongeldig'),'INVALID_CONTACT_EMAIL'],
    [$details(schoolPhone:'123'),'INVALID_SCHOOL_PHONE'],
    [$details(contactPhone:'010 123 4567'),'INVALID_CONTACT_PHONE'],
] as [$input,$code]){$result=$validator->validate($input);$assert(in_array($code,array_map(static fn($issue)=>$issue->code,$result['issues']),true),"Issue {$code} ontbreekt.");}
echo "BookingSchoolContactDomainTest OK\n";
