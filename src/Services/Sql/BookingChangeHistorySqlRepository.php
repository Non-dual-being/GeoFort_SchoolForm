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

    /** @param array<string,array{before:int,after:int}> $changedFields */
    public function insertAttendanceChange(int $bookingId, array $changedFields, int $adminId): int
    {
        try {
            $json = json_encode($changedFields, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $statement = $this->pdo->prepare("INSERT INTO booking_change_history (booking_id,change_type,changed_fields_json,changed_by_admin_id,created_at) VALUES (:bookingId,'attendance_changed',:fields,:adminId,NOW())");
            $statement->execute([':bookingId'=>$bookingId, ':fields'=>$json, ':adminId'=>$adminId]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException|JsonException $exception) {
            throw new RuntimeException('Wijzigingsaudit kon niet worden vastgelegd.', 0, $exception);
        }
    }
}
