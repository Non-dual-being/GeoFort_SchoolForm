<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingStatusSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function guardedUpdate(int $bookingId, string $expectedCurrentStatus, string $targetStatus): bool
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE aanvragen
                SET status = :targetStatus
                WHERE id = :id
                  AND status = :expectedCurrentStatus
                SQL);
            $statement->bindValue(':targetStatus', $targetStatus);
            $statement->bindValue(':id', $bookingId, PDO::PARAM_INT);
            $statement->bindValue(':expectedCurrentStatus', $expectedCurrentStatus);
            $statement->execute();

            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            throw new RuntimeException('Aanvraagstatus kon niet worden bijgewerkt.', 0, $exception);
        }
    }
}
