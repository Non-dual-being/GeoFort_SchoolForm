<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\SchoolContact\BookingSchoolContactDetails;
use JsonException;

final readonly class BookingSchoolContactUpdateRequest
{
    private const FIELDS=['schoolName','country','address','postalCode','city','schoolPhone','contactFirstName','contactLastName','contactEmail','contactPhone'];
    public function __construct(public int $bookingId,public BookingSchoolContactDetails $expected,public BookingSchoolContactDetails $proposed){}
    public static function fromJson(string $json):self
    {
        try{$payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){throw new BookingSchoolContactUpdateRequestException('INVALID_REQUEST');}
        if(!is_array($payload)||array_is_list($payload)||self::differentKeys($payload,['bookingId','expected','proposed'])||!is_int($payload['bookingId'])||$payload['bookingId']<=0)throw new BookingSchoolContactUpdateRequestException('INVALID_REQUEST');
        return new self($payload['bookingId'],self::details($payload['expected']),self::details($payload['proposed']));
    }
    private static function details(mixed $value):BookingSchoolContactDetails
    {
        if(!is_array($value)||array_is_list($value)||self::differentKeys($value,self::FIELDS))throw new BookingSchoolContactUpdateRequestException('INVALID_REQUEST');
        foreach(self::FIELDS as $field)if(!is_string($value[$field]))throw new BookingSchoolContactUpdateRequestException('INVALID_REQUEST');
        return new BookingSchoolContactDetails(...array_values($value));
    }
    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function differentKeys(array $value,array $keys):bool{return array_diff(array_keys($value),$keys)!==[]||array_diff($keys,array_keys($value))!==[];}
}
