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
            if ((new \GeoFort\Services\Sql\RosterPairSqlRepository($this->pdo))->forPlan($planId) !== null) {
                throw new RosterPlanException('JOINT_LIFECYCLE_REQUIRED', 409, 'Maak gekoppelde roosters samen definitief of heropen ze samen via het gezamenlijke overzicht.');
            }
            if ($plan['status'] !== 'concept') throw new RosterPlanException('PLAN_NOT_CONCEPT', 409, 'Alleen een conceptrooster kan definitief worden gemaakt.');
            $result = $this->validator->validate($planId, $expectedRevision);
            if (!$result->isValid()) throw new RosterPlanException('ROSTER_INVALID', 422, 'Het rooster bevat blokkerende fouten. Controleer het overzicht.', $result->toArray());
            $this->publishLocked($plan, $expectedRevision, $actorId);
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
            if ((new \GeoFort\Services\Sql\RosterPairSqlRepository($this->pdo))->forPlan($planId) !== null) {
                throw new RosterPlanException('JOINT_LIFECYCLE_REQUIRED', 409, 'Maak gekoppelde roosters samen definitief of heropen ze samen via het gezamenlijke overzicht.');
            }
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

    private function publishLocked(array $plan, int $expectedRevision, int $actorId, ?array $jointContext = null): void
    {
        $planId = (int) $plan['id'];
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
                    'guidesForOneGroup' => $this->config->requiredGeoFortGuidesForSession($key, 1, false, $module['programVersion'] ?? null),
                    'guidesForTwoGroups' => $this->config->requiredGeoFortGuidesForSession($key, 2, false, $module['programVersion'] ?? null)];
            }
            $content = ['formatVersion' => RosterFinalSnapshot::FORMAT_VERSION, 'planId' => $planId,
                'sourceRevision' => $expectedRevision, 'publishedRevision' => $expectedRevision + 1,
                'finalizedByAdminId' => $actorId, 'finalizedAtUtc' => $time,
                'presentation' => $view, 'stored' => $data, 'occupancy' => $occupancy,
                'rules' => ['modules' => $ruleContext, 'maxModulesPerStaff' => 3, 'maxGroups' => RosterManualProgramPolicy::maxGroupsForModuleCount(count($ruleContext))]];
            if ($jointContext !== null) $content['jointFinalization'] = $jointContext;
            $snapshotId = $this->snapshots->append($planId, $expectedRevision, $actorId, $time, $content);
            $this->snapshots->transition($plan, 'published', $snapshotId, $actorId, $time);
    }

    /** Preview and apply share locks and validation; both snapshots commit together. */
    public function joint(array $revisions, string $mode, ?string $signature, bool $acceptedWarnings, int $actorId): array
    {
        if (count($revisions) !== 2 || !in_array($mode, ['preview', 'finalize', 'reopen'], true)) {
            throw new RosterPlanException('INVALID_PAIR', 422, 'Kies twee gekoppelde roosters.');
        }
        ksort($revisions, SORT_NUMERIC);
        $ids = array_keys($revisions);
        $initial = $this->plans->findById($ids[0]);
        if ($initial === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
        $this->pdo->beginTransaction();
        try {
            $catalogRevision = RosterStaffCatalogGuard::lock($this->pdo);
            $this->dates->lockDate($initial['visit_date']);
            $locked = []; $data = [];
            foreach ($ids as $id) {
                $plan = $this->plans->lockPlan($id);
                if ($plan === null || (int) $plan['revision'] !== $revisions[$id]) throw new RosterPlanException('STALE_REVISION', 409, 'Een rooster is gewijzigd. Controleer beide scholen opnieuw.');
                if ($this->plans->findById($id)['visit_date'] !== $initial['visit_date']) throw new RosterPlanException('PAIR_DATE_MISMATCH', 409, 'Beide scholen moeten op dezelfde bezoekdatum staan.');
                $locked[$id] = $plan;
            }
            $bookingIds = array_values(array_unique(array_filter(array_column($locked, 'booking_id'))));
            sort($bookingIds, SORT_NUMERIC);
            foreach ($bookingIds as $id) $this->raw->lockBooking((int) $id);
            $pair = (new \GeoFort\Services\Sql\RosterPairSqlRepository($this->pdo))->forPlan($ids[0]);
            $pairIds = $pair['planIds'] ?? []; sort($pairIds, SORT_NUMERIC);
            if ($pair === null || $pairIds !== $ids) throw new RosterPlanException('PAIR_REQUIRED', 409, 'Deze roosters zijn niet meer aan elkaar gekoppeld.');
            $published = count(array_filter($locked, static fn(array $p): bool => $p['status'] === 'published'));
            if ($mode === 'reopen') {
                if ($published !== 2) throw new RosterPlanException('PAIR_NOT_PUBLISHED', 409, 'Alleen twee definitieve gekoppelde roosters kunnen samen worden heropend.');
                $time = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
                foreach ($locked as $id => $plan) {
                    $snapshot = $this->snapshots->state($id)['current'];
                    if ($snapshot === null) throw new RosterPlanException('FINAL_SNAPSHOT_MISSING', 409, 'Een definitief rooster heeft geen betrouwbare snapshot.');
                    $this->snapshots->transition($plan, 'concept', (int) $snapshot['id'], $actorId, $time);
                }
                $this->pdo->commit();
                return ['applied' => true, 'status' => 'concept'];
            }
            foreach ($locked as $plan) if ($plan['status'] !== 'concept') throw new RosterPlanException('PAIR_NOT_CONCEPT', 409, 'Beide roosters moeten concept zijn om ze samen definitief te maken.');
            RosterPairGuard::groups($this->pdo, $ids[0]);
            $report = $this->validator->validatePlans($revisions);
            $errors = $report['day']['errors']; $warnings = $report['day']['warnings'];
            foreach ($report['plans'] as $id => $own) {
                $school = array_search((int) $id, $pair['planIds'], true) + 1;
                foreach (['errors', 'warnings'] as $kind) foreach ($own[$kind] as $issue) {
                    $issue['message'] = '[School ' . $school . '] ' . $issue['message'];
                    if ($kind === 'errors') $errors[] = $issue; else $warnings[] = $issue;
                }
                $data[$id] = $this->raw->load((int) $id);
            }
            $currentSignature = hash('sha256', json_encode([$pair, $catalogRevision, $data, $report], JSON_THROW_ON_ERROR));
            $result = ['signature' => $currentSignature, 'canFinalize' => $report['canFinalize'], 'errors' => $errors, 'warnings' => $warnings, 'applied' => false];
            if ($mode === 'preview') { $this->pdo->rollBack(); return $result; }
            if ($signature === null || !hash_equals($currentSignature, $signature)) throw new RosterPlanException('STALE_JOINT_CONTROL', 409, 'De planning of controle is gewijzigd. Bekijk eerst de nieuwe gezamenlijke controle.');
            if (!$report['canFinalize']) throw new RosterPlanException('ROSTER_INVALID', 422, 'Los eerst de blokkerende fouten in beide roosters op.', $result);
            if ($warnings !== [] && !$acceptedWarnings) throw new RosterPlanException('WARNINGS_NOT_ACCEPTED', 422, 'Bevestig eerst dat je de getoonde waarschuwingen accepteert.', $result);
            $context = ['pairId' => $pair['id'], 'planIds' => $pair['planIds'], 'controlSignature' => $currentSignature, 'acceptedWarnings' => $warnings];
            foreach ($locked as $id => $plan) $this->publishLocked($plan, $revisions[$id], $actorId, $context);
            $this->pdo->commit();
            return [...$result, 'applied' => true, 'status' => 'published'];
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
