<?php

declare(strict_types=1);

namespace GeoFort\Booking;

final class SchoolVacationConfig
{
    /**
     * Regio Midden.
     *
     * @return array<int, array{start: string, end: string, reason: string}>
     */
    public static function rangesUntilEndSchoolYear2027(): array
    {
        return [
            [
                'start' => '2026-07-18',
                'end' => '2026-08-30',
                'reason' => 'Zomervakantie 2026 regio Midden',
            ],
            [
                'start' => '2026-10-17',
                'end' => '2026-10-25',
                'reason' => 'Herfstvakantie 2026 regio Midden',
            ],
            [
                'start' => '2026-12-19',
                'end' => '2027-01-03',
                'reason' => 'Kerstvakantie 2026-2027 regio Midden',
            ],
            [
                'start' => '2027-02-20',
                'end' => '2027-02-28',
                'reason' => 'Voorjaarsvakantie 2027 regio Midden',
            ],
            [
                'start' => '2027-04-24',
                'end' => '2027-05-02',
                'reason' => 'Meivakantie 2027',
            ],
            [
                'start' => '2027-07-17',
                'end' => '2027-08-29',
                'reason' => 'Zomervakantie 2027 regio Midden',
            ],
        ];
    }
}