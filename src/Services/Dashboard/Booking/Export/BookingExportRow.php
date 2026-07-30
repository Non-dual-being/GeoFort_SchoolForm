<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Export;

final readonly class BookingExportRow
{
    /** @param list<string> $values */
    public function __construct(public array $values) {}
}
