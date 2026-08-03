<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingStatusHistorySqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function insert(int $bookingId, string $previousStatus, string $newStatus, int $adminUserId, string $mailMode, bool $mailSent): int
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO booking_status_history (
                    booking_id, previous_status, new_status,
                    mail_mode, mail_sent, admin_user_id, created_at
                ) VALUES (
                    :bookingId, :previousStatus, :newStatus,
                    :mailMode, :mailSent, :adminUserId, NOW()
                )
                SQL);
            $statement->bindValue(':bookingId', $bookingId, PDO::PARAM_INT);
            $statement->bindValue(':previousStatus', $previousStatus);
            $statement->bindValue(':newStatus', $newStatus);
            $statement->bindValue(':mailMode', $mailMode);
            $statement->bindValue(':mailSent', $mailSent, PDO::PARAM_BOOL);
            $statement->bindValue(':adminUserId', $adminUserId, PDO::PARAM_INT);
            $statement->execute();
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            throw new RuntimeException('Statusgeschiedenis kon niet worden vastgelegd.', 0, $exception);
        }
    }

    public function markMailSent(int $historyId): void
    {
        try {
            $statement=$this->pdo->prepare('UPDATE booking_status_history SET mail_sent = 1 WHERE id = :id AND mail_sent = 0');
            $statement->execute([':id'=>$historyId]);
            if($statement->rowCount()!==1)throw new RuntimeException('Mailstatusrecord ontbreekt of is al verwerkt.');
        } catch(PDOException $exception) {
            throw new RuntimeException('Mailstatus kon niet worden vastgelegd.',0,$exception);
        }
    }
}
