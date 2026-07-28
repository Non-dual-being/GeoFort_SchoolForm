<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Booking\Cjp\BookingCjpDetails;
use JsonException;

final readonly class BookingCjpUpdateRequest
{
    private const FIELDS = ['useCjp','contactName','cardNumber'];
    public function __construct(public int $bookingId, public BookingCjpDetails $expected, public BookingCjpDetails $proposed) {}

    public static function fromJson(string $json): self
    {
        try { $payload=json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new BookingCjpUpdateRequestException('INVALID_REQUEST'); }
        if (!is_array($payload) || array_is_list($payload) || self::differentKeys($payload, ['bookingId','expected','proposed']) || !is_int($payload['bookingId']) || $payload['bookingId'] <= 0) {
            throw new BookingCjpUpdateRequestException('INVALID_REQUEST');
        }
        return new self($payload['bookingId'], self::details($payload['expected']), self::details($payload['proposed']));
    }

    private static function details(mixed $value): BookingCjpDetails
    {
        if (!is_array($value) || array_is_list($value) || self::differentKeys($value, self::FIELDS) || !is_string($value['useCjp'])) {
            throw new BookingCjpUpdateRequestException('INVALID_REQUEST');
        }
        foreach (['contactName','cardNumber'] as $field) {
            if ($value[$field] !== null && !is_string($value[$field])) throw new BookingCjpUpdateRequestException('INVALID_REQUEST');
        }
        return new BookingCjpDetails($value['useCjp'], $value['contactName'], $value['cardNumber']);
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function differentKeys(array $value, array $keys): bool
    {
        return array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== [];
    }
}
