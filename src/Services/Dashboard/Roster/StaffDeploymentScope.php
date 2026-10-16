<?php
declare(strict_types=1);
namespace GeoFort\Services\Dashboard\Roster;

/** Facets use the same frozen/current entries as the report, never a second roster interpretation. */
final class StaffDeploymentScope
{
    private const FIELDS = ['status'=>'status', 'roster'=>'rosterStatus', 'employment'=>'employment', 'staff'=>'staffId', 'year'=>'year'];

    public static function select(array $entries, array $filters): array
    {
        $scope = array_values(array_filter($entries, fn($e) => self::matches($e, $filters, null, false)));
        $dates = array_values(array_unique(array_column($scope, 'date')));
        sort($dates);
        $bounds = ['min'=>$dates[0] ?? null, 'max'=>$dates ? $dates[count($dates)-1] : null];
        if ($filters['range'] === 'auto') {
            $filters['from'] = $bounds['min'] ?? '';
            $filters['to'] = $bounds['max'] ?? '';
        }
        $options = [];
        foreach (self::FIELDS as $key=>$field) {
            $values = [];
            foreach ($entries as $e) {
                // Choosing a year starts a fresh date range; other facets respect a custom range.
                if (!self::matches($e, $filters, $key, $key !== 'year')) continue;
                $value = $key === 'year' ? substr($e['date'], 0, 4) : (string)$e[$field];
                $values[$value] = ['value'=>$value, 'label'=>$key === 'staff' ? $e['name'] : $value];
            }
            $options[$key] = array_values($values);
            usort($options[$key], fn($a,$b) => $key === 'year' ? strcmp($b['value'],$a['value']) : strnatcasecmp($a['label'],$b['label']));
        }
        $selected = array_values(array_filter($scope, fn($e) => self::matches($e, $filters)));
        // Rejected proposals are useful when explicitly requested, but are not active deployment.
        if ($filters['status'] !== 'Afgewezen') $selected = array_values(array_filter($selected, fn($e) => $e['status'] !== 'Afgewezen'));
        return ['filters'=>$filters, 'options'=>$options, 'dateBounds'=>$bounds, 'entries'=>$selected];
    }

    private static function matches(array $entry, array $filters, ?string $except=null, bool $dates=true): bool
    {
        foreach (self::FIELDS as $key=>$field) {
            $value = $key === 'year' ? substr($entry['date'],0,4) : (string)$entry[$field];
            if ($key !== $except && $filters[$key] !== 'all' && $filters[$key] !== $value) return false;
        }
        return !$dates || $filters['range'] === 'auto' || ($entry['date'] >= $filters['from'] && $entry['date'] <= $filters['to']);
    }
}
