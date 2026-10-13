<?php

declare(strict_types=1);

namespace GeoFort\Booking;

use InvalidArgumentException;

/** Frontend payloads derived from backend policy, without changing legacy configuration. */
final class BookingProgramVersionConfig
{
    public static function forFrontend(?string $version = null): array
    {
        $config = BookingProgramConfig::forFrontend();
        if ($version === null) return $config;
        if ($version !== FourModuleProgram::VERSION) {
            throw new InvalidArgumentException('Onbekende programmaversie.');
        }

        $config['programVersion'] = $version;
        $config['availableFrom'] = FourModuleProgram::START_DATE;
        $config['groupTarget'] = FourModuleProgram::GROUP_TARGET;
        $config['chargeExtraSupervisors'] = false;
        $config['timetable'] = FourModuleProgram::timetable();
        $config['programs']['dag']['label'] = 'Dagprogramma · vier modules';
        $config['programs']['dag']['eindTijd'] = '14:00';
        $config['programs']['dag']['description'] = [
            'Van maandag t/m vrijdag buiten de schoolvakanties.',
            'Vier lesmodules van 45 minuten, van 10:00 tot 14:00 uur.',
            'Eén keuzemodule voor de hele school.',
        ];
        foreach (array_keys(BookingProgramConfig::SCHOOL_TYPES_BY_KEY) as $sector) {
            $config['modules'][$sector]['dag'] = [
                'standaard' => FourModuleProgram::standardModules($sector),
                'keuze' => FourModuleProgram::choiceModules($sector, ['groep7', 'groep8']),
            ];
        }
        foreach (['Earth-Watch', 'Stop-de-Klimaat-Klok'] as $module) {
            $config['moduleFilters'][$module]['primairOnderwijs'] = [
                'regulier' => ['groep5', 'groep6'],
                'speciaal' => ['groep5', 'groep6'],
            ];
        }
        $config['practicalInfo']['included'][0] = 'Schoolbegeleiders betalen geen entree bij het viermodulesprogramma.';

        return $config;
    }
}
