<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;

/** Pure rules for a selected day: no writes, generation, or changes to frozen snapshots. */
final readonly class RosterDayRules
{
    public function __construct(private RosterPlanningConfig $config) {}

    /**
     * Each plan supplies planId, revision, visitDate, schoolName, cookStaffId and occupancy.
     * A caller must load these from authoritative stored plans or published snapshots.
     * Confirmation is a stored decision, never an unchecked flag from a request body.
     * @param list<array<string,mixed>> $plans
     * @param list<int>|null $focusPlanIds Other plans are fixed background occupancy.
     * @return array<string,mixed>
     */
    public function validate(array $plans, bool $allowShared = false, ?string $confirmedSignature = null, ?array $focusPlanIds = null): array
    {
        $errors = []; $warnings = []; $rows = []; $names = []; $dates = []; $ids = [];
        $focus = array_fill_keys($focusPlanIds ?? array_map(static fn (array $p): int => (int) $p['planId'], $plans), true);
        foreach ($plans as $plan) {
            $id = (int) $plan['planId'];
            if ($id <= 0 || isset($ids[$id])) throw new RosterPlanException('INVALID_DAY_PLANS', 422, 'Gebruik verschillende geldige schoolroosters.');
            $ids[$id] = true; $names[$id] = (string) $plan['schoolName']; $dates[(string) $plan['visitDate']] = true;
            foreach ($plan['occupancy'] as $row) {
                $row['planId'] = $id;
                $row['staffIds'] = array_values(array_unique(array_map('intval', $row['staffIds'] ?? [])));
                $row['cookStaffId'] = $row['moduleKey'] === 'Voedsel-Innovatie' ? ($row['cookStaffId'] ?? $plan['cookStaffId'] ?? null) : null;
                $row['guideStaffIds'] = array_values(array_diff($row['staffIds'], [$row['cookStaffId']]));
                if (!isset(BookingProgramConfig::MODULE_LABELS[$row['moduleKey'] ?? ''])
                    || !is_int($row['groupCount'] ?? null) || $row['groupCount'] < 1
                    || !$this->time($row['startTime'] ?? null) || !$this->time($row['endTime'] ?? null)
                    || $row['startTime'] >= $row['endTime'] || array_filter($row['staffIds'],static fn(int $id):bool=>$id<=0)!==[]) {
                    throw new RosterPlanException('INVALID_DAY_OCCUPANCY', 422, 'De dagcontrole vereist geldige opgeslagen modules, groepen en tijden.');
                }
                $rows[] = $row;
            }
        }
        if (count($dates) !== 1 || $plans === []) throw new RosterPlanException('DAY_DATE_MISMATCH', 422, 'De schoolroosters moeten op dezelfde bezoekdatum staan.');
        $signature = $this->signature($plans);
        $confirmed = $confirmedSignature !== null && hash_equals($signature, $confirmedSignature);
        $byModule = [];
        $limits = [];
        foreach ($rows as $row) foreach ($this->config->capacityPools($row['moduleKey']) as $pool => $limit) {
            $byModule[$pool][] = $row; $limits[$pool] = $limit;
        }
        foreach ($byModule as $module => $sessions) {
            foreach ($sessions as $point) {
                $active = array_values(array_filter($sessions, static fn (array $r): bool => $r['startTime'] <= $point['startTime'] && $r['endTime'] > $point['startTime']));
                if (!$this->touchesFocus($active, $focus)) continue;
                if (str_starts_with($module, 'KE/') && !in_array(substr($module,3),array_column($active,'moduleKey'),true)) continue;
                $displayModule = str_starts_with($module, 'KE/') ? substr($module,3) : $module;
                $count = array_sum(array_column($active, 'groupCount'));
                if ($count <= $limits[$module]) continue;
                $errors[] = ['code'=>'MODULE_CAPACITY','severity'=>'error','message'=>'Te veel groepen volgen gelijktijdig '.BookingProgramConfig::MODULE_LABELS[$displayModule].'.',
                    'moduleKey'=>$displayModule,'startTime'=>$point['startTime'],'endTime'=>min(array_column($active,'endTime')),
                    'groupCount'=>$count,'maxGroups'=>$limits[$module],'schools'=>$this->schools($active,$names)];
                break;
            }
        }
        $staffRows = [];
        foreach ($rows as $row) foreach ($row['staffIds'] as $staffId) $staffRows[$staffId][] = $row;
        $shared = false;
        foreach ($staffRows as $staffId => $sessions) {
            if (!$this->touchesFocus($sessions, $focus)) continue;
            $modules = [];
            foreach ($sessions as $session) if (in_array($staffId,$session['guideStaffIds'],true)) $modules[RosterPlanningConfig::teachingFamily($session['moduleKey'])] = true;
            if (count($modules) > 3) $errors[] = ['code'=>'STAFF_DAY_MODULE_LIMIT','severity'=>'error','message'=>'Een medewerker mag over de hele dag maximaal drie verschillende lesmodules begeleiden.',
                'staffId'=>$staffId,'moduleKeys'=>array_keys($modules),'schools'=>$this->schools($sessions,$names)];
            $reported = [];
            foreach ($sessions as $i => $a) foreach (array_slice($sessions,$i+1) as $b) {
                if ($a['startTime'] >= $b['endTime'] || $b['startTime'] >= $a['endTime'] || !$this->touchesFocus([$a,$b],$focus)) continue;
                $active = array_values(array_filter($sessions, static fn (array $r): bool => $r['startTime'] < min($a['endTime'],$b['endTime']) && $r['endTime'] > max($a['startTime'],$b['startTime'])));
                $key = max($a['startTime'],$b['startTime']).'|'.min($a['endTime'],$b['endTime']);
                if (isset($reported[$key])) continue;
                $reported[$key] = true;
                $context = ['staffId'=>$staffId,'startTime'=>max($a['startTime'],$b['startTime']),'endTime'=>min($a['endTime'],$b['endTime']),
                    'schools'=>$this->schools($active,$names),'sessionIds'=>array_column($active,'sessionId')];
                $canShare = $this->canShare($active, (int) $staffId);
                $selectedPair = count($focus)===2 && count(array_filter($active,static fn(array $r):bool=>isset($focus[$r['planId']])))===count($active);
                if (!$canShare || !$allowShared || !$selectedPair) {
                    $errors[] = ['code'=>'STAFF_DAY_OVERLAP','severity'=>'error','message'=>$canShare
                        ? 'Gedeelde begeleiding tussen scholen is niet toegestaan zonder expliciete uitzondering.'
                        : 'Een medewerker is gelijktijdig op onverenigbare sessies ingedeeld.',...$context];
                    continue;
                }
                $shared = true;
                $warnings[] = ['code'=>'SHARED_SCHOOL_SUPERVISION','severity'=>'warning','message'=>'Uitzondering: deze medewerker begeleidt gelijktijdig groepen van verschillende scholen.',
                    'moduleKey'=>$a['moduleKey'],'groupCount'=>array_sum(array_column($active,'groupCount')),...$context];
            }
        }
        if ($shared && !$confirmed) $errors[] = ['code'=>'SHARED_SUPERVISION_CONFIRMATION_REQUIRED','severity'=>'error',
            'message'=>'Bevestig de gedeelde begeleiding voor de actuele roosters voordat deze definitief worden gemaakt.'];
        $lessonErrors = array_values(array_filter($errors,static fn (array $e): bool => $e['code']==='MODULE_CAPACITY'));
        return ['visitDate'=>array_key_first($dates),'planIds'=>array_keys($ids),'validLessons'=>$lessonErrors===[],
            'canFinalize'=>$errors===[],'errors'=>$errors,'warnings'=>$warnings,'hasSharedSupervision'=>$shared,
            'requiresSharedConfirmation'=>$shared&&!$confirmed,'exceptionSignature'=>$signature];
    }

    /** A school/program/time/staff/revision change invalidates an earlier acknowledgement. */
    public function signature(array $plans): string
    {
        $normalized = [];
        foreach ($plans as $plan) {
            $occupancy = $plan['occupancy'];
            foreach ($occupancy as &$row) {
                foreach (['staffIds','groupIds'] as $key) { $row[$key]=array_values(array_unique(array_map('intval',$row[$key]??[]))); sort($row[$key],SORT_NUMERIC); }
                ksort($row);
            }
            unset($row);
            usort($occupancy,static fn (array $a,array $b): int => strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
            $normalized[] = ['planId'=>(int)$plan['planId'],'revision'=>(int)$plan['revision'],'visitDate'=>$plan['visitDate'],
                'schoolName'=>$plan['schoolName'],'cookStaffId'=>$plan['cookStaffId']??null,'occupancy'=>$occupancy];
        }
        usort($normalized,static fn (array $a,array $b): int => $a['planId']<=>$b['planId']);
        return hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
    }

    private function canShare(array $sessions, int $staffId): bool
    {
        if (count(array_unique(array_column($sessions,'planId'))) < 2 || count($sessions)!==2) return false;
        $a=$sessions[0]; $b=$sessions[1];
        if (($a['programVersion'] ?? null) === \GeoFort\Booking\FourModuleProgram::VERSION || ($b['programVersion'] ?? null) === \GeoFort\Booking\FourModuleProgram::VERSION) return false;
        if ($a['planId']===$b['planId'] || $a['moduleKey']!==$b['moduleKey'] || $a['startTime']!==$b['startTime'] || $a['endTime']!==$b['endTime']) return false;
        // A cook's overlapping role or cooking/guide combination is never this guide exception.
        if (!in_array($staffId,$a['guideStaffIds'],true)||!in_array($staffId,$b['guideStaffIds'],true)) return false;
        $groups=$a['groupCount']+$b['groupCount'];
        return $groups <= $this->config->maxParallel($a['moduleKey']) && $groups <= $this->config->maxGroupsPerSession($a['moduleKey'])
            && $this->config->requiredGeoFortGuidesForSession($a['moduleKey'],$groups)<=1;
    }

    private function touchesFocus(array $rows,array $focus): bool { foreach($rows as $row)if(isset($focus[$row['planId']]))return true; return false; }
    private function schools(array $rows,array $names): array { $result=[];foreach($rows as $row)$result[$row['planId']]=['planId'=>$row['planId'],'schoolName'=>$names[$row['planId']]];return array_values($result); }
    private function time(mixed $value): bool { return is_string($value)&&preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value)===1; }
}
