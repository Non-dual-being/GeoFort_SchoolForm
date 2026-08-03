<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Pricing;
use GeoFort\Services\Sql\BookingPriceSnapshotSqlRepository;
use PDO;
final readonly class BookingPriceSnapshotServiceFactory
{
    public function __construct(private PDO $pdo) {}
    public function create(): BookingPriceSnapshotService
    {
        $checksum=new BookingPricingInputChecksum();
        return new BookingPriceSnapshotService(new BookingPriceSnapshotSqlRepository($this->pdo),new BookingPriceCalculator(),new BookingPriceSnapshotFactory($checksum),$checksum);
    }
}
