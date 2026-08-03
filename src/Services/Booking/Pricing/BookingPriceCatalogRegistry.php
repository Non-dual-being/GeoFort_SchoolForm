<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Pricing;

use InvalidArgumentException;

final class BookingPriceCatalogRegistry
{
    /** Tarieven zijn op 2 juni 2026 in de centrale configuratie geïntroduceerd. */
    /** Neutrale schema-id; dit beweert geen historische ingangsdatum van de tarieven. */
    public const INITIAL_2026_VERSION = 'catalog-v1';
    public const ACTIVE_VERSION = self::INITIAL_2026_VERSION;

    /** @var array<string, BookingPriceCatalog> */
    private array $catalogs;

    /** @param list<BookingPriceCatalog>|null $catalogs */
    public function __construct(?array $catalogs = null, private readonly string $activeVersion = self::ACTIVE_VERSION)
    {
        $catalogs ??= [new BookingPriceCatalog(
            self::INITIAL_2026_VERSION,
            BookingPriceCatalog::CURRENCY_EUR,
            BookingPriceCatalog::VAT_BASIS_POINTS,
            BookingPriceCatalog::VAT_MEANING,
            BookingPriceCatalog::VAT_ROUNDING_HALF_UP_TOTAL_CENTS,
            BookingPriceCatalog::CALCULATION_PROFILE_PER_ITEM_V1,
            8,
            [
                'primairOnderwijs' => 'basis',
                'speciaalOnderwijs' => 'basis',
                'voortgezetOnderbouw' => 'voortgezet',
                'voortgezetBovenbouw' => 'voortgezet',
            ],
            ['ochtend' => ['basis' => 995], 'dag' => ['basis' => 1800, 'voortgezet' => 2200]],
            ['remise_break' => 260, 'kazerne_break' => 260, 'fortgracht_break' => 260, 'glas_limonade' => 100, 'waterijsje' => 100, 'remise_lunch' => 360, 'eigen_picknick' => 0],
        )];
        $this->catalogs = [];
        foreach ($catalogs as $catalog) {
            if (isset($this->catalogs[$catalog->version])) throw new InvalidArgumentException('Prijsversies zijn immutable en uniek.');
            $this->catalogs[$catalog->version] = $catalog;
        }
        if (!isset($this->catalogs[$activeVersion])) throw new InvalidArgumentException('PRICE_VERSION_UNKNOWN');
    }

    public function active(): BookingPriceCatalog { return $this->catalogs[$this->activeVersion]; }
    public function get(string $version): BookingPriceCatalog
    {
        return $this->catalogs[$version] ?? throw new InvalidArgumentException('PRICE_VERSION_UNKNOWN');
    }
}
