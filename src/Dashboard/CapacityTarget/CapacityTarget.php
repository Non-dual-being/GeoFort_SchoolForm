<?php
declare(strict_types=1);

namespace GeoFort\Dashboard\CapacityTarget;

final readonly class CapacityTarget
{
    public function __construct(
        public int $id,
        public string $effectiveDate,
        public int $studentsPerAvailableDay,
        public float $bookingsPerAvailableDay,
        public int $createdByAdminId,
        public int $updatedByAdminId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'effectiveDate' => $this->effectiveDate,
            'studentsPerAvailableDay' => $this->studentsPerAvailableDay,
            'bookingsPerAvailableDay' => $this->bookingsPerAvailableDay,
            'derivedAverageStudentsPerBooking' => $this->bookingsPerAvailableDay > 0
                ? $this->studentsPerAvailableDay / $this->bookingsPerAvailableDay
                : null,
            'createdByAdminId' => $this->createdByAdminId,
            'updatedByAdminId' => $this->updatedByAdminId,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
