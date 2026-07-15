<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

final readonly class DashboardBookingListItem
{
    public function __construct(
        public int $id,
        public string $status,
        public string $visitDate,
        public string $schoolName,
        public string $city,
        public string $sectorKey,
        public string $sectorLabel,
        public string $programKey,
        public string $programLabel,
        public ?string $moduleKey,
        public string $moduleLabel,
        public ?int $studentCount,
        public string $contactPersonName,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'visitDate' => $this->visitDate,
            'schoolName' => $this->schoolName,
            'city' => $this->city,
            'sectorKey' => $this->sectorKey,
            'sectorLabel' => $this->sectorLabel,
            'programKey' => $this->programKey,
            'programLabel' => $this->programLabel,
            'moduleKey' => $this->moduleKey,
            'moduleLabel' => $this->moduleLabel,
            'studentCount' => $this->studentCount,
            'contactPersonName' => $this->contactPersonName,
        ];
    }
}
