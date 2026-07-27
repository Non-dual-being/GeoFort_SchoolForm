<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingChangeHistorySqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string,array{before:int|bool,after:int|bool}> $changedFields */
    public function insertAttendanceChange(int $bookingId, array $changedFields, int $adminId): int
    {
        return $this->insertChange($bookingId, 'attendance_changed', $changedFields, $adminId);
    }

    /** @param array<string,array{before:int|bool,after:int|bool}> $changedFields */
    public function insertCateringChange(int $bookingId, array $changedFields, int $adminId): int
    {
        return $this->insertChange($bookingId, 'catering_changed', $changedFields, $adminId);
    }

    /** @param array<string,array{before:string,after:string}> $changedFields */
    public function insertVisitDateChange(int $bookingId, array $changedFields, int $adminId): int
    {
        return $this->insertChange($bookingId, 'visit_date_changed', $changedFields, $adminId);
    }

    /** @param array<string,array{before:string,after:string}> $changedFields */
    public function insertProgramChange(int $bookingId, array $changedFields, int $adminId): int
    {
        return $this->insertChange($bookingId, 'program_changed', $changedFields, $adminId);
    }

    /** @param array<string,array{before:int|bool|string,after:int|bool|string}> $changedFields */
    private function insertChange(int $bookingId, string $changeType, array $changedFields, int $adminId): int
    {
        try {
            $json = json_encode($changedFields, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $statement = $this->pdo->prepare('INSERT INTO booking_change_history (booking_id,change_type,changed_fields_json,changed_by_admin_id,created_at) VALUES (:bookingId,:changeType,:fields,:adminId,NOW())');
            $statement->execute([':bookingId'=>$bookingId, ':changeType'=>$changeType, ':fields'=>$json, ':adminId'=>$adminId]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException|JsonException $exception) {
            throw new RuntimeException('Wijzigingsaudit kon niet worden vastgelegd.', 0, $exception);
        }
    }
}
