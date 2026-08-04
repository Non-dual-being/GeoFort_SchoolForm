<?php
declare(strict_types=1);

use GeoFort\Services\Booking\Pricing\Backfill\BackfillExecutionPolicy;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$policy = new BackfillExecutionPolicy();
$policy->assertDevelopmentDatasetAllowed('development', 'geoform_db');
$assertRejected = static function (callable $action, string $expected): void {
    try {
        $action();
        throw new RuntimeException("Verwachte weigering {$expected} ontbreekt.");
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== $expected) throw $exception;
    }
};
$assertRejected(
    static fn () => $policy->assertDevelopmentDatasetAllowed('production', 'geoform_db'),
    'DEVELOPMENT_DATASET_REQUIRES_DEVELOPMENT_ENV',
);
$assertRejected(
    static fn () => $policy->assertDevelopmentDatasetAllowed('development', 'onderwijsboeking_v2'),
    'DEVELOPMENT_DATASET_FORBIDDEN_FOR_PRODUCTION_DATABASE',
);
echo "Booking price snapshot development policy tests passed.\n";
