<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingPolicy;
use PDO;
use PDOException;
use RuntimeException;

final class BookingCalendarSqlService
{
    public function __construct(
        private readonly PDO $pdo,
    ) {}

    public function getBookingStatsForDate(string $dateYmd, ?int $excludeBookingId = null): array
    {
        try {
            $statusPlaceholders = $this->createNamedPlaceholders(
                'status',
                BookingPolicy::CAPACITY_COUNTING_STATUSES,
            );

            $exclusionSql = $excludeBookingId === null ? '' : ' AND id <> :excludeBookingId';
            $sql = "
                SELECT
                    COUNT(*) AS booked_schools,
                    COALESCE(SUM(aantal_leerlingen), 0) AS booked_students
                FROM aanvragen
                WHERE bezoekdatum = :bezoekdatum
                  AND status IN ({$statusPlaceholders['sql']})
                  {$exclusionSql}
            ";

            $stmt = $this->pdo->prepare($sql);

            $params = [
                ':bezoekdatum' => $dateYmd,
                ...$statusPlaceholders['params'],
            ];
            if ($excludeBookingId !== null) $params[':excludeBookingId'] = $excludeBookingId;
            $stmt->execute($params);

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
        ?int $excludeBookingId = null,
    ): array {
        try {
            $statusPlaceholders = $this->createNamedPlaceholders(
                'status',
                BookingPolicy::CAPACITY_COUNTING_STATUSES,
            );

            $exclusionSql = $excludeBookingId === null ? '' : ' AND id <> :excludeBookingId';
            $sql = "
                SELECT
                    bezoekdatum,
                    programma,
                    COUNT(*) AS booked_schools,
                    COALESCE(SUM(aantal_leerlingen), 0) AS booked_students
                FROM aanvragen
                WHERE bezoekdatum BETWEEN :minDate AND :maxDate
                  AND status IN ({$statusPlaceholders['sql']})
                  {$exclusionSql}
                GROUP BY bezoekdatum, programma
                ORDER BY bezoekdatum ASC, programma ASC
            ";

            $stmt = $this->pdo->prepare($sql);

            $params = [
                ':minDate' => $minDateYmd,
                ':maxDate' => $maxDateYmd,
                ...$statusPlaceholders['params'],
            ];
            if ($excludeBookingId !== null) $params[':excludeBookingId'] = $excludeBookingId;
            $stmt->execute($params);

            $statsByDate = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $date = (string) $row['bezoekdatum'];

                $program = (string) ($row['programma'] ?? '');
                $statsByDate[$date] ??= ['bookedSchools' => 0, 'bookedStudents' => 0, 'programStudents' => []];
                $statsByDate[$date]['bookedSchools'] += (int) ($row['booked_schools'] ?? 0);
                $statsByDate[$date]['bookedStudents'] += (int) ($row['booked_students'] ?? 0);
                $statsByDate[$date]['programStudents'][$program] = (int) ($row['booked_students'] ?? 0);
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
