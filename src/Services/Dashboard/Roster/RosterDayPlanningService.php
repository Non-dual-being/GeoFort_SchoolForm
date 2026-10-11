<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Services\Sql\RosterValidationSqlRepository;
use GeoFort\Services\Sql\RosterSnapshotSqlRepository;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\BookingDaySettingsSqlRepository;
use PDO;
use Throwable;

/** Day lesson planning; finalization repeats authoritative saved-data and staffing checks. */
final readonly class RosterDayPlanningService
{
    public function __construct(
        private PDO $pdo,
        private RosterValidationSqlRepository $raw,
        private RosterSnapshotSqlRepository $snapshots,
        private RosterValidationService $validator,
        private RosterAutoGenerator $generator,
        private RosterPlanningConfig $config,
    ) {}

    /** @param array<int,int> $expectedRevisions */
    public function preview(array $expectedRevisions): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::lock($this->pdo);
            $saved = $this->validator->validatePlans($expectedRevisions);
            $selected = array_keys($expectedRevisions);
            $date = $saved['day']['visitDate'];
            $fixed = [];
            foreach ($this->raw->otherPlans($date, $selected[0]) as $other) {
                if (in_array((int) $other['id'], $selected, true) || $other['status'] !== 'published') continue;
                $snapshot = $this->snapshots->state((int) $other['id'])['current'];
                if ($snapshot === null) throw new RosterPlanException('FINAL_SNAPSHOT_MISSING', 409, 'Een definitief rooster heeft geen geldige snapshot.');
                $fixed[] = $this->dayPlan($snapshot['content']['stored'], $snapshot['content']['occupancy'], (int) $other['revision']);
            }
            $options = [];
            $lessonErrors = [];
            foreach ($expectedRevisions as $id => $revision) {
                $data = $this->raw->load($id);
                if ($data['plan']['status'] === 'published') {
                    $snapshot = $this->snapshots->state($id)['current'];
                    $options[] = [$this->dayPlan($snapshot['content']['stored'], $snapshot['content']['occupancy'], $revision)];
                    continue;
                }
                if ($data['plan']['status'] !== 'concept') throw new RosterPlanException('PLAN_NOT_CONCEPT', 409, 'Gebruik een conceptrooster of een betrouwbaar definitief rooster.');
                if ($data['sessions'] !== []) {
                    $errors = $this->lessonErrors($data, $revision);
                    $lessonErrors = [...$lessonErrors, ...array_map(static fn (array $e): array => [...$e, 'planId' => $id], $errors)];
                    $options[] = [$this->dayPlan($data, $this->validator->occupancy($data), $revision)];
                    continue;
                }
                $candidates = [];
                $candidateErrors = [];
                foreach ($this->generator->lessonCandidates($id) as $proposal) {
                    $proposed = $this->proposalData($data, $proposal);
                    $errors = $this->lessonErrors($proposed, $revision);
                    if ($errors !== []) {
                        if ($candidateErrors === []) $candidateErrors = array_map(static fn (array $e): array => [...$e, 'planId' => $id], $errors);
                        continue;
                    }
                    $candidate = $this->dayPlan($proposed, $this->validator->occupancy($proposed), $revision);
                    $candidate['proposal'] = $proposal;
                    $candidates[] = $candidate;
                }
                if ($candidates === []) $lessonErrors = [...$lessonErrors, ...$candidateErrors];
                $options[] = $candidates;
            }
            $rules = new RosterDayRules($this->config);
            $checked = 0;
            $found = null;
            $conflicts = [];
            // At most 120 candidates per school, 14,400 combinations; absence is not an impossibility proof.
            foreach ($lessonErrors === [] ? $options[0] : [] as $a) {
                foreach ($options[1] ?? [null] as $b) {
                    $combination = $b === null ? [$a] : [$a, $b];
                    $day = $rules->validate([...$fixed, ...$combination], false, null, $selected);
                    $checked++;
                    $conflicts = $day['errors'];
                    if ($day['validLessons'] && $lessonErrors === []) {
                        $found = $combination;
                        break 2;
                    }
                }
            }
            if ($ownsTransaction) $this->pdo->rollBack();
            return [
                'savedControl' => $saved,
                'lessons' => [
                    'status' => $found !== null ? 'candidate_found' : ($lessonErrors !== [] ? 'saved_lessons_invalid' : 'no_candidate_found'),
                    'checkedCombinations' => $checked,
                    'errors' => $lessonErrors,
                    'conflicts' => $found === null ? $conflicts : [],
                    'schools' => $found === null ? [] : array_map(static fn (array $p): array => [
                        'planId' => $p['planId'], 'schoolName' => $p['schoolName'],
                        'isProposal' => isset($p['proposal']), 'sessions' => $p['proposal'] ?? [],
                    ], $found),
                ],
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** Save only a new lesson grid; earlier schools and personnel remain fixed. */
    public function applyLessons(array $expectedRevisions, int $adminId): array
    {
        if (count($expectedRevisions) < 1 || count($expectedRevisions) > 2) {
            throw new RosterPlanException('INVALID_DAY_PLANS', 422, 'Kies één of twee schoolroosters.');
        }
        $this->pdo->beginTransaction();
        try {
            RosterStaffCatalogGuard::lock($this->pdo);
            $ids = array_keys($expectedRevisions);
            $targetId = $ids[count($ids) - 1];
            $initial = $this->raw->load($ids[0]);
            (new BookingDaySettingsSqlRepository($this->pdo))->lockDate((string) $initial['plan']['visit_date']);
            $repository = new RosterPlanSqlRepository($this->pdo);
            $lockIds = $ids;
            sort($lockIds, SORT_NUMERIC);
            foreach ($lockIds as $id) {
                $locked = $repository->lockPlan($id);
                if ($locked === null || $locked['revision'] !== $expectedRevisions[$id]) {
                    throw new RosterPlanException('STALE_REVISION', 409, 'Een rooster is gewijzigd. Controleer opnieuw.');
                }
            }
            $target = $this->raw->load($targetId);
            if ($target['plan']['status'] !== 'concept' || $target['sessions'] !== []) {
                throw new RosterPlanException('LESSONS_ALREADY_EXIST', 409, 'De bestaande lesindeling blijft behouden. Bewerk deze in het schoolrooster.');
            }
            if (count($ids) === 2) {
                $first = $this->raw->load($ids[0]);
                if ($first['sessions'] === [] || $this->lessonErrors($first, $expectedRevisions[$ids[0]]) !== []) {
                    throw new RosterPlanException('FIRST_SCHOOL_REQUIRED', 422, 'Maak eerst de volledige lesindeling van school 1.');
                }
            }
            $preview = $this->preview($expectedRevisions);
            if ($preview['lessons']['status'] !== 'candidate_found') {
                throw new RosterPlanException('DAY_LESSONS_CONFLICT', 422, 'Geen passende lesindeling gevonden binnen de harde capaciteitsregels.');
            }
            $proposal = array_values(array_filter($preview['lessons']['schools'], static fn (array $p): bool => $p['planId'] === $targetId))[0]['sessions'];
            if ($proposal === []) throw new RosterPlanException('NO_LESSON_PROPOSAL', 422, 'Er is geen nieuwe lesindeling om op te slaan.');
            $sessions = new RosterSessionSqlRepository($this->pdo);
            foreach ($proposal as $session) {
                $sessionId = $sessions->insertActivity($targetId, $session['moduleKey'], $session['startTime'], $session['endTime'], null, $adminId);
                $sessions->replaceGroups($sessionId, $session['groupIds']);
            }
            $expectedRevisions[$targetId] = $repository->advanceRevision($targetId, $adminId);
            $result = $this->preview($expectedRevisions);
            if ($result['lessons']['status'] !== 'candidate_found') {
                throw new RosterPlanException('DAY_LESSONS_CONFLICT', 422, 'De opgeslagen lesindeling voldoet niet aan de harde regels.');
            }
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function lessonErrors(array $data, int $revision): array
    {
        // Personnel is checked separately against actual saved assignments, never invented for a lesson preview.
        $data['sessionStaff'] = $data['groupStaff'] = $data['selectedStaff'] = [];
        $data['settings'] = null;
        foreach ($data['links'] as &$link) $link['supervision_kind'] = 'open';
        unset($link);
        return array_values(array_filter($this->validator->validateData($data, $revision)->errors,
            static fn (array $e): bool => !in_array($e['code'], ['OPEN_SUPERVISION', 'GUIDES_REQUIRED', 'COOK_REQUIRED'], true)));
    }

    private function proposalData(array $data, array $proposal): array
    {
        $id = (int) $data['plan']['id'];
        $data['sessions'] = $data['links'] = $data['sessionStaff'] = $data['groupStaff'] = [];
        foreach ($proposal as $i => $session) {
            $sid = $i + 1;
            $data['sessions'][] = ['id' => $sid, 'roster_plan_id' => $id, 'item_type' => 'activity',
                'module_key' => $session['moduleKey'], 'start_time' => $session['startTime'], 'end_time' => $session['endTime']];
            foreach ($session['groupIds'] as $gid) $data['links'][] = [
                'roster_session_id' => $sid, 'roster_group_id' => $gid,
                'session_plan_id' => $id, 'group_plan_id' => $id, 'supervision_kind' => 'open',
            ];
        }
        return $data;
    }

    private function dayPlan(array $data, array $occupancy, int $revision): array
    {
        return ['planId' => (int) $data['plan']['id'], 'revision' => $revision,
            'visitDate' => (string) $data['plan']['visit_date'],
            'schoolName' => (string) ($data['context']['school_name'] ?? $data['booking']['schoolnaam'] ?? 'School'),
            'cookStaffId' => null, 'occupancy' => array_map(static fn (array $r): array => [...$r, 'staffIds' => []], $occupancy)];
    }
}
