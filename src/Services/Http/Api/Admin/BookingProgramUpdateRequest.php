<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use InvalidArgumentException;
use JsonException;

final readonly class BookingProgramUpdateRequest
{
    /** @param list<BookingRuleOverrideRequest> $overrides */
    public function __construct(public int $bookingId,public string $expectedProgram,public string $proposedProgram,public array $overrides){}
    public static function fromJson(string $json):self
    {
        try{$payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){throw new BookingProgramUpdateRequestException('INVALID_REQUEST');}
        if(!is_array($payload)||array_is_list($payload)||array_diff(array_keys($payload),['bookingId','expected','proposed','overrides'])!==[]||array_diff(['bookingId','expected','proposed'],array_keys($payload))!==[])throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        if(!is_int($payload['bookingId'])||$payload['bookingId']<=0)throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        $expected=self::programObject($payload['expected']??null);$proposed=self::programObject($payload['proposed']??null);
        $raw=$payload['overrides']??[];if(!is_array($raw)||!array_is_list($raw))throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');
        $overrides=[];$seen=[];foreach($raw as $item){if(!is_array($item)||array_is_list($item)||count($item)!==2||array_diff(array_keys($item),['ruleCode','reason'])!==[]||!is_string($item['ruleCode']??null)||!is_string($item['reason']??null))throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');$code=trim($item['ruleCode']);if($code===''||isset($seen[$code]))throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');try{$overrides[]=new BookingRuleOverrideRequest($code,$item['reason']);}catch(InvalidArgumentException){throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');}$seen[$code]=true;}
        return new self($payload['bookingId'],$expected,$proposed,$overrides);
    }
    private static function programObject(mixed $value):string
    {
        if(!is_array($value)||array_is_list($value)||array_keys($value)!==['program']||!is_string($value['program'])||trim($value['program'])==='')throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        return trim($value['program']);
    }
}
