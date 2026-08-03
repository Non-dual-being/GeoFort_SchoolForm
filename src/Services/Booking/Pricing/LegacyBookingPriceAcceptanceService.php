<?php
declare(strict_types=1);
namespace GeoFort\Services\Booking\Pricing;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class LegacyBookingPriceAcceptanceService
{
    public function __construct(private PDO $pdo,private StoredBookingSqlRepository $bookings,private BookingPriceSnapshotService $snapshots) {}
    public function accept(int $bookingId,int $adminId,bool $explicitlyAccepted): BookingPriceSnapshot
    {
        if(!$explicitlyAccepted)throw new RuntimeException('LEGACY_PRICE_ACCEPTANCE_REQUIRED');
        try {
            if(!$this->pdo->beginTransaction())throw new RuntimeException('DATABASE_ERROR');
            $booking=$this->bookings->findByIdForUpdate($bookingId)??throw new RuntimeException('BOOKING_NOT_FOUND');
            if($booking->status!==BookingPolicy::STATUS_OPTION)throw new RuntimeException('LEGACY_PRICE_ACCEPTANCE_NOT_ALLOWED');
            if($this->snapshots->latest($bookingId)!==null)throw new RuntimeException('PRICE_SNAPSHOT_CONFLICT');
            $snapshot=$this->snapshots->appendUsingActiveVersion($bookingId,BookingPricingInput::fromStoredBooking($booking),BookingPriceSnapshot::REASON_LEGACY_ACCEPTANCE,$adminId);
            if(!$this->pdo->commit())throw new RuntimeException('DATABASE_ERROR');
            return $snapshot;
        } catch(Throwable $exception) {
            if($this->pdo->inTransaction())try{$this->pdo->rollBack();}catch(Throwable){}
            throw $exception;
        }
    }
}
