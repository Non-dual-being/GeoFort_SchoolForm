<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Cjp;

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Sql\BookingChangeHistorySqlRepository;
use GeoFort\Services\Sql\BookingCjpSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;

final readonly class BookingCjpChangeServiceFactory
{
    public function __construct(private PDO $pdo) {}
    public function create(): BookingCjpChangeService
    {
        return new BookingCjpChangeService(
            $this->pdo,
            new StoredBookingSqlRepository($this->pdo, new StoredBookingAssembler()),
            new BookingCjpSqlRepository($this->pdo),
            new BookingChangeHistorySqlRepository($this->pdo),
            new BookingCjpValidator(),
        );
    }
}
