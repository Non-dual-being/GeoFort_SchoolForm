<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Presentation;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Booking\Data\EducationSelectionSummary;

final class EducationSelectionSummaryFactory
{
    public function fromData(
        EducationSelectionData $educationSelection,
    ): EducationSelectionSummary {
        $sector = $educationSelection->sector;

        $levelLabels = [];
        $groupsByLevelLabel = [];

        foreach ($educationSelection->selectedLevels as $levelKey) {
            $levelLabel = $this->getLevelLabel($sector, $levelKey);

            $levelLabels[] = $levelLabel;
            $groupsByLevelLabel[$levelLabel] = [];

            foreach (
                $educationSelection->selectedGroupsByLevel[$levelKey] ?? []
                as $groupKey
            ) {
                $groupsByLevelLabel[$levelLabel][] = $this->getGroupLabel(
                    $sector,
                    $levelKey,
                    $groupKey,
                );
            }
        }

        return new EducationSelectionSummary(
            levelLabels: $levelLabels,
            groupsByLevelLabel: $groupsByLevelLabel,
        );
    }

    /**
     * Gebruik deze later voor dashboard/mail vanuit de database.
     *
     * @param array<int, array{
     *   level_label: string,
     *   level_position: int|string,
     *   group_label: string,
     *   group_position: int|string
     * }> $rows
     */
    public function fromDatabaseRows(array $rows): EducationSelectionSummary
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $levelCompare = ((int) $a['level_position'])
                    <=> ((int) $b['level_position']);

                if ($levelCompare !== 0) {
                    return $levelCompare;
                }

                return ((int) $a['group_position'])
                    <=> ((int) $b['group_position']);
            },
        );

        $levelLabels = [];
        $groupsByLevelLabel = [];

        foreach ($rows as $row) {
            $levelLabel = (string) $row['level_label'];
            $groupLabel = (string) $row['group_label'];

            if (!in_array($levelLabel, $levelLabels, true)) {
                $levelLabels[] = $levelLabel;
            }

            $groupsByLevelLabel[$levelLabel] ??= [];
            $groupsByLevelLabel[$levelLabel][] = $groupLabel;
        }

        return new EducationSelectionSummary(
            levelLabels: $levelLabels,
            groupsByLevelLabel: $groupsByLevelLabel,
        );
    }

    private function getLevelLabel(string $sector, string $levelKey): string
    {
        return BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey]['label']
            ?? $levelKey;
    }

    private function getGroupLabel(
        string $sector,
        string $levelKey,
        string $groupKey,
    ): string {
        return BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey]['groups'][$groupKey]
            ?? $groupKey;
    }
}