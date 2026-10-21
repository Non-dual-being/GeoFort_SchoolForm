<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** Reconstruct only an exact fingerprint match; never infer a programme from an incomplete lesson grid. */
final class RosterBookingSourceHistory
{
    public static function resolve(array $current, string $fingerprint, array $history): ?array
    {
        $candidate = $current;
        $fields = ['bezoekdatum'=>'visitDate', 'onderwijs_sector'=>'schoolSector', 'programma'=>'program',
            'program_version'=>'programVersion', 'keuzemodule_key'=>'choiceModuleKey', 'aantal_leerlingen'=>'studentCount',
            'aantal_begeleiders'=>'supervisorCount', 'education_selection'=>'educationSelection'];
        if (hash_equals($fingerprint, RosterSourceFingerprint::fromContext($candidate))) return $candidate;
        foreach ($history as $changes) {
            foreach ($changes as $field => $change) {
                if (!isset($fields[$field])) continue;
                $key = $fields[$field];
                if (!is_array($change) || !array_key_exists('before', $change) || !array_key_exists('after', $change)
                    || ($candidate[$key] ?? null) !== $change['after']) return null;
                $candidate[$key] = $change['before'];
            }
            if (hash_equals($fingerprint, RosterSourceFingerprint::fromContext($candidate))) return $candidate;
        }
        return null;
    }
}
