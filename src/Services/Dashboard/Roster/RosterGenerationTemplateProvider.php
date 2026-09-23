<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

final class RosterGenerationTemplateProvider
{
    /** @return list<array{label:string,startTime:string,endTime:string}> */
    public function defaultRounds(string $program): array
    {
        if ($program === 'ochtend') {
            return [
                ['label' => 'Ronde 1', 'startTime' => '10:15', 'endTime' => '10:35'],
                ['label' => 'Ronde 2', 'startTime' => '10:35', 'endTime' => '10:55'],
                ['label' => 'Ronde 3', 'startTime' => '11:10', 'endTime' => '11:30'],
                ['label' => 'Ronde 4', 'startTime' => '11:30', 'endTime' => '11:50'],
                ['label' => 'Ronde 5', 'startTime' => '11:50', 'endTime' => '12:10'],
            ];
        }

        return [
            ['label' => 'Ronde 1', 'startTime' => '10:15', 'endTime' => '11:00'],
            ['label' => 'Ronde 2', 'startTime' => '11:15', 'endTime' => '12:00'],
            ['label' => 'Ronde 3', 'startTime' => '12:00', 'endTime' => '12:45'],
            ['label' => 'Ronde 4', 'startTime' => '13:15', 'endTime' => '14:00'],
            ['label' => 'Ronde 5', 'startTime' => '14:00', 'endTime' => '14:45'],
        ];
    }

    public function note(string $program): string
    {
        return $program === 'ochtend'
            ? 'Ochtendtemplate met expliciete aankomst, pauze en vertrek rondom de lesrondes.'
            : 'Standaard GeoFort-dag: 10:00 aankomst, vijf lesrondes, pauze 11:00-11:15, lunch 12:45-13:15 en vertrek 14:45-15:00.';
    }
}
