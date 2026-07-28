<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Cjp;

use GeoFort\Booking\Cjp\BookingCjpChangeCode;
use GeoFort\Booking\Cjp\BookingCjpChangeCommand;
use GeoFort\Booking\Cjp\BookingCjpChangeResult;
use GeoFort\Booking\Cjp\BookingCjpDetails;
use GeoFort\Services\Sql\BookingChangeHistorySqlRepository;
use GeoFort\Services\Sql\BookingCjpSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class BookingCjpChangeService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private BookingCjpSqlRepository $repository,
        private BookingChangeHistorySqlRepository $history,
        private BookingCjpValidator $validator,
    ) {}

    public function change(BookingCjpChangeCommand $command): BookingCjpChangeResult
    {
        $previous = null;
        try {
            if (!$this->pdo->beginTransaction()) throw new RuntimeException('Transactie kon niet worden gestart.');
            $booking = $this->bookings->findByIdForUpdate($command->bookingId);
            if ($booking === null) return $this->rollback($command, BookingCjpChangeCode::BookingNotFound);
            $previous = BookingCjpDetails::fromStoredBooking($booking);
            if ($previous->toArray() !== $command->expected->toArray()) return $this->rollback($command, BookingCjpChangeCode::Conflict, $previous);
            $validated = $this->validator->validate($command->proposed);
            if ($validated['details'] === null) return $this->rollback($command, BookingCjpChangeCode::InvalidDetails, $previous, $validated['issues']);
            $current = $validated['details'];
            $changed = $this->changedFields($previous, $current);
            if ($changed === []) return $this->rollback($command, BookingCjpChangeCode::NoChange, $previous, [], $current);
            if (!$this->repository->guardedUpdate($booking->id, $command->expected, $current)) return $this->rollback($command, BookingCjpChangeCode::Conflict, $previous);
            $historyId = $this->history->insertCjpChange($booking->id, $changed, $command->actingAdminId);
            if (!$this->pdo->commit()) throw new RuntimeException('Transactie kon niet worden vastgelegd.');
            return new BookingCjpChangeResult(BookingCjpChangeCode::Success, true, $booking->id, $previous, $current, [], $changed, $historyId);
        } catch (Throwable $exception) {
            error_log(sprintf('CJP change failure: exception=%s booking=%d', $exception::class, $command->bookingId));
            $this->rollbackActive();
            return new BookingCjpChangeResult(BookingCjpChangeCode::DatabaseError, false, $command->bookingId, $previous, $previous);
        }
    }

    /** @return array<string,array{before:?string,after:?string}> */
    private function changedFields(BookingCjpDetails $before, BookingCjpDetails $after): array
    {
        $changed = [];
        foreach (['cjpPasGebruik'=>'useCjp','cjpContactpersoonNaam'=>'contactName','cjpPasnummer'=>'cardNumber'] as $column=>$property) {
            if ($before->{$property} !== $after->{$property}) $changed[$column] = ['before'=>$before->{$property}, 'after'=>$after->{$property}];
        }
        return $changed;
    }

    private function rollback(BookingCjpChangeCommand $command, BookingCjpChangeCode $code, ?BookingCjpDetails $previous=null, array $issues=[], ?BookingCjpDetails $current=null): BookingCjpChangeResult
    {
        $this->rollbackActive();
        return new BookingCjpChangeResult($code, $code === BookingCjpChangeCode::NoChange, $command->bookingId, $previous, $current ?? $previous, $issues);
    }

    private function rollbackActive(): void
    {
        if ($this->pdo->inTransaction()) try { $this->pdo->rollBack(); } catch (Throwable) {}
    }
}
