<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Booking\Catering\BookingCateringValues;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use JsonException;

final readonly class BookingCateringUpdateRequest
{
    public function __construct(
        public int $bookingId,
        public BookingCateringValues $expected,
        public FoodAndDrinkSelectionData $proposed,
    ) {}

    public static function fromJson(string $json): self
    {
        try { $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new BookingCateringUpdateRequestException('INVALID_REQUEST'); }
        if (!is_array($payload) || array_is_list($payload) || self::differentKeys($payload, ['bookingId','expected','proposed'])) throw new BookingCateringUpdateRequestException('INVALID_REQUEST');
        if (!is_int($payload['bookingId']) || $payload['bookingId'] <= 0) throw new BookingCateringUpdateRequestException('INVALID_REQUEST');
        $expected = self::object($payload['expected'], ['remiseBreak','kazerneBreak','fortgrachtBreak','waterIce','lemonade','remiseLunch','ownPicnic']);
        $proposed = self::object($payload['proposed'], ['remiseBreak','kazerneBreak','fortgrachtBreak','waterIce','lemonade','lunchChoice','remiseLunch']);
        foreach (['remiseBreak','kazerneBreak','fortgrachtBreak','waterIce','lemonade','remiseLunch'] as $key) {
            if (!is_int($expected[$key]) || $expected[$key] < 0 || !is_int($proposed[$key]) || $proposed[$key] < 0) throw new BookingCateringUpdateRequestException('INVALID_REQUEST');
        }
        if (!is_bool($expected['ownPicnic']) || !is_string($proposed['lunchChoice']) || !in_array($proposed['lunchChoice'], ['remise_lunch','eigen_picknick'], true)) throw new BookingCateringUpdateRequestException('INVALID_REQUEST');
        $ownPicnic = $proposed['lunchChoice'] === 'eigen_picknick';
        return new self(
            $payload['bookingId'],
            new BookingCateringValues($expected['remiseBreak'],$expected['kazerneBreak'],$expected['fortgrachtBreak'],$expected['waterIce'],$expected['lemonade'],$expected['remiseLunch'],$expected['ownPicnic']),
            new FoodAndDrinkSelectionData($proposed['remiseBreak'],$proposed['kazerneBreak'],$proposed['fortgrachtBreak'],$proposed['waterIce'],$proposed['lemonade'],$proposed['lunchChoice'],$proposed['remiseLunch'],$ownPicnic),
        );
    }

    /** @param mixed $value @param list<string> $keys @return array<string,mixed> */
    private static function object(mixed $value, array $keys): array
    {
        if (!is_array($value) || array_is_list($value) || self::differentKeys($value, $keys)) throw new BookingCateringUpdateRequestException('INVALID_REQUEST');
        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function differentKeys(array $value, array $keys): bool
    {
        return array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== [];
    }
}
