<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\ProgramConfiguration\BookingProgramConfigurationSnapshot;
use GeoFort\Booking\Rules\BookingRuleOverrideRequest;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use InvalidArgumentException;
use JsonException;

final readonly class BookingProgramConfigurationUpdateRequest
{
    /** @param list<BookingRuleOverrideRequest> $overrides */
    public function __construct(public int $bookingId, public BookingProgramConfigurationSnapshot $expected, public BookingProgramConfigurationSnapshot $proposed, public array $overrides) {}

    public static function fromJson(string $json): self
    {
        try { $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR); } catch(JsonException) { throw new BookingProgramUpdateRequestException('INVALID_REQUEST'); }
        if(!is_array($payload)||array_is_list($payload)||array_diff(array_keys($payload),['bookingId','expected','proposed','overrides'])!==[]||array_diff(['bookingId','expected','proposed'],array_keys($payload))!==[]) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        if(!is_int($payload['bookingId'])||$payload['bookingId']<=0) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        $expected=self::snapshot($payload['expected']??null,true);
        $proposedValues=self::snapshot($payload['proposed']??null,false,$expected);
        $raw=$payload['overrides']??[]; if(!is_array($raw)||!array_is_list($raw)) throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');
        $overrides=[];$seen=[]; foreach($raw as $item) {
            if(!is_array($item)||array_is_list($item)||array_keys($item)!==['ruleCode','reason']||!is_string($item['ruleCode'])||!is_string($item['reason'])) throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');
            $code=trim($item['ruleCode']); if($code===''||isset($seen[$code])) throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');
            try{$overrides[]=new BookingRuleOverrideRequest($code,$item['reason']);}catch(InvalidArgumentException){throw new BookingProgramUpdateRequestException('INVALID_OVERRIDE_REQUEST');}
            $seen[$code]=true;
        }
        return new self($payload['bookingId'],$expected,$proposedValues,$overrides);
    }

    private static function snapshot(mixed $value,bool $expected,?BookingProgramConfigurationSnapshot $base=null):BookingProgramConfigurationSnapshot
    {
        $keys=$expected?['status','visitDate','program','studentCount','educationSelection','choiceModule']:['program','studentCount','educationSelection','choiceModule'];
        if(!is_array($value)||array_is_list($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[]||array_diff($keys,array_keys($value))!==[]) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        foreach(['program'] as $key) if(!is_string($value[$key])||trim($value[$key])==='') throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        if(!is_int($value['studentCount'])) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        if($value['choiceModule']!==null&&!is_string($value['choiceModule'])) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        if($expected&&(!is_string($value['status'])||trim($value['status'])===''||!is_string($value['visitDate'])||trim($value['visitDate'])==='')) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        return new BookingProgramConfigurationSnapshot(
            $expected?trim($value['status']):$base->status,
            $expected?trim($value['visitDate']):$base->visitDate,
            trim($value['program']),
            $value['studentCount'],
            self::education($value['educationSelection']),
            $value['choiceModule']===null||trim($value['choiceModule'])===''?null:trim($value['choiceModule']),
        );
    }

    private static function education(mixed $value):EducationSelectionData
    {
        $keys=['sector','selectedLevels','selectedGroupsByLevel'];
        if(!is_array($value)||array_is_list($value)||count($value)!==3||array_diff(array_keys($value),$keys)!==[]||array_diff($keys,array_keys($value))!==[]||!is_string($value['sector'])||!is_array($value['selectedLevels'])||!array_is_list($value['selectedLevels'])||!is_array($value['selectedGroupsByLevel'])||array_is_list($value['selectedGroupsByLevel'])) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        foreach($value['selectedLevels'] as $level) if(!is_string($level)) throw new BookingProgramUpdateRequestException('INVALID_REQUEST');
        foreach($value['selectedGroupsByLevel'] as $level=>$groups) {if(!is_string($level)||!is_array($groups)||!array_is_list($groups))throw new BookingProgramUpdateRequestException('INVALID_REQUEST');foreach($groups as $group)if(!is_string($group))throw new BookingProgramUpdateRequestException('INVALID_REQUEST');}
        return new EducationSelectionData(trim($value['sector']),array_values($value['selectedLevels']),$value['selectedGroupsByLevel']);
    }
}
