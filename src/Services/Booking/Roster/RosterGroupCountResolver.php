<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Roster;

final class RosterGroupCountResolver
{
    public function resolve(
        string $schoolSector,
        string $program,
        int $studentCount,
    ): ?int {
        if ($schoolSector === 'primairOnderwijs' && $program === 'ochtend') {
            return $this->resolveFromRanges($studentCount, [
                [40, 40, 2],
                [41, 50, 3],
                [51, 65, 4],
                [66, 80, 5],
            ]);
        }

        if ($program === 'dag') {
            return $this->resolveFromRanges($studentCount, [
                [40, 50, 3],
                [51, 65, 4],
                [66, 80, 5],
                [81, 100, 6],
                [101, 120, 7],
                [121, 130, 8],
                [131, 150, 9],
                [151, 160, 10],
            ]);
        }

        return null;
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: int}> $ranges
     */
    private function resolveFromRanges(int $studentCount, array $ranges): ?int
    {
        foreach ($ranges as [$min, $max, $groupCount]) {
            if ($studentCount >= $min && $studentCount <= $max) {
                return $groupCount;
            }
        }

        return null;
    }
}
