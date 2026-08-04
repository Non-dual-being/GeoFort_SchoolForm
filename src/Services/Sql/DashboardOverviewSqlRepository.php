<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingPolicy;
use PDO;

final readonly class DashboardOverviewSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{total: int, items: list<array<string, mixed>>} */
    public function optionSummary(int $limit): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM aanvragen WHERE status = :status');
        $count->execute([':status' => BookingPolicy::STATUS_OPTION]);
        $statement = $this->pdo->prepare(
            'SELECT id, bezoekdatum, schoolnaam, programma, onderwijs_sector, aantal_leerlingen
             FROM aanvragen WHERE status = :status
             ORDER BY CASE WHEN bezoekdatum IS NULL THEN 1 ELSE 0 END, bezoekdatum ASC, id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':status', BookingPolicy::STATUS_OPTION);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return [
            'total' => (int) $count->fetchColumn(),
            'items' => $statement->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function nextOptions(string $today): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, bezoekdatum, schoolnaam, programma, onderwijs_sector, aantal_leerlingen
             FROM aanvragen
             WHERE status = :status AND bezoekdatum = (
                 SELECT MIN(bezoekdatum) FROM aanvragen WHERE status = :statusNext AND bezoekdatum >= :today
             ) ORDER BY id ASC'
        );
        $statement->execute([
            ':status' => BookingPolicy::STATUS_OPTION,
            ':statusNext' => BookingPolicy::STATUS_OPTION,
            ':today' => $today,
        ]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{status: string, sector: string, bookings: int, students: int}> */
    public function monthStatistics(string $start, string $endExclusive): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, onderwijs_sector AS sector, COUNT(*) AS bookings,
                    COALESCE(SUM(CASE WHEN aantal_leerlingen > 0 THEN aantal_leerlingen ELSE 0 END), 0) AS students
             FROM aanvragen WHERE bezoekdatum >= :start AND bezoekdatum < :end
             GROUP BY status, onderwijs_sector'
        );
        $statement->execute([
            ':start' => $start,
            ':end' => $endExclusive,
        ]);
        return array_map(static fn (array $row): array => [
            'status' => (string) $row['status'],
            'sector' => (string) $row['sector'],
            'bookings' => (int) $row['bookings'],
            'students' => (int) $row['students'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
