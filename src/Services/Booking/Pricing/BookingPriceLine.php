<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

final readonly class BookingPriceLine
{
    public function __construct(
        public string $key,
        public string $label,
        public int $quantity,
        public float $unitPriceInclVat,
        public float $totalInclVat,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'unitPriceInclVat' => $this->unitPriceInclVat,
            'totalInclVat' => $this->totalInclVat,
        ];
    }
}
