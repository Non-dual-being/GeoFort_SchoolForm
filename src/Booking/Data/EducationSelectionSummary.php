<?php

declare(strict_types=1);

namespace GeoFort\Booking\Data;

final readonly class EducationSelectionSummary
{
    /**
     * @param string[] $levelLabels
     * @param array<string, string[]> $groupsByLevelLabel
     */
    public function __construct(
        public array $levelLabels,
        public array $groupsByLevelLabel,
    ) {}

    public function levelSummary(): string
    {
        return implode(', ', $this->levelLabels);
    }

    public function groupSummary(): string
    {
        $parts = [];

        foreach ($this->groupsByLevelLabel as $levelLabel => $groupLabels) {
            $parts[] = $levelLabel . ': ' . implode(', ', $groupLabels);
        }

        return implode(' · ', $parts);
    }
}