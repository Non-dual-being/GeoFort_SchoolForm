<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\FourModuleProgram;

/** Explicit lesson choices; morning activities never inherit KE's school option. */
final class RosterLessonPreferences
{
    public static function defaults(array $modules): array
    {
        $keys = array_column($modules, 'key');
        // The catalogue order supplies the initial ranking; food always comes first.
        if (in_array('Voedsel-Innovatie', $keys, true)) {
            $keys = ['Voedsel-Innovatie', ...array_values(array_diff($keys, ['Voedsel-Innovatie']))];
        }
        $supervision = [];
        foreach ($modules as $module) {
            if (in_array($module['key'], ['Klimaat-Experience', 'Klimparcours'], true)) {
                $supervision[$module['key']] = ($module['programVersion'] ?? null) === FourModuleProgram::VERSION
                    ? 'geofort' : 'school';
            }
        }
        return [
            'moduleOrder' => $keys,
            'supervision' => $supervision,
        ];
    }

    /** A changed programme drops preferences for modules that no longer exist. */
    public static function forModules(?array $stored, array $modules): array
    {
        $defaults = self::defaults($modules);
        if ($stored === null) return $defaults;
        // Migrate the previous single preference and reconcile changes to the programme.
        $order = $stored['moduleOrder'] ?? (isset($stored['earlyModuleKey']) ? [$stored['earlyModuleKey']] : []);
        $order = array_values(array_unique(array_intersect($order, $defaults['moduleOrder'])));
        $order = [...$order, ...array_values(array_diff($defaults['moduleOrder'], $order))];
        if (in_array('Voedsel-Innovatie', $order, true)) {
            $order = ['Voedsel-Innovatie', ...array_values(array_diff($order, ['Voedsel-Innovatie']))];
        }
        return self::validate([
            'moduleOrder' => $order,
            'supervision' => array_replace($defaults['supervision'], array_intersect_key(
                $stored['supervision'] ?? [], $defaults['supervision'],
            )),
        ], $modules);
    }

    public static function validate(array $input, array $modules): array
    {
        $defaults = self::defaults($modules);
        $order = $input['moduleOrder'] ?? null;
        $supervision = $input['supervision'] ?? null;
        if (
            array_diff(array_keys($input), ['moduleOrder', 'supervision']) !== []
            || !is_array($order) || !array_is_list($order)
            || count($order) !== count($defaults['moduleOrder'])
            || array_filter($order, static fn(mixed $key): bool => !is_string($key)) !== []
            || count(array_unique($order)) !== count($order)
            || array_diff($order, $defaults['moduleOrder']) !== []
            || (in_array('Voedsel-Innovatie', $order, true) && $order[0] !== 'Voedsel-Innovatie')
            || !is_array($supervision)
            || array_diff(array_keys($supervision), array_keys($defaults['supervision'])) !== []
        ) {
            throw new RosterPlanException('INVALID_LESSON_PREFERENCES', 422, 'Geef elke gekozen module één voorkeur. Voedsel Innovatie staat, indien gekozen, altijd op 1.');
        }
        foreach ($supervision as $kind) {
            if (!in_array($kind, ['geofort', 'school'], true)) {
                throw new RosterPlanException('INVALID_LESSON_PREFERENCES', 422, 'Kies GeoFort-begeleiding of schoolbegeleiding.');
            }
        }
        return ['moduleOrder' => $order, 'supervision' => array_replace($defaults['supervision'], $supervision)];
    }

    /** Keep an unassigned GeoFort role explicitly open, including in the four-module programme. */
    public static function apply(array $sessions, array $preferences, RosterPlanningConfig $config): array
    {
        return array_map(static function (array $session) use ($preferences, $config): array {
            $kind = $preferences['supervision'][$session['moduleKey']] ?? null;
            if ($kind === null) return $session;
            return [
                ...$session,
                'schoolSupervisionAllowed' => true,
                'schoolFallback' => $kind === 'school',
                'minimumGeoFortStaff' => $kind === 'school' ? 0 : $config->requiredGeoFortGuidesForSession(
                    $session['moduleKey'], count($session['groupIds']), false, $session['programVersion'] ?? null,
                ),
            ];
        }, $sessions);
    }
}
