<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Sql\RosterSnapshotSqlRepository;
use GeoFort\Services\Sql\RosterValidationSqlRepository;

/** Capacity is concurrent groups, including immutable published occupancy on the visit date. */
final readonly class RosterModuleCapacityService
{
    public function __construct(
        private RosterValidationSqlRepository $raw,
        private RosterSnapshotSqlRepository $snapshots,
        private RosterPlanningConfig $config,
    ) {}

    /** @param list<array<string,mixed>> $ownOccupancy */
    public function validateForDate(int $planId, string $date, array $ownOccupancy, RosterValidationResult $result): void
    {
        $occupancy = $ownOccupancy;
        foreach ($this->raw->otherPlans($date, $planId) as $other) {
            if ($other['status'] !== 'published' || $other['published_snapshot_id'] === null) continue;
            $snapshot = $this->snapshots->state((int) $other['id'])['current'];
            if ($snapshot === null) {
                $result->error('FINAL_SNAPSHOT_MISSING', 'Een ander definitief rooster heeft geen geldige snapshot.');
                continue;
            }
            $occupancy = [...$occupancy, ...$snapshot['content']['occupancy']];
        }
        $this->validateOccupancy($occupancy, $result, $planId);
    }

    /** @param list<array<string,mixed>> $occupancy */
    public function validateOccupancy(array $occupancy, RosterValidationResult $result, int $ownPlanId): void
    {
        $byModule = [];
        $limits = [];
        foreach ($occupancy as $session) if (is_string($session['moduleKey'])) {
            foreach ($this->config->capacityPools($session['moduleKey']) as $pool => $limit) {
                $byModule[$pool][] = $session;
                $limits[$pool] = $limit;
            }
        }
        foreach ($byModule as $module => $sessions) foreach ($sessions as $point) {
            $active = array_filter($sessions, static fn (array $session): bool =>
                $session['startTime'] <= $point['startTime'] && $session['endTime'] > $point['startTime']);
            if (!in_array($ownPlanId, array_column($active, 'planId'), true)) continue;
            if (str_starts_with($module, 'KE/') && !in_array(substr($module, 3), array_column($active, 'moduleKey'), true)) continue;
            $reportedModule = str_starts_with($module, 'KE/') ? substr($module, 3) : $module;
            if (array_sum(array_column($active, 'groupCount')) > $limits[$module]) {
                // Own validation and day validation may encounter the same violation.
                $reported = array_filter($result->errors, static fn (array $error): bool => $error['code'] === 'MODULE_CAPACITY' && $error['moduleKey'] === $reportedModule);
                if ($reported !== []) break;
                $result->error('MODULE_CAPACITY', 'Te veel groepen volgen gelijktijdig ' . (BookingProgramConfig::MODULE_LABELS[$reportedModule] ?? $reportedModule) . '.', ['moduleKey' => $reportedModule]);
                break;
            }
        }
    }
}
