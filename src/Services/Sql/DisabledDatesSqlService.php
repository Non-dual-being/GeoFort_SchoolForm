<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use Throwable;

final class DisabledDatesSqlService
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    public function usesConnection(PDO $pdo): bool
    {
        return $this->pdo === $pdo;
    }

    /**
     * @param array<int, array{datum: string, type: string, reden: string|null}> $dates
     */
    public function upsertGeneratedDates(array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $sql = "
            INSERT INTO disabled_dates (
                datum,
                type,
                reden
            ) VALUES (
                :datum,
                :type,
                :reden
            )
            ON DUPLICATE KEY UPDATE
                reden = IF(type = 'manual', reden, VALUES(reden)),
                type = IF(type = 'manual', type, VALUES(type))
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($dates as $date) {
            $stmt->execute([
                ':datum' => $date['datum'],
                ':type' => $date['type'],
                ':reden' => $date['reden'],
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getDisabledDateStrings(
        ?string $from = null,
        ?string $to = null
    ): array {
        $conditions = [];
        $params = [];

        if ($from !== null) {
            $conditions[] = 'datum >= :from';
            $params[':from'] = $from;
        }

        if ($to !== null) {
            $conditions[] = 'datum <= :to';
            $params[':to'] = $to;
        }

        $where = $conditions === []
            ? ''
            : 'WHERE ' . implode(' AND ', $conditions);

        /**
         * and only is generated between values
         */

        $sql = "
            SELECT datum
            FROM disabled_dates
            $where
            ORDER BY datum ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, string> $dates */
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $dates;
    }

    /**
     * @return array<int, array{datum: string, type: string, reden: string|null}>
     */
    public function getDisabledDatesDetailed(
        ?string $from = null,
        ?string $to = null
    ): array {
        $conditions = [];
        $params = [];

        if ($from !== null) {
            $conditions[] = 'datum >= :from';
            $params[':from'] = $from;
        }

        if ($to !== null) {
            $conditions[] = 'datum <= :to';
            $params[':to'] = $to;
        }

        $where = $conditions === []
            ? ''
            : 'WHERE ' . implode(' AND ', $conditions);

        $sql = "
            SELECT
                datum,
                type,
                reden
            FROM disabled_dates
            $where
            ORDER BY datum ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array{datum: string, type: string, reden: string|null}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isDateDisabled(string $date): bool
    {
        $sql = "
            SELECT 1
            FROM disabled_dates
            WHERE datum = :datum
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':datum' => $date,
        ]);

        return $stmt->fetchColumn() !== false;
    }
}
