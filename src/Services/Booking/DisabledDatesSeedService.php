<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking;

use GeoFort\Services\Sql\DisabledDatesSqlService;

final class DisabledDatesSeedService
{
    public function __construct(
        private readonly DisabledDateGenerator $generator,
        private readonly DisabledDatesSqlService $sqlService
    ) {}

    public function seedUntilEndSchoolYear2027(): void
    {
        $dates = $this->generator->generateUntilEndSchoolYear2027();

        $this->sqlService->upsertGeneratedDates($dates);
    }
}