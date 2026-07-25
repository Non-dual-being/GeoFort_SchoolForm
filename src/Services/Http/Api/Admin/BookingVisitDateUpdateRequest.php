<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use DateTimeImmutable;
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use InvalidArgumentException;
use JsonException;

final readonly class BookingVisitDateUpdateRequest
{
    /** @param list<BookingRuleOverrideRequest> $overrides */
    public function __construct(public int $bookingId,public string $expectedVisitDate,public string $proposedVisitDate,public array $overrides){}

    public static function fromJson(string $json):self
    {
        try{$payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(JsonException){throw new BookingVisitDateUpdateRequestException('INVALID_REQUEST');}
        if(!is_array($payload)||array_is_list($payload)||array_diff(array_keys($payload),['bookingId','expected','proposed','overrides'])!==[]||array_diff(['bookingId','expected','proposed'],array_keys($payload))!==[])throw new BookingVisitDateUpdateRequestException('INVALID_REQUEST');
        if(!is_int($payload['bookingId'])||$payload['bookingId']<=0)throw new BookingVisitDateUpdateRequestException('INVALID_REQUEST');
        $expected=self::dateObject($payload['expected']??null);$proposed=self::dateObject($payload['proposed']??null);
        $raw=$payload['overrides']??[];if(!is_array($raw)||!array_is_list($raw))throw new BookingVisitDateUpdateRequestException('INVALID_OVERRIDE_REQUEST');
        $overrides=[];$seen=[];foreach($raw as $item){if(!is_array($item)||array_is_list($item)||count($item)!==2||array_diff(array_keys($item),['ruleCode','reason'])!==[]||!is_string($item['ruleCode']??null)||!is_string($item['reason']??null))throw new BookingVisitDateUpdateRequestException('INVALID_OVERRIDE_REQUEST');$code=trim($item['ruleCode']);if($code===''||isset($seen[$code]))throw new BookingVisitDateUpdateRequestException('INVALID_OVERRIDE_REQUEST');try{$overrides[]=new BookingRuleOverrideRequest($code,$item['reason']);}catch(InvalidArgumentException){throw new BookingVisitDateUpdateRequestException('INVALID_OVERRIDE_REQUEST');}$seen[$code]=true;}
        return new self($payload['bookingId'],$expected,$proposed,$overrides);
    }
    private static function dateObject(mixed $value):string
    {
        if(!is_array($value)||array_is_list($value)||array_keys($value)!==['visitDate']||!is_string($value['visitDate']))throw new BookingVisitDateUpdateRequestException('INVALID_REQUEST');
        $date=$value['visitDate'];$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)!==1||$parsed===false||$parsed->format('Y-m-d')!==$date)throw new BookingVisitDateUpdateRequestException('INVALID_VISIT_DATE');
        return $date;
    }
}
