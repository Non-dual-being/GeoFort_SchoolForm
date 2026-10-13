<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Roster;

use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use InvalidArgumentException;

/** Public examples only: never a reservation of rooms, staff or a final school plan. */
final class FourModuleExampleRoster
{
    public static function stem(string $sector, string $choice, int $groups): string
    {
        FourModuleProgram::modules($sector, ['groep7', 'groep8'], $choice);
        if ($groups < 1 || $groups > FourModuleProgram::MAX_GROUPS) {
            throw new InvalidArgumentException('Ongeldig aantal voorbeeldgroepen.');
        }
        return 'four-2027-' . ($sector === 'primairOnderwijs' ? 'po' : 'vo')
            . '-' . strtolower($choice) . '-' . $groups;
    }

    /** @return array{modules: list<array<string,mixed>>, rows: list<array<string,mixed>>} */
    public static function schedule(string $sector, string $choice, int $groups): array
    {
        self::stem($sector, $choice, $groups);
        $config = new RosterPlanningConfig();
        $modules = $config->moduleOptions($sector, 'dag', $choice, FourModuleProgram::VERSION);
        $round = 0;
        $rows = [];
        foreach (FourModuleProgram::timetable() as $block) {
            $cells = [];
            for ($group = 0; $group < $groups; $group++) {
                // Two parallel groups at most; every group visits each module exactly once.
                $cells[] = $block['kind'] === 'lesson' ? $modules[($group + $round) % 4] : [
                    'key' => null,
                    'label' => match ($block['kind']) {
                        'arrival' => 'Aankomst & welkom', 'lunch' => 'Lunch',
                        'departure' => 'Afscheid & vertrek',
                    },
                    'color' => '#FFFFFF',
                ];
            }
            $rows[] = $block + ['cells' => $cells];
            if ($block['kind'] === 'lesson') $round++;
        }
        return ['modules' => $modules, 'rows' => $rows];
    }
}
