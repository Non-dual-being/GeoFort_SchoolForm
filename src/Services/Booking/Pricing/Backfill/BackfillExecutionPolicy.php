<?php
declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing\Backfill;

use RuntimeException;

final class BackfillExecutionPolicy
{
    public const PRODUCTION_DATABASE = 'onderwijsboeking_v2';

    public function assertDevelopmentDatasetAllowed(string $appEnvironment, string $database): void
    {
        if ($appEnvironment !== 'development') {
            throw new RuntimeException('DEVELOPMENT_DATASET_REQUIRES_DEVELOPMENT_ENV');
        }
        if (strcasecmp($database, self::PRODUCTION_DATABASE) === 0) {
            throw new RuntimeException('DEVELOPMENT_DATASET_FORBIDDEN_FOR_PRODUCTION_DATABASE');
        }
    }
}
