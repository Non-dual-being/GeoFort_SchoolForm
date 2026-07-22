<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use InvalidArgumentException;
use JsonException;

final readonly class BookingAttendanceUpdateRequest
{
    /** @param list<BookingRuleOverrideRequest> $overrides */
    public function __construct(public int $bookingId,public int $expectedStudentCount,public int $expectedSupervisorCount,public int $studentCount,public int $supervisorCount,public array $overrides){}
    public static function fromJson(string $json):self
    {
        try{$payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);$object=json_decode($json,false,512,JSON_THROW_ON_ERROR);}catch(JsonException){throw new BookingAttendanceUpdateRequestException('INVALID_REQUEST');}
        $keys=['bookingId','expectedStudentCount','expectedSupervisorCount','studentCount','supervisorCount','overrides'];
        $required=array_slice($keys,0,5);
        if(!is_array($payload)||array_is_list($payload)||array_diff(array_keys($payload),$keys)!==[]||array_diff($required,array_keys($payload))!==[])throw new BookingAttendanceUpdateRequestException('INVALID_REQUEST');
        foreach($required as $key)if(!is_int($payload[$key]))throw new BookingAttendanceUpdateRequestException('INVALID_REQUEST');
        if($payload['bookingId']<=0||$payload['expectedStudentCount']<0||$payload['expectedSupervisorCount']<0||$payload['studentCount']<=0||$payload['supervisorCount']<0)throw new BookingAttendanceUpdateRequestException('INVALID_REQUEST');
        if(is_object($object)&&property_exists($object,'overrides')&&!is_array($object->overrides))throw new BookingAttendanceUpdateRequestException('INVALID_OVERRIDE_REQUEST');
        $rawOverrides=$payload['overrides']??[];
        if(!is_array($rawOverrides)||!array_is_list($rawOverrides))throw new BookingAttendanceUpdateRequestException('INVALID_OVERRIDE_REQUEST');
        $overrides=[];$seen=[];foreach($rawOverrides as $raw){if(!is_array($raw)||array_is_list($raw)||array_diff(array_keys($raw),['ruleCode','reason'])!==[]||count($raw)!==2||!is_string($raw['ruleCode']??null)||!is_string($raw['reason']??null))throw new BookingAttendanceUpdateRequestException('INVALID_OVERRIDE_REQUEST');$code=trim($raw['ruleCode']);if($code===''||isset($seen[$code]))throw new BookingAttendanceUpdateRequestException('INVALID_OVERRIDE_REQUEST');try{$overrides[]=new BookingRuleOverrideRequest($code,$raw['reason']);}catch(InvalidArgumentException){throw new BookingAttendanceUpdateRequestException('INVALID_OVERRIDE_REQUEST');}$seen[$code]=true;}
        return new self($payload['bookingId'],$payload['expectedStudentCount'],$payload['expectedSupervisorCount'],$payload['studentCount'],$payload['supervisorCount'],$overrides);
    }
}
