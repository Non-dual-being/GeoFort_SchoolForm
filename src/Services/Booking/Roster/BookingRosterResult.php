<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Roster;

final class BookingRosterResult
{
    /**
     * @param array<int, array{key: string, label: string}> $standardModules
     * @param array{key: string, label: string}|null $choiceModule
     */
    public function __construct(
        public readonly bool $available,
        public readonly ?int $groupCount,
        public readonly array $standardModules,
        public readonly ?array $choiceModule,
        public readonly ?string $imageUrl,
        public readonly ?string $pdfUrl,
        public readonly ?string $message,
    ) {}

    /**
     * @return array{
     *   available: bool,
     *   groupCount: int|null,
     *   standardModules: array<int, array{key: string, label: string}>,
     *   choiceModule: array{key: string, label: string}|null,
     *   imageUrl: string|null,
     *   pdfUrl: string|null,
     *   message: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'groupCount' => $this->groupCount,
            'standardModules' => $this->standardModules,
            'choiceModule' => $this->choiceModule,
            'imageUrl' => $this->imageUrl,
            'pdfUrl' => $this->pdfUrl,
            'message' => $this->message,
        ];
    }
}
