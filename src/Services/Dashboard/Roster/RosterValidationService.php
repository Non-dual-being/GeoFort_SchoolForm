<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Sql\RosterValidationSqlRepository;
use GeoFort\Services\Sql\RosterSnapshotSqlRepository;
use Throwable;

/** The same raw-data rules serve the control tab and transactional finalization. */
final readonly class RosterValidationService
{
    public function __construct(
        private RosterValidationSqlRepository $raw,
        private RosterSnapshotSqlRepository $snapshots,
        private RosterPlanningConfig $config,
        private RosterGenerationTemplateProvider $templates,
    ) {}

    public function validate(int $planId, int $expectedRevision): RosterValidationResult
    {
        $data = $this->raw->load($planId);
        if ($data === null) throw new RosterPlanException('PLAN_NOT_FOUND', 404, 'Het rooster bestaat niet.');
        $result = $this->validateData($data, $expectedRevision);
        if ($data['plan']['status'] !== 'concept') return $result;
        $own = $this->occupancy($data);
        $finalOccupancy = $own;
        foreach ($this->raw->otherPlans((string) $data['plan']['visit_date'], $planId) as $other) {
            $final = $other['status'] === 'published' && $other['published_snapshot_id'] !== null;
            if ($final) {
                $snapshot = $this->snapshots->state((int) $other['id'])['current'];
                if ($snapshot === null) {
                    $result->error('FINAL_SNAPSHOT_MISSING', 'Een ander definitief rooster heeft geen geldige snapshot.');
                    continue;
                }
                $occupancy = $snapshot['content']['occupancy'];
                $finalOccupancy = [...$finalOccupancy, ...$occupancy];
            } else {
                $otherData = $this->raw->load((int) $other['id']);
                $occupancy = $otherData === null ? [] : $this->occupancy($otherData);
                if ($other['status'] !== 'concept') {
                    $result->warning('LEGACY_PLAN', 'Een ander rooster op deze dag heeft een oude status zonder betrouwbare finale snapshot.', ['otherPlanId' => (int) $other['id']]);
                }
            }
            foreach ($own as $a) foreach ($occupancy as $b) {
                if (!$this->overlaps($a, $b)) continue;
                if (array_intersect($a['staffIds'], $b['staffIds']) !== []) {
                    $result->{$final ? 'error' : 'warning'}('OTHER_PLAN_STAFF_CONFLICT', 'Een medewerker is tegelijk ingedeeld op een ander ' . ($final ? 'definitief' : 'concept/legacy') . ' rooster.',
                        ['sessionId' => $a['sessionId'], 'otherPlanId' => (int) $other['id']]);
                }
            }
        }
        $this->capacity($finalOccupancy, $result, $planId);
        return $result;
    }

    /** Pure validation of unfiltered stored rows; also used by focused domain tests. */
    public function validateData(array $data, int $expectedRevision): RosterValidationResult
    {
        $plan = $data['plan'];
        $id = (int) $plan['id'];
        $result = new RosterValidationResult($id, (int) $plan['revision']);
        if ((int) $plan['revision'] !== $expectedRevision) $result->error('STALE_REVISION', 'Het rooster is gewijzigd. Laad het opnieuw en controleer opnieuw.');
        if ($plan['status'] !== 'concept') {
            $result->error('PLAN_NOT_CONCEPT', 'Alleen een conceptrooster kan opnieuw definitief worden gemaakt.');
            return $result; // Published history is never validated against today's staff catalog.
        }
        $date = $plan['visit_date'] ?? null;
        if (!is_string($date) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $dateParts) !== 1
            || !checkdate((int) $dateParts[2], (int) $dateParts[3], (int) $dateParts[1])) {
            $result->error('INVALID_VISIT_DATE', 'De opgeslagen bezoekdatum is ongeldig.');
        }
        $custom = ($plan['planning_mode'] ?? '') === 'custom';
        if (!in_array($plan['planning_mode'] ?? '', ['custom', 'booking_locked'], true)) $result->error('INVALID_CONTEXT', 'De planningsmodus is ongeldig.');
        $context = $custom ? $data['context'] : $data['booking'];
        if ($context === null || (!$custom && $plan['booking_id'] === null)) {
            $result->error('INVALID_CONTEXT', 'De opgeslagen programma- of boekingscontext ontbreekt.');
            return $result;
        }
        $count = $context[$custom ? 'student_count' : 'aantal_leerlingen'] ?? null;
        if (!is_int($count) || $count <= 0) $result->error('INVALID_STUDENT_COUNT', 'Vul een positief geheel leerlingaantal in.');
        elseif ($count < 40 || $count > 160) $result->warning('STUDENT_COUNT_ADVICE', 'Het leerlingaantal ligt buiten de gebruikelijke bandbreedte van 40 tot 160. Dit blokkeert het rooster niet.');
        $sector = (string) ($context[$custom ? 'sector_key' : 'onderwijs_sector'] ?? '');
        $program = (string) ($context[$custom ? 'program_key' : 'programma'] ?? '');
        if (!isset(BookingProgramConfig::PROGRAMS[$program], BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector])) $result->error('INVALID_CONTEXT', 'Het opgeslagen programma of de sector is onbekend.');
        elseif (!BookingProgramConfig::isProgramAllowedForSchoolSector($program, $sector)
            || (is_string($date) && !BookingProgramConfig::isProgramAllowedForWeekday($program, (int) date('N', strtotime($date) ?: 0)))) {
            $result->error('INVALID_CONTEXT', 'Het opgeslagen programma past niet bij de onderwijssector of bezoekdag.');
        }
        $modules = [];
        try {
            $modules = $custom
                ? json_decode((string) ($context['module_keys_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR)
                : $this->config->requiredModuleKeys($sector, $program, $context['keuzemodule_key'] ?? null);
            if (!is_array($modules) || !array_is_list($modules) || !RosterManualProgramPolicy::supportsModuleCount(count($modules))) throw new \RuntimeException();
            foreach ($modules as $module) if (!is_string($module) || !isset(BookingProgramConfig::MODULE_LABELS[$module])) throw new \RuntimeException();
            if (count(array_unique($modules)) !== count($modules)) throw new \RuntimeException();
        } catch (Throwable) {
            $result->error('INVALID_MODULE_CONTEXT', 'Sla een geldig programma met twee tot vijf verschillende modules op.');
            return $result;
        }
        if (!$custom) {
            try {
                $booking = (new StoredBookingAssembler())->assemble($data['booking'], $data['educationSelections']);
                if (!hash_equals((string) ($plan['source_booking_fingerprint'] ?? ''), RosterSourceFingerprint::of($booking)) || $booking->visitDate !== $plan['visit_date']) {
                    $result->error('SOURCE_CHANGED', 'De aanvraag is gewijzigd. Beoordeel de afwijking en zet zo nodig om naar maatwerk.');
                }
            } catch (Throwable) {
                $result->error('INVALID_BOOKING_CONTEXT', 'De opgeslagen aanvraag bevat ongeldige gegevens.');
            }
        }
        $groups = array_column($data['groups'], null, 'id');
        $groupLimit = RosterManualProgramPolicy::maxGroupsForModuleCount(count($modules));
        if (count($groups) < 1 || count($groups) > min(10, $groupLimit)) $result->error('GROUP_LIMIT', "Dit programma ondersteunt maximaal {$groupLimit} groepen (absolute bovengrens: 10).");
        $positions = [];
        foreach ($data['groups'] as $group) {
            if ((int) $group['roster_plan_id'] !== $id || trim((string) $group['label']) === '' || !$this->positiveInteger($group['position']) || isset($positions[$group['position']])) $result->error('INVALID_GROUP', 'Een groep heeft een ongeldige relatie, naam of volgorde.', ['groupId' => (int) $group['id']]);
            $positions[$group['position']] = true;
        }
        $blocks = [];
        try {
            $blocks = $this->blocks($data, $program, count($modules));
            if (!RosterManualProgramPolicy::matchesTimelineStructure($blocks, count($modules), $program)) throw new \RuntimeException();
        } catch (Throwable) {
            $result->error('INVALID_TIMELINE', 'De volledige dagindeling heeft ongeldige tijden, volgorde of dagblokken.');
        }
        $lessons = array_values(array_filter($blocks, static fn (array $b): bool => $b['type'] === 'round'));
        $sessions = array_column($data['sessions'], null, 'id');
        $links = [];
        foreach ($data['links'] as $link) {
            $sid = (int) $link['roster_session_id']; $gid = (int) $link['roster_group_id'];
            if (!isset($sessions[$sid], $groups[$gid]) || (int) $link['session_plan_id'] !== $id || (int) $link['group_plan_id'] !== $id) {
                $result->error('INVALID_GROUP_RELATION', 'Een sessie is gekoppeld aan een groep of rooster buiten dit plan.', ['sessionId' => $sid, 'groupId' => $gid]);
            }
            if (isset($links[$sid][$gid])) $result->error('DUPLICATE_GROUP_RELATION', 'Een groepsrelatie komt dubbel voor.');
            $links[$sid][$gid] = $link;
        }
        $sessionStaff = []; $groupStaff = [];
        foreach ($data['sessionStaff'] as $row) $sessionStaff[(int) $row['roster_session_id']][] = (int) $row['staff_id'];
        foreach ($data['groupStaff'] as $row) {
            $sid = (int) $row['roster_session_id']; $gid = (int) $row['roster_group_id'];
            if (!isset($links[$sid][$gid]) || !isset($sessions[$sid], $groups[$gid])) $result->error('INVALID_STAFF_RELATION', 'Een groepsspecifieke medewerker heeft geen geldige sessie/groepsrelatie.');
            $groupStaff[$sid][$gid][] = (int) $row['staff_id'];
        }
        $staff = array_column($data['staff'], null, 'id'); $skills = [];
        foreach ($data['selectedStaff'] ?? [] as $selected) {
            $staffId = (int) $selected['staff_id'];
            if (!isset($staff[$staffId]) || (int) $staff[$staffId]['is_active'] !== 1) {
                $result->error('INACTIVE_SELECTED_STAFF', 'Een inactieve medewerker staat nog in de dagselectie. Los toewijzingen op en verwijder deze selectie expliciet.', ['staffId' => $staffId]);
            }
        }
        foreach ($data['skills'] as $skill) $skills[(int) $skill['staff_id']][$skill['module_key']] = true;
        $coverage = []; $staffModules = []; $occupancy = [];
        foreach ($sessions as $sid => $session) {
            $module = $session['module_key']; $meta = ['sessionId' => (int) $sid];
            if ($session['item_type'] !== 'activity' || !is_string($module) || !in_array($module, $modules, true)) {
                $result->error('INVALID_SESSION_MODULE', 'Een sessie heeft een onbekende of niet gekozen module.', $meta);
                continue;
            }
            $start = $this->time($session['start_time']); $end = $this->time($session['end_time']);
            $fits = false;
            foreach ($lessons as $lesson) if ($start !== null && $end !== null && $start >= $lesson['startTime'] && $end <= $lesson['endTime'] && $start < $end) $fits = true;
            if (!$fits) $result->error('INVALID_SESSION_TIME', 'Een les valt buiten de opgeslagen lesrondes of heeft ongeldige tijden.', $meta);
            $groupLinks = $links[$sid] ?? [];
            if (count($groupLinks) < 1 || count($groupLinks) > $this->config->maxGroupsPerSession($module)) $result->error('SESSION_GROUP_CAPACITY', 'Het aantal groepen in deze sessie overschrijdt de modulecapaciteit.', $meta);
            $guides = []; $staffedGroups = 0;
            foreach ($groupLinks as $gid => $link) {
                $coverage[$gid][$module] = ($coverage[$gid][$module] ?? 0) + 1;
                $kind = $link['supervision_kind'] ?? null;
                if ($kind !== null && !in_array($kind, ['school', 'staff', 'open'], true)) $result->error('INVALID_SUPERVISION', 'De opgeslagen begeleidingsvorm is ongeldig.', $meta);
                $assigned = RosterGroupSupervision::staffIds($kind, $groupStaff[$sid][$gid] ?? [], $sessionStaff[$sid] ?? []);
                if ($kind === 'school') {
                    if (!$this->config->schoolSupervisionAllowedForModuleCount($module, count($modules))) $result->error('SCHOOL_NOT_ALLOWED', 'Schoolbegeleiding is voor deze module in dit programma niet toegestaan.', [...$meta, 'groupId' => $gid]);
                    if (($groupStaff[$sid][$gid] ?? []) !== []) $result->error('AMBIGUOUS_SUPERVISION', 'Schoolbegeleiding en personeel zijn tegelijk opgeslagen voor dezelfde groep.', $meta);
                } else {
                    $staffedGroups++;
                    if ($assigned === []) $result->error('OPEN_SUPERVISION', 'Een groep heeft nog OPEN/onbezette begeleiding.', [...$meta, 'groupId' => $gid]);
                }
                foreach ($assigned as $staffId) $guides[$staffId] = true;
            }
            $required = $staffedGroups === 0 ? 0 : $this->config->requiredGeoFortGuidesForSession($module, $staffedGroups, false);
            if (count($guides) < $required || ($module === 'Voedsel-Innovatie' && count($guides) !== 1)) $result->error('GUIDES_REQUIRED', 'Deze sessie heeft niet het vereiste aantal GeoFort-begeleiders.', $meta);
            // The aggregate must not silently hide extra assignments or an explicit OPEN group.
            foreach ($sessionStaff[$sid] ?? [] as $staffId) if (!isset($guides[$staffId])) $result->error('AMBIGUOUS_STAFF_RELATION', 'De sessiebezetting wijkt af van de groepsspecifieke bezetting.', $meta);
            foreach (array_keys($guides) as $staffId) if (!in_array($staffId, $sessionStaff[$sid] ?? [], true)) $result->error('AMBIGUOUS_STAFF_RELATION', 'Een groepsbegeleider ontbreekt in de opgeslagen sessiebezetting.', $meta);
            foreach (array_keys($guides) as $staffId) {
                $member = $staff[$staffId] ?? null;
                if ($member === null || !(bool) $member['is_active'] || !(bool) $member['can_guide'] || !isset($skills[$staffId][$module])) $result->error('STAFF_NOT_QUALIFIED', 'Een begeleider is onbekend, inactief of niet bevoegd voor deze module.', [...$meta, 'staffId' => $staffId]);
                $staffModules[$staffId][$module] = true;
            }
            $assignedIds = array_keys($guides);
            if ($module === 'Voedsel-Innovatie') {
                $cookId = (int) ($data['settings']['cook_staff_id'] ?? 0); $cook = $staff[$cookId] ?? null;
                if ($cook === null || !(bool) $cook['is_active'] || !(bool) $cook['can_cook'] || isset($guides[$cookId])) $result->error('COOK_REQUIRED', 'Voedsel Innovatie vereist een actieve kok naast de begeleider.', $meta);
                if ($cookId > 0) $assignedIds[] = $cookId;
            }
            $occupancy[] = ['planId' => $id, 'sessionId' => (int) $sid, 'moduleKey' => $module, 'startTime' => $start ?? '', 'endTime' => $end ?? '', 'groupIds' => array_keys($groupLinks), 'groupCount' => count($groupLinks), 'staffIds' => array_values(array_unique($assignedIds))];
        }
        foreach ($groups as $gid => $group) foreach ($modules as $module) {
            $n = $coverage[$gid][$module] ?? 0;
            if ($n !== 1) $result->error($n === 0 ? 'MISSING_MODULE' : 'DUPLICATE_MODULE', $group['label'] . ': ' . BookingProgramConfig::MODULE_LABELS[$module] . ($n === 0 ? ' ontbreekt.' : ' is dubbel ingedeeld.'), ['groupId' => (int) $gid, 'moduleKey' => $module]);
        }
        foreach ($staffModules as $staffId => $staffModuleKeys) if (count($staffModuleKeys) > 3) $result->error('STAFF_MODULE_LIMIT', 'Een medewerker mag maximaal drie verschillende lesmodules begeleiden.', ['staffId' => $staffId]);
        foreach ($occupancy as $i => $a) foreach (array_slice($occupancy, $i + 1) as $b) {
            if (!$this->overlaps($a, $b)) continue;
            if (array_intersect($a['groupIds'], $b['groupIds']) !== []) $result->error('GROUP_OVERLAP', 'Een groep volgt gelijktijdig meerdere sessies.', ['sessionId' => $a['sessionId']]);
            if (array_intersect($a['staffIds'], $b['staffIds']) !== []) $result->error('STAFF_OVERLAP', 'Een medewerker is gelijktijdig op verschillende sessies ingedeeld.', ['sessionId' => $a['sessionId']]);
        }
        $this->capacity($occupancy, $result, $id);
        return $result;
    }

    /** Full authoritative timeline, retained in the snapshot. */
    public function blocks(array $data, string $program, int $moduleCount): array
    {
        $saved = $data['plan']['planning_mode'] === 'custom' ? ($data['context']['rounds_json'] ?? null) : null;
        $blocks = $saved === null ? $this->templates->defaultTimeline($program, $moduleCount)
            : RosterManualTimeline::fromArray(json_decode($saved, false, 32, JSON_THROW_ON_ERROR))->blocks;
        return RosterManualTimeline::fromArray(json_decode(json_encode($blocks, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR))->blocks;
    }

    /** Occupancy is frozen too, so later staffing/configuration edits cannot alter other finals. */
    public function occupancy(array $data): array
    {
        $sessionStaff = []; $groupStaff = []; $links = [];
        foreach ($data['sessionStaff'] as $r) $sessionStaff[$r['roster_session_id']][] = (int) $r['staff_id'];
        foreach ($data['groupStaff'] as $r) $groupStaff[$r['roster_session_id']][$r['roster_group_id']][] = (int) $r['staff_id'];
        foreach ($data['links'] as $r) $links[$r['roster_session_id']][] = $r;
        $result = [];
        foreach ($data['sessions'] as $s) {
            $ids = []; $groups = [];
            foreach ($links[$s['id']] ?? [] as $link) {
                $gid = (int) $link['roster_group_id']; $groups[] = $gid;
                $ids = [...$ids, ...RosterGroupSupervision::staffIds($link['supervision_kind'] ?? null, $groupStaff[$s['id']][$gid] ?? [], $sessionStaff[$s['id']] ?? [])];
            }
            if ($s['module_key'] === 'Voedsel-Innovatie' && ($data['settings']['cook_staff_id'] ?? null) !== null) $ids[] = (int) $data['settings']['cook_staff_id'];
            $result[] = ['planId' => (int) $data['plan']['id'], 'sessionId' => (int) $s['id'], 'moduleKey' => $s['module_key'], 'startTime' => substr((string) $s['start_time'], 0, 5), 'endTime' => substr((string) $s['end_time'], 0, 5), 'groupIds' => $groups, 'groupCount' => count($groups), 'staffIds' => array_values(array_unique($ids))];
        }
        return $result;
    }

    private function capacity(array $occupancy, RosterValidationResult $result, int $ownPlanId): void
    {
        $byModule = [];
        foreach ($occupancy as $s) if (is_string($s['moduleKey'])) $byModule[$s['moduleKey']][] = $s;
        foreach ($byModule as $module => $sessions) foreach ($sessions as $point) {
            $active = array_filter($sessions, static fn (array $s): bool => $s['startTime'] <= $point['startTime'] && $s['endTime'] > $point['startTime']);
            if (!in_array($ownPlanId, array_column($active, 'planId'), true)) continue;
            if (array_sum(array_column($active, 'groupCount')) > $this->config->maxParallel($module)) {
                $result->error('MODULE_CAPACITY', 'Te veel groepen volgen gelijktijdig ' . (BookingProgramConfig::MODULE_LABELS[$module] ?? $module) . '.', ['moduleKey' => $module]);
                break;
            }
        }
    }

    private function overlaps(array $a, array $b): bool { return $a['startTime'] < $b['endTime'] && $b['startTime'] < $a['endTime']; }
    private function positiveInteger(mixed $value): bool { return (is_int($value) && $value > 0) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1); }
    private function time(mixed $value): ?string { return is_string($value) && preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::00)?$/D', $value) === 1 ? substr($value, 0, 5) : null; }
}
