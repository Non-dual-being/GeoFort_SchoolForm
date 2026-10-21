<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Sql\{RosterPlanSqlRepository, RosterBookingRefreshSqlRepository, StoredBookingSqlRepository};
use PDO;
use Throwable;

final readonly class RosterBookingRefreshService
{
    public function __construct(
        private PDO $pdo,
        private RosterPlanService $service,
        private RosterPlanSqlRepository $plans,
        private StoredBookingSqlRepository $bookings,
        private RosterPlanningConfig $config,
    ) {}

    public function preview(int $planId, int $revision): array
    {
        $this->pdo->beginTransaction();
        try {
            $result = $this->proposal($planId, $revision);
            $this->pdo->rollBack(); // Snapshot reads only; opening the review never changes a roster.
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function apply(int $planId, int $revision, string $signature, bool $accepted, int $actor): array
    {
        if (!$accepted) throw new RosterPlanException('REFRESH_CONFIRMATION_REQUIRED', 422, 'Controleer en bevestig eerst de getoonde wijzigingen.');
        $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::lock($this->pdo);
            $row = $this->plans->lockPlan($planId);
            if ($row === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
            if ($row['booking_id'] !== null) $this->bookings->findByIdForUpdate($row['booking_id']);
            $preview = $this->proposal($planId, $revision);
            if (!hash_equals($preview['signature'], $signature)) throw new RosterPlanException('REFRESH_PREVIEW_CHANGED', 409, 'De aanvraag of het rooster is gewijzigd. Bekijk de gevolgen opnieuw.');
            if (!$preview['canApply']) throw new RosterPlanException('REFRESH_BLOCKED', 409, $preview['blockedReason'] ?? 'Er zijn geen aanvraagwijzigingen te verwerken.');
            $before = $this->service->getPlan($planId);
            (new RosterBookingRefreshSqlRepository($this->pdo))->apply($before, $preview, $actor);
            RosterPairGuard::groups($this->pdo, $planId);
            $this->plans->advanceRevision($planId, $actor);
            $result = $this->service->getPlan($planId);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function label(string $key, mixed $value): string
    {
        if ($key === 'programVersion') return $value === null ? '5 modules (bestaande variant)' : ($value === 'day-four-2027' ? '4 modules' : (string)$value);
        if ($value === null) return 'Geen';
        if ($key === 'program') return $value === 'ochtend' ? 'Ochtendprogramma' : 'Dagprogramma';
        if ($key === 'choiceModuleKey') return $this->config->moduleOption((string)$value)['label'];
        if ($key === 'schoolSector') return $value === 'primairOnderwijs' ? 'Primair onderwijs' : 'Voortgezet onderwijs';
        if ($key === 'visitDate') return implode('-', array_reverse(explode('-', (string)$value)));
        return (string)$value;
    }

    private function proposal(int $planId, int $revision): array
    {
        $plan = $this->service->getPlan($planId);
        if ($plan === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
        if ($plan['status'] !== 'concept' || $plan['planningMode'] !== 'booking_locked') throw new RosterPlanException('REFRESH_NOT_ALLOWED', 409, 'Alleen een standaard conceptrooster kan de aanvraagwijzigingen overnemen. Maatwerk blijft behouden.');
        if ($plan['revision'] !== $revision) throw new RosterPlanException('REVISION_CONFLICT', 409, 'Het rooster is gewijzigd. Laad het opnieuw.');
        $booking = $this->bookings->findById($plan['bookingId']);
        if ($booking === null || !$booking->studentCount) throw new RosterPlanException('STUDENT_COUNT_REQUIRED', 422, 'Controleer het leerlingaantal van de aanvraag.');
        $moduleKeys = $this->config->requiredModuleKeys($booking->schoolSector, $booking->program, $booking->choiceModuleKey, $booking->programVersion);
        if (!RosterManualProgramPolicy::supportsModuleCount(count($moduleKeys))) throw new RosterPlanException('INVALID_MODULE_CONTEXT', 422, 'Controleer het programma van de aanvraag.');
        $count = (new RosterGroupCountResolver())->resolve($booking->schoolSector, $booking->program, $booking->studentCount, $booking->programVersion);
        $count = max(1, min(RosterManualProgramPolicy::maxGroupsForModuleCount(count($moduleKeys)), $count ?? (int) ceil($booking->studentCount / 16)));
        $currentContext = RosterSourceFingerprint::context($booking);
        $repository = new RosterBookingRefreshSqlRepository($this->pdo);
        $storedFingerprint = (string) $this->plans->findById($planId)['source_booking_fingerprint'];
        $previousContext = $repository->confirmedContext($planId, $storedFingerprint)
            ?? RosterBookingSourceHistory::resolve($currentContext, $storedFingerprint, $repository->bookingHistory($booking->id));
        $programChanged = $previousContext !== null && (
            $previousContext['program'] !== $currentContext['program']
            || ($previousContext['programVersion'] ?? null) !== ($currentContext['programVersion'] ?? null)
            || $previousContext['schoolSector'] !== $currentContext['schoolSector']);
        $choiceChanged = $previousContext !== null && $previousContext['choiceModuleKey'] !== $currentContext['choiceModuleKey'];
        $resetLessons = $programChanged || $choiceChanged;
        $groupChanged = count($plan['groups']) !== $count;
        $dateChanged = $plan['visitDate'] !== $booking->visitDate;
        $clearStaffing = $resetLessons || $groupChanged || $dateChanged;
        $changes = [];
        foreach (['program'=>'Programma', 'programVersion'=>'Programmavariant', 'choiceModuleKey'=>'Keuzemodule',
            'schoolSector'=>'Onderwijssector', 'studentCount'=>'Leerlingen', 'supervisorCount'=>'Schoolbegeleiders', 'visitDate'=>'Bezoekdatum'] as $key => $label) {
            if ($previousContext !== null && ($previousContext[$key] ?? null) !== ($currentContext[$key] ?? null)) {
                $changes[] = ['label'=>$label, 'before'=>$this->label($key, $previousContext[$key] ?? null), 'after'=>$this->label($key, $currentContext[$key] ?? null)];
            }
        }
        if ($groupChanged) $changes[] = ['label'=>'Groepen', 'before'=>(string)count($plan['groups']), 'after'=>(string)$count];
        $groups = $plan['groups'];
        usort($groups, static fn(array $a, array $b): int => $a['position'] <=> $b['position']);
        $remainingIds = array_column(array_slice($groups, 0, $count), 'id');
        $affected = [];
        foreach ($plan['planning']['sessions'] as $session) {
            $remaining = !$resetLessons ? array_values(array_intersect($session['groupIds'], $remainingIds)) : [];
            if ($remaining !== $session['groupIds']) $affected[] = [
                'id' => $session['id'], 'moduleLabel' => $session['moduleLabel'], 'groupLabels' => $session['groupLabels'],
                'startTime' => $session['startTime'], 'endTime' => $session['endTime'], 'remainingGroupIds' => $remaining,
                'removedGroupLabels' => array_values(array_map(static fn(int $id): string => $groups[array_search($id, array_column($groups, 'id'), true)]['label'], array_values(array_diff($session['groupIds'], $remaining)))),
            ];
        }
        $fingerprint = RosterSourceFingerprint::of($booking);
        $blocked = $previousContext === null ? 'De oorspronkelijke aanvraaggegevens zijn niet volledig te herleiden. Laat dit rooster eerst handmatig controleren; er wordt niets leeggemaakt.' : null;
        if ($plan['jointPlanning'] !== null && $plan['visitDate'] !== $booking->visitDate) $blocked = 'De bezoekdatum is veranderd. Controleer eerst de gekoppelde scholen; hun bezoekdatum moet gelijk blijven.';
        if ($plan['jointPlanning'] !== null) {
            foreach ($plan['jointPlanning']['planIds'] as $otherId) {
                if ($otherId === $planId) continue;
                $other = $this->service->getPlan($otherId);
                if ($count + count($other['groups'] ?? []) > 10) $blocked = 'De gewijzigde groepsindeling overschrijdt samen de grens van tien groepen. Controleer beide scholen.';
            }
        }
        $changed = !$plan['sourceCurrent'] || count($groups) !== $count || $plan['visitDate'] !== $booking->visitDate;
        return [
            'planId' => $planId, 'revision' => $revision, 'studentCount' => $booking->studentCount,
            'beforeGroupCount' => count($groups), 'afterGroupCount' => $count,
            'beforeDate' => $plan['visitDate'], 'afterDate' => $booking->visitDate,
            'removedGroups' => array_slice($groups, $count), 'addedGroupCount' => max(0, $count - count($groups)),
            'affectedLessons' => $affected,
            'preservedLessonCount' => count($plan['planning']['sessions']) - count($affected),
            'remainingLessonCount' => count($plan['planning']['sessions']) - count(array_filter($affected, static fn(array $s): bool => $s['remainingGroupIds'] === [])),
            'moduleLabels' => array_map(fn(string $key): string => $this->config->moduleOption($key, $booking->programVersion)['label'], $moduleKeys),
            'changeType' => $previousContext === null ? 'unresolved' : ($programChanged ? 'program' : ($choiceChanged ? 'choice_module' : ($groupChanged ? 'groups' : 'details'))),
            'changes' => $changes, 'resetLessons' => $resetLessons, 'clearStaffing' => $clearStaffing,
            'staffedLessonCount' => count(array_filter($plan['planning']['sessions'], static fn(array $s): bool => $s['staffAssignments'] !== [] || array_filter($s['groupStaffAssignments'] ?? [], static fn(array $g): bool => $g['staffAssignments'] !== []) !== [] || ($s['moduleKey'] === 'Voedsel-Innovatie' && $s['cookStaffId'] !== null))),
            'sourceContext' => $currentContext, 'beforeSourceContext' => $previousContext,
            'sourceFingerprint' => $fingerprint, 'canApply' => $changed && $blocked === null, 'blockedReason' => $blocked,
            'signature' => hash('sha256', json_encode([$planId, $revision, $fingerprint, $groups, $plan['planning']['sessions'], $plan['planning']['staffingSettings'], $plan['jointPlanning'], $previousContext, $resetLessons, $clearStaffing], JSON_THROW_ON_ERROR)),
        ];
    }
}
