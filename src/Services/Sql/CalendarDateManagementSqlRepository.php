<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Dashboard\Calendar\CalendarDateManagementPolicy;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class CalendarDateManagementSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string, array{type:string,reason:?string}> */
    public function findDisabledDates(string $startDate, string $endDate, bool $forUpdate = false): array
    {
        if ($forUpdate) $this->assertTransaction();
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            'SELECT datum, type, reden FROM disabled_dates
             WHERE datum BETWEEN :startDate AND :endDate ORDER BY datum' . $lock,
        );
        $statement->execute([':startDate' => $startDate, ':endDate' => $endDate]);
        $result = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $result[(string) $row['datum']] = [
                'type' => (string) $row['type'],
                'reason' => $row['reden'] === null ? null : (string) $row['reden'],
            ];
        }
        return $result;
    }

    /**
     * @return array{
     *   bookingCount:int,
     *   studentCount:int,
     *   bookings:list<array{id:int,date:string,status:string,studentCount:?int}>
     * }
     */
    public function findActiveBookings(string $startDate, string $endDate, bool $forUpdate = false): array
    {
        if ($forUpdate) $this->assertTransaction();
        $params = [':startDate' => $startDate, ':endDate' => $endDate];
        $placeholders = [];
        foreach (BookingPolicy::ACTIVE_STATUSES as $index => $status) {
            $key = ':activeStatus' . $index;
            $params[$key] = $status;
            $placeholders[] = $key;
        }
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(sprintf(
            'SELECT id, bezoekdatum, status, aantal_leerlingen FROM aanvragen
             WHERE bezoekdatum BETWEEN :startDate AND :endDate
               AND status IN (%s)
             ORDER BY bezoekdatum, id%s',
            implode(', ', $placeholders),
            $lock,
        ));
        $statement->execute($params);
        $bookings = [];
        $students = 0;
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $studentCount = $row['aantal_leerlingen'] === null ? null : (int) $row['aantal_leerlingen'];
            $students += $studentCount ?? 0;
            $bookings[] = [
                'id' => (int) $row['id'],
                'date' => (string) $row['bezoekdatum'],
                'status' => (string) $row['status'],
                'studentCount' => $studentCount,
            ];
        }
        return ['bookingCount' => count($bookings), 'studentCount' => $students, 'bookings' => $bookings];
    }

    public function insertManualBlock(string $date, string $reason): void
    {
        $statement = $this->pdo->prepare('INSERT INTO disabled_dates (datum, type, reden) VALUES (:date, :type, :reason)');
        $statement->execute([':date' => $date, ':type' => CalendarDateManagementPolicy::MANUAL_BLOCK_TYPE, ':reason' => $reason]);
    }

    public function releaseManualBlock(string $date): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM disabled_dates WHERE datum = :date AND type = :type');
        $statement->execute([':date' => $date, ':type' => CalendarDateManagementPolicy::MANUAL_BLOCK_TYPE]);
        return $statement->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<array{date:string,before:bool,after:bool,reasonBefore:?string,reasonAfter:?string}> $dates
     */
    public function insertAudit(
        string $action,
        string $scope,
        string $startDate,
        string $endDate,
        ?string $reason,
        array $summary,
        array $dates,
        int $adminId,
    ): int {
        try {
            $json = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $header = $this->pdo->prepare(
                'INSERT INTO calendar_date_change_history
                 (action, scope, start_date, end_date, reason, affected_count, summary_json, changed_by_admin_id)
                 VALUES (:action, :scope, :startDate, :endDate, :reason, :affectedCount, :summary, :adminId)',
            );
            $header->execute([
                ':action' => $action,
                ':scope' => $scope,
                ':startDate' => $startDate,
                ':endDate' => $endDate,
                ':reason' => $reason,
                ':affectedCount' => count($dates),
                ':summary' => $json,
                ':adminId' => $adminId,
            ]);
            $historyId = (int) $this->pdo->lastInsertId();
            $child = $this->pdo->prepare(
                'INSERT INTO calendar_date_change_history_dates
                 (history_id, calendar_date, manually_blocked_before, manually_blocked_after, reason_before, reason_after)
                 VALUES (:historyId, :date, :before, :after, :reasonBefore, :reasonAfter)',
            );
            foreach ($dates as $date) {
                $child->execute([
                    ':historyId' => $historyId,
                    ':date' => $date['date'],
                    ':before' => $date['before'] ? 1 : 0,
                    ':after' => $date['after'] ? 1 : 0,
                    ':reasonBefore' => $date['reasonBefore'],
                    ':reasonAfter' => $date['reasonAfter'],
                ]);
            }
            return $historyId;
        } catch (PDOException | JsonException $exception) {
            throw new RuntimeException('Kalenderaudit kon niet worden vastgelegd.', 0, $exception);
        }
    }

    private function assertTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Kalenderdatumlock vereist een actieve transactie.');
        }
    }
}
