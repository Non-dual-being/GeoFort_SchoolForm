<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSnapshotSqlRepository;
use GeoFort\Services\Sql\RosterValidationSqlRepository;
use PDO;
use Throwable;

final readonly class RosterLifecycleService
{
    public function __construct(
        private PDO $pdo,
        private RosterPlanSqlRepository $plans,
        private BookingDaySettingsSqlRepository $dates,
        private RosterValidationSqlRepository $raw,
        private RosterSnapshotSqlRepository $snapshots,
        private RosterValidationService $validator,
        private RosterPlanService $presentation,
        private RosterPlanningConfig $config,
    ) {}

    public function validate(int $planId, int $expectedRevision): array
    {
        // A consistent read-only view. Finalize always repeats this after acquiring locks.
        $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::lock($this->pdo);
            $result = $this->validator->validate($planId, $expectedRevision);
            $this->pdo->rollBack();
            if ($result->revision !== $expectedRevision) throw new RosterPlanException('STALE_REVISION', 409, 'Het rooster is gewijzigd. Laad het opnieuw.', $result->toArray());
            return $result->toArray();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function finalize(int $planId, int $expectedRevision, int $actorId): array
    {
        $initial = $this->plans->findById($planId);
        if ($initial === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
        $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::lock($this->pdo);
            $plan = $this->lock($initial, $expectedRevision);
            if ($plan['status'] !== 'concept') throw new RosterPlanException('PLAN_NOT_CONCEPT', 409, 'Alleen een conceptrooster kan definitief worden gemaakt.');
            $result = $this->validator->validate($planId, $expectedRevision);
            if (!$result->isValid()) throw new RosterPlanException('ROSTER_INVALID', 422, 'Het rooster bevat blokkerende fouten. Controleer het overzicht.', $result->toArray());
            $data = $this->raw->load($planId);
            $view = $this->presentation->getPlan($planId);
            $time = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $occupancy = $this->validator->occupancy($data);
            $usedStaff = array_values(array_unique(array_merge([], ...array_column($occupancy, 'staffIds'))));
            // Selected teachers also appear in the accepted export, including their free rounds.
            $usedStaff = array_values(array_unique([...$usedStaff, ...array_map('intval', array_column($data['selectedStaff'], 'staff_id'))]));
            $data['staff'] = array_values(array_filter($data['staff'], static fn (array $s): bool => in_array((int) $s['id'], $usedStaff, true)));
            $data['skills'] = array_values(array_filter($data['skills'], static fn (array $s): bool => in_array((int) $s['staff_id'], $usedStaff, true)));
            $view['planning']['staffCatalog'] = array_values(array_filter($view['planning']['staffCatalog'], static fn (array $s): bool => in_array($s['id'], $usedStaff, true)));
            $ruleContext = [];
            foreach ($view['planning']['modules'] as $module) {
                $key = $module['key'];
                $ruleContext[] = [...$module, 'maxGroupsPerSession' => $this->config->maxGroupsPerSession($key),
                    'guidesForOneGroup' => $this->config->requiredGeoFortGuidesForSession($key, 1, false),
                    'guidesForTwoGroups' => $this->config->requiredGeoFortGuidesForSession($key, 2, false)];
            }
            $content = ['formatVersion' => RosterFinalSnapshot::FORMAT_VERSION, 'planId' => $planId,
                'sourceRevision' => $expectedRevision, 'publishedRevision' => $expectedRevision + 1,
                'finalizedByAdminId' => $actorId, 'finalizedAtUtc' => $time,
                'presentation' => $view, 'stored' => $data, 'occupancy' => $occupancy,
                'rules' => ['modules' => $ruleContext, 'maxModulesPerStaff' => 3, 'maxGroups' => RosterManualProgramPolicy::maxGroupsForModuleCount(count($ruleContext))]];
            $snapshotId = $this->snapshots->append($planId, $expectedRevision, $actorId, $time, $content);
            $this->snapshots->transition($plan, 'published', $snapshotId, $actorId, $time);
            $this->pdo->commit();
            return $this->presentation->getPlan($planId);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function reopen(int $planId, int $expectedRevision, int $actorId): array
    {
        $initial = $this->plans->findById($planId);
        if ($initial === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
        $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::lock($this->pdo);
            $plan = $this->lock($initial, $expectedRevision);
            if ($plan['status'] !== 'published') throw new RosterPlanException('PLAN_NOT_PUBLISHED', 409, 'Alleen een definitief rooster kan worden heropend.');
            $current = $this->snapshots->state($planId)['current'];
            $this->snapshots->transition($plan, 'concept', $current === null ? null : (int) $current['id'], $actorId,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'));
            $this->pdo->commit();
            return $this->presentation->getPlan($planId);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function lock(array $initial, int $expectedRevision): array
    {
        // Date -> plan -> booking: existing admin booking mutations also acquire the date first.
        // The first consistent read happens AFTER the date lock, so competing finals see the preceding commit.
        $this->dates->lockDate((string) $initial['visit_date']);
        $plan = $this->plans->lockPlan((int) $initial['id']);
        if ($plan === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
        if ($plan['revision'] !== $expectedRevision || (int) $initial['revision'] !== $expectedRevision) throw new RosterPlanException('STALE_REVISION', 409, 'Het rooster is gewijzigd. Laad het opnieuw en controleer opnieuw.');
        if ($plan['booking_id'] !== null) $this->raw->lockBooking((int) $plan['booking_id']);
        $current = $this->plans->findById((int) $initial['id']);
        if ($current['visit_date'] !== $initial['visit_date'] || $current['booking_id'] !== $initial['booking_id']) throw new RosterPlanException('STALE_CONTEXT', 409, 'De roosterdatum of aanvraag is gewijzigd. Laad het rooster opnieuw.');
        return $plan;
    }
}
