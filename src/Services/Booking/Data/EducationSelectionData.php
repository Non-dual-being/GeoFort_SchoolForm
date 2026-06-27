<?php
declare(strict_types=1);

namespace GeoFort\Services\Booking\Data;

final readonly class EducationSelectionData
{
    /**
     * @param string[] $selectedLevels
     * @param array<string, string[]> $selectedGroupsByLevel
     */
    public function __construct(
        public string $sector,
        public array $selectedLevels,
        public array $selectedGroupsByLevel,
    ) {}

    public function toArray(): array
    {
        return [
            'sector' => $this->sector,
            'selectedLevels' => $this->selectedLevels,
            'selectedGroupsByLevel' => $this->selectedGroupsByLevel,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }
}