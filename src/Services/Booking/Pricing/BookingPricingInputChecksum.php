<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use JsonException;

final class BookingPricingInputChecksum
{
    public const FORMAT_VERSION = 1;

    /** @throws JsonException */
    public function canonicalJson(BookingPricingInput $input): string
    {
        return json_encode($input->canonicalData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @throws JsonException */
    public function checksum(BookingPricingInput $input): string
    {
        return hash('sha256', $this->canonicalJson($input));
    }

    public function matches(BookingPricingInput $input, string $checksum, int $formatVersion): bool
    {
        return $formatVersion === self::FORMAT_VERSION && hash_equals($checksum, $this->checksum($input));
    }
}
