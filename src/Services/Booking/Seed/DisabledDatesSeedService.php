<?php

declare(strict_types=1);
namespace GeoFort\Services\Booking\Seed;

use GeoFort\Services\Sql\DisabledDatesSqlService;
use GeoFort\Services\Booking\Availability\DisabledDateGenerator;

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