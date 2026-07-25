<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Catering;

use GeoFort\Booking\Validation\BookingValidationCoordinator;
use GeoFort\Booking\Validation\BookingValidationProfile;
use GeoFort\Services\Sql\BookingCateringSqlRepository;
use GeoFort\Services\Sql\BookingChangeHistorySqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingCateringChangeService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private BookingCateringSqlRepository $catering,
        private BookingChangeHistorySqlRepository $history,
        private BookingValidationCoordinator $validator,
    ) {}

    public function change(BookingCateringChangeCommand $command): BookingCateringChangeResult
    {
        $previous = null;
        try {
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transactie kon niet worden gestart.');
            $booking = $this->bookings->findByIdForUpdate($command->bookingId);
            if ($booking === null) return $this->rollback($command, BookingCateringChangeCode::BookingNotFound);
            $previous = BookingCateringValues::fromSelection($booking->foodAndDrinkSelection);
            if ($previous != $command->expected) return $this->rollback($command, BookingCateringChangeCode::CateringConflict, $booking->status, $previous);

            $proposedBooking = $booking->withFoodAndDrink($command->proposed);
            $validation = $this->validator->validate(BookingValidationProfile::ChangeCatering, $proposedBooking);
            if (!$validation->isValid()) {
                return $this->rollback($command, BookingCateringChangeCode::InvalidCateringSelection, $booking->status, $previous, $validation->issues);
            }
            $current = BookingCateringValues::fromSelection($command->proposed);
            $changed = $this->changedFields($previous, $current);
            if ($changed === []) return $this->rollback($command, BookingCateringChangeCode::NoCateringChange, $booking->status, $previous, [], $current);
            if (!$this->catering->guardedUpdate($booking->id, $command->expected, $current)) {
                return $this->rollback($command, BookingCateringChangeCode::CateringConflict, $booking->status, $previous);
            }
            $historyId = $this->history->insertCateringChange($booking->id, $changed, $command->actingAdminId);
            if (!$this->pdo->commit()) throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new BookingCateringChangeResult(BookingCateringChangeCode::Success, true, $booking->id, $booking->status, $previous, $current, [], $changed, $historyId);
        } catch (Throwable $exception) {
            error_log(sprintf('Booking catering change failure: exception=%s booking=%d', $exception::class, $command->bookingId));
            $this->rollbackIfActive();
            return new BookingCateringChangeResult(BookingCateringChangeCode::DatabaseError, false, $command->bookingId, previous: $previous, current: $previous);
        }
    }

    /** @return array<string,array{before:int|bool,after:int|bool}> */
    private function changedFields(BookingCateringValues $before, BookingCateringValues $after): array
    {
        $map = ['remise_break'=>'remiseBreak','kazerne_break'=>'kazerneBreak','fortgracht_break'=>'fortgrachtBreak','waterijsje'=>'waterIce','glas_limonade'=>'lemonade','remise_lunch'=>'remiseLunch','eigen_picknick'=>'ownPicnic'];
        $changed = [];
        foreach ($map as $column => $property) if ($before->{$property} !== $after->{$property}) $changed[$column] = ['before'=>$before->{$property},'after'=>$after->{$property}];
        return $changed;
    }

    private function rollback(BookingCateringChangeCommand $command, BookingCateringChangeCode $code, ?string $status = null, ?BookingCateringValues $previous = null, array $issues = [], ?BookingCateringValues $current = null): BookingCateringChangeResult
    {
        $this->rollbackIfActive();
        return new BookingCateringChangeResult($code, $code === BookingCateringChangeCode::NoCateringChange, $command->bookingId, $status, $previous, $current ?? $previous, $issues);
    }

    private function rollbackIfActive(): void
    {
        if ($this->pdo->inTransaction()) try { $this->pdo->rollBack(); } catch (Throwable) {}
    }
}
