<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Catering;

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Booking\Validation\BookingValidationCoordinator;
use GeoFort\Services\Sql\BookingCateringSqlRepository;
use GeoFort\Services\Sql\BookingChangeHistorySqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshotServiceFactory;

final readonly class BookingCateringChangeServiceFactory
{
    public function __construct(private PDO $pdo) {}
    public function create(): BookingCateringChangeService
    {
        return new BookingCateringChangeService(
            $this->pdo,
            new StoredBookingSqlRepository($this->pdo, new StoredBookingAssembler()),
            new BookingCateringSqlRepository($this->pdo),
            new BookingChangeHistorySqlRepository($this->pdo),
            new BookingValidationCoordinator(),
            (new BookingPriceSnapshotServiceFactory($this->pdo))->create(),
        );
    }
}
