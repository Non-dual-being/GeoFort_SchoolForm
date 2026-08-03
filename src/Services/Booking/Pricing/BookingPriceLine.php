<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

final readonly class BookingPriceLine
{
    public function __construct(
        public string $key,
        public string $label,
        public int $quantity,
        public int $unitPriceInclVatCents,
        public int $totalInclVatCents,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'quantity' => $this->quantity,
            'unitPriceInclVatCents' => $this->unitPriceInclVatCents,
            'totalInclVatCents' => $this->totalInclVatCents,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach(['key','label','quantity','unitPriceInclVatCents','totalInclVatCents'] as $key)if(!array_key_exists($key,$data))throw new \InvalidArgumentException('Ongeldige opgeslagen prijsregel.');
        if(!is_string($data['key'])||!is_string($data['label'])||!is_int($data['quantity'])||!is_int($data['unitPriceInclVatCents'])||!is_int($data['totalInclVatCents']))throw new \InvalidArgumentException('Ongeldige opgeslagen prijsregel.');
        return new self($data['key'],$data['label'],$data['quantity'],$data['unitPriceInclVatCents'],$data['totalInclVatCents']);
    }
}
