<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\{RosterPlanSqlRepository, RosterStaffSqlRepository};
use PDO;
use Throwable;

final readonly class RosterPlanStaffSelectionService
{
    public function __construct(private PDO $pdo, private RosterPlanSqlRepository $plans, private RosterStaffSqlRepository $staff) {}

    public function save(int $planId, int $expectedRevision, int $expectedCatalogRevision, array $staffIds, int $actorId): array
    {
        $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::expect(RosterStaffCatalogGuard::lock($this->pdo), $expectedCatalogRevision);
            $plan = $this->plans->lockPlan($planId);
            if ($plan === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
            if ($plan['status'] !== 'concept') throw new RosterPlanException('PLAN_NOT_EDITABLE', 409, 'Alleen een conceptrooster heeft een bewerkbare dagselectie.');
            if ($plan['revision'] !== $expectedRevision) throw new RosterPlanException('STALE_REVISION', 409, 'Het rooster is gewijzigd. Laad het opnieuw.');
            $previous = $this->staff->selectedIdsForPlan($planId);
            $members = array_column($this->staff->catalog(), null, 'id');
            foreach ($staffIds as $id) {
                $member = $members[$id] ?? null;
                if ($member === null || (!in_array($id, $previous, true) && (!$member['isActive'] || (!$member['canGuide'] && !$member['canCook'])))) {
                    throw new RosterPlanException('STAFF_NOT_SELECTABLE', 422, 'Nieuwe selectie vereist een actieve medewerker met een planningsrol.');
                }
            }
            $assigned = array_values((new \GeoFort\Services\Sql\RosterSessionCookSqlRepository($this->pdo))->forPlan($planId));
            foreach ($this->staff->assignmentsForPlan($planId) as $rows) foreach ($rows as $row) $assigned[] = $row['id'];
            foreach ($this->staff->groupAssignmentsForPlan($planId) as $groups) foreach ($groups as $group) foreach ($group['staffAssignments'] as $row) $assigned[] = $row['id'];
            $settings = $this->staff->settingsForPlan($planId);
            if ($settings['cookStaffId'] !== null) $assigned[] = $settings['cookStaffId'];
            if (array_intersect(array_diff($previous, $staffIds), $assigned) !== []) {
                throw new RosterPlanException('STAFF_STILL_ASSIGNED', 409, 'Vervang eerst de begeleiderstoewijzingen of kokkeuze voordat je deze medewerker uit de dagselectie verwijdert.');
            }
            $this->staff->replacePlanSelection($planId, $staffIds);
            $this->staff->replacePlanModulePreferences($planId, array_values(array_filter($settings['plannerModulePreferences'], static fn (array $row): bool => in_array($row['staffId'], $staffIds, true))));
            $revision = $this->plans->advanceRevision($planId, $actorId);
            $this->pdo->commit();
            return ['revision' => $revision];
        } catch (Throwable $exception) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $exception; }
    }
}
