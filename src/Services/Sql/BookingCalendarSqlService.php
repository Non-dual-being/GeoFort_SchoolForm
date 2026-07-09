<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingPolicy;
use PDO;
use PDOException;
use RuntimeException;

final class BookingCalendarSqlService
{
    private const CAPACITY_STATUSES = [
        BookingPolicy::STATUS_CONFIRMED,
    ];

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function getBookingStatsForDate(string $dateYmd): array
    {
        try {
            $statusPlaceholders = $this->createNamedPlaceholders(
                'status',
                self::CAPACITY_STATUSES,
            );

            $sql = "
                SELECT
                    COUNT(*) AS booked_schools,
                    COALESCE(SUM(aantal_leerlingen), 0) AS booked_students
                FROM aanvragen
                WHERE bezoekdatum = :bezoekdatum
                  AND status IN ({$statusPlaceholders['sql']})
            ";

            $stmt = $this->pdo->prepare($sql);

            $stmt->execute([
                ':bezoekdatum' => $dateYmd,
                ...$statusPlaceholders['params'],
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'bookedSchools' => (int) ($row['booked_schools'] ?? 0),
                'bookedStudents' => (int) ($row['booked_students'] ?? 0),
            ];
        } catch (PDOException $e) {
            error_log('[SQL ERROR][getBookingStatsForDate]: ' . $e->getMessage());

            throw new RuntimeException(
                'Boekingscapaciteit kon niet worden opgehaald.',
                0,
                $e,
            );
        }
    }

    public function getBookingStatsByDateForRange(
        string $minDateYmd,
        string $maxDateYmd,
    ): array {
        try {
            $statusPlaceholders = $this->createNamedPlaceholders(
                'status',
                self::CAPACITY_STATUSES,
            );

            $sql = "
                SELECT
                    bezoekdatum,
                    COUNT(*) AS booked_schools,
                    COALESCE(SUM(aantal_leerlingen), 0) AS booked_students
                FROM aanvragen
                WHERE bezoekdatum BETWEEN :minDate AND :maxDate
                  AND status IN ({$statusPlaceholders['sql']})
                GROUP BY bezoekdatum
                ORDER BY bezoekdatum ASC
            ";

            $stmt = $this->pdo->prepare($sql);

            $stmt->execute([
                ':minDate' => $minDateYmd,
                ':maxDate' => $maxDateYmd,
                ...$statusPlaceholders['params'],
            ]);

            $statsByDate = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $date = (string) $row['bezoekdatum'];

                $statsByDate[$date] = [
                    'bookedSchools' => (int) ($row['booked_schools'] ?? 0),
                    'bookedStudents' => (int) ($row['booked_students'] ?? 0),
                ];
            }

            return $statsByDate;
        } catch (PDOException $e) {
            error_log('[SQL ERROR][getBookingStatsByDateForRange]: ' . $e->getMessage());

            throw new RuntimeException(
                'Boekingscapaciteit voor agenda kon niet worden opgehaald.',
                0,
                $e,
            );
        }
    }

    private function createNamedPlaceholders(
        string $prefix,
        array $values,
    ): array {
        if ($values === []) {
            throw new RuntimeException('Geen actieve boekingsstatussen ingesteld.');
        }

        $sqlParts = [];
        $params = [];

        foreach (array_values($values) as $index => $value) {
            $placeholder = ':' . $prefix . $index;

            $sqlParts[] = $placeholder;
            $params[$placeholder] = $value;
        }

        return [
            'sql' => implode(', ', $sqlParts),
            'params' => $params,
        ];
    }
}
