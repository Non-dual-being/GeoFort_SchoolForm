<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\SchoolContact;

use GeoFort\Booking\SchoolContact\BookingSchoolContactDetails;
use GeoFort\Booking\SchoolContact\BookingSchoolContactIssue;
use GeoFort\Validation\FieldValidationException;
use GeoFort\Validation\FormRules;
use GeoFort\Validation\Validator;

final readonly class BookingSchoolContactValidator
{
    public function __construct(private Validator $validator = new Validator()) {}

    /** @return array{details:?BookingSchoolContactDetails,issues:list<BookingSchoolContactIssue>} */
    public function validate(BookingSchoolContactDetails $raw): array
    {
        $values = [];
        $issues = [];
        $checks = [
            'schoolName'=>fn()=> $this->validator->text('schoolnaam',$raw->schoolName,FormRules::RULES),
            'country'=>fn()=> $this->validator->text('land',$raw->country,FormRules::RULES),
            'address'=>fn()=> $this->validator->text('adres',$raw->address,FormRules::RULES),
            'city'=>fn()=> $this->validator->text('plaats',$raw->city,FormRules::RULES),
            'contactFirstName'=>fn()=> $this->validator->text('contactpersoonVoornaam',$raw->contactFirstName,FormRules::RULES),
            'contactLastName'=>fn()=> $this->validator->text('contactpersoonAchternaam',$raw->contactLastName,FormRules::RULES),
            'contactEmail'=>fn()=> $this->validator->email('email',$raw->contactEmail,FormRules::RULES),
        ];
        foreach ($checks as $field=>$check) {
            try {$values[$field]=$check();}
            catch (FieldValidationException $exception) {$issues[]=$this->issue($field,$exception->getMessage());}
        }
        $country = $values['country'] ?? trim($raw->country);
        foreach ([
            'postalCode'=>fn()=> $this->validator->postcode($country,$raw->postalCode,FormRules::RULES['postcode']),
            'schoolPhone'=>fn()=> $this->validator->phone('schoolTelefoonnummer',$country,$raw->schoolPhone,FormRules::RULES['schoolTelefoonnummer']),
            'contactPhone'=>fn()=> $this->validator->phone('contactpersoonTelefoonnummer',$country,$raw->contactPhone,FormRules::RULES['contactpersoonTelefoonnummer']),
        ] as $field=>$check) {
            try {$values[$field]=$check();}
            catch (FieldValidationException $exception) {$issues[]=$this->issue($field,$exception->getMessage());}
        }
        if ($issues !== []) return ['details'=>null,'issues'=>$issues];
        return ['details'=>new BookingSchoolContactDetails(
            $values['schoolName'],$values['country'],$values['address'],$values['postalCode'],
            $values['city'],$values['schoolPhone'],$values['contactFirstName'],
            $values['contactLastName'],$values['contactEmail'],$values['contactPhone'],
        ),'issues'=>[]];
    }

    private function issue(string $field, string $description): BookingSchoolContactIssue
    {
        $codes = [
            'schoolName'=>'MISSING_SCHOOL_NAME','country'=>'INVALID_COUNTRY',
            'postalCode'=>'INVALID_SCHOOL_POSTCODE','schoolPhone'=>'INVALID_SCHOOL_PHONE',
            'contactEmail'=>'INVALID_CONTACT_EMAIL','contactPhone'=>'INVALID_CONTACT_PHONE',
            'contactFirstName'=>'MISSING_CONTACT_NAME','contactLastName'=>'MISSING_CONTACT_NAME',
            'address'=>'INVALID_SCHOOL_ADDRESS','city'=>'INVALID_SCHOOL_CITY',
        ];
        return new BookingSchoolContactIssue(
            $codes[$field] ?? 'INVALID_SCHOOL_CONTACT_FIELD', $field,
            'Controleer dit veld', $description,
        );
    }
}
