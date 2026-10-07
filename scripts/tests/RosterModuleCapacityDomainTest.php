<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Roster\{RosterModuleCapacityService, RosterPlanningConfig, RosterValidationResult};
use GeoFort\Services\Sql\{RosterValidationSqlRepository, RosterSnapshotSqlRepository};

$pdo = new PDO('sqlite::memory:');
$capacity = new RosterModuleCapacityService(new RosterValidationSqlRepository($pdo), new RosterSnapshotSqlRepository($pdo), new RosterPlanningConfig());
$row = static fn (int $plan, string $module, int $groups, string $start = '10:15', string $end = '11:00'): array =>
    ['planId' => $plan, 'moduleKey' => $module, 'groupCount' => $groups, 'startTime' => $start, 'endTime' => $end];
$check = static function (array $occupancy, bool $valid) use ($capacity): void {
    $result = new RosterValidationResult(1, 1);
    $capacity->validateOccupancy($occupancy, $result, 1);
    if ($result->isValid() !== $valid) throw new RuntimeException(json_encode($result->toArray()));
    $capacity->validateOccupancy($occupancy, $result, 1);
    if (count($result->errors) > 1) throw new RuntimeException('Duplicate capacity error.');
};
$check([$row(1, 'Klimaat-Experience', 2)], true);
$check([$row(1, 'Klimaat-Experience', 2), $row(2, 'Klimaat-Experience', 1)], false);
$check([$row(1, 'Klimaat-Experience', 2), $row(2, 'Klimaat-Experience', 1, '11:00', '11:45')], true);
$check([$row(1, 'Klimaat-Experience', 2), $row(2, 'Klimaat-Experience', 1, '10:45', '11:30')], false);
$check([$row(1, 'Klimparcours', 2), $row(2, 'Klimparcours', 1)], true);
$check([$row(1, 'Klimparcours', 2), $row(2, 'Klimparcours', 2)], false);
$check([$row(1, 'Earth-Watch', 2), $row(2, 'Earth-Watch', 1)], false);
$check([$row(1, 'Earth-Watch', 1), $row(2, 'Earth-Watch', 1)], true);
$check([$row(1, 'Earth-Watch', 1), $row(1, 'Earth-Watch', 1), $row(2, 'Earth-Watch', 1)], false);
$check([$row(1, 'Earth-Watch', 2), $row(2, 'Klimparcours', 4)], true); // An unrelated module must not block own plan.
echo "PASS: KE=2, Vleermuizen=3, regular limits, shared-group counting, partial/boundary overlaps and deduplication.\n";
