<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use InvalidArgumentException;

final readonly class BookingPriceCatalog
{
    public const CURRENCY_EUR = 'EUR';
    public const VAT_BASIS_POINTS = 900;
    public const VAT_MEANING = 'prices_include_vat';
    public const VAT_ROUNDING_HALF_UP_TOTAL_CENTS = 'half_up_total_cents';
    public const CALCULATION_PROFILE_PER_ITEM_V1 = 'per_item_integer_cents_v1';

    /**
     * @param array<string, array<string, int>> $visitPricesCents
     * @param array<string, int> $cateringPricesCents
     * @param array<string, string> $sectorPriceTypes
     */
    public function __construct(
        public string $version,
        public string $currencyCode,
        public int $vatBasisPoints,
        public string $vatMeaning,
        public string $vatRoundingRule,
        public string $calculationProfile,
        public int $studentsPerFreeSupervisor,
        private array $sectorPriceTypes,
        private array $visitPricesCents,
        private array $cateringPricesCents,
    ) {
        if ($version === '' || $currencyCode !== self::CURRENCY_EUR || $vatBasisPoints < 0 || $vatMeaning !== self::VAT_MEANING || $vatRoundingRule !== self::VAT_ROUNDING_HALF_UP_TOTAL_CENTS || $calculationProfile !== self::CALCULATION_PROFILE_PER_ITEM_V1 || $studentsPerFreeSupervisor < 1) {
            throw new InvalidArgumentException('Ongeldige prijscatalogusmetadata.');
        }
        foreach ($sectorPriceTypes as $priceType) if (!is_string($priceType) || $priceType === '') throw new InvalidArgumentException('Ongeldige sector-prijstypekoppeling.');
        foreach ([...array_values($visitPricesCents), $cateringPricesCents] as $prices) {
            foreach ($prices as $price) if (!is_int($price) || $price < 0) throw new InvalidArgumentException('Prijzen moeten niet-negatieve integer cents zijn.');
        }
    }

    public function priceTypeForSector(string $sector): string
    {
        return $this->sectorPriceTypes[$sector] ?? throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
    }

    public function freeSupervisorCount(int $studentCount): int
    {
        return $studentCount <= 0 ? 0 : intdiv($studentCount + $this->studentsPerFreeSupervisor - 1, $this->studentsPerFreeSupervisor);
    }

    public function amountExcludingVatCents(int $includingVatCents): int
    {
        $denominator = 10_000 + $this->vatBasisPoints;
        if ($includingVatCents < 0 || $includingVatCents > intdiv(PHP_INT_MAX - intdiv($denominator, 2), 10_000)) throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
        return intdiv($includingVatCents * 10_000 + intdiv($denominator, 2), $denominator);
    }

    public function visitPriceCents(string $program, string $priceType): int
    {
        return $this->visitPricesCents[$program][$priceType]
            ?? throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
    }

    public function cateringPriceCents(string $key): int
    {
        return $this->cateringPricesCents[$key]
            ?? throw new InvalidArgumentException('PRICE_CALCULATION_INVALID');
    }

    /** @return array<string, array<string, int>> */
    public function visitPricesCents(): array { return $this->visitPricesCents; }
    /** @return array<string, int> */
    public function cateringPricesCents(): array { return $this->cateringPricesCents; }
}
