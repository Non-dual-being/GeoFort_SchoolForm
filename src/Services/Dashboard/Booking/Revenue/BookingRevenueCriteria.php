<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

final readonly class BookingRevenueCriteria
{
    public const SCOPE_DEFINITIVE = 'definitive';
    public const SCOPE_OPTION = 'option';
    public const SCOPE_COMBINED = 'combined';
    public const PER_PAGE = 10;

    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $scope = self::SCOPE_DEFINITIVE,
        public int $page = 1,
    ) {}
}
