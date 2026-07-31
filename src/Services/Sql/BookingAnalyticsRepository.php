<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Dashboard\Booking\Analytics\BookingAnalyticsCriteria;
use PDO;

final readonly class BookingAnalyticsRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function bookings(BookingAnalyticsCriteria $criteria): array
    {
        if (!$criteria->hasOverlap()) return [];
        $statement = $this->pdo->prepare(
            'SELECT id, status, schoolnaam, postcode, land, bezoekdatum, onderwijs_sector, programma, '
            . 'keuzemodule_key, aantal_leerlingen, remise_break, kazerne_break, fortgracht_break, '
            . 'glas_limonade, waterijsje, remise_lunch, eigen_picknick FROM aanvragen '
            . 'WHERE bezoekdatum >= :startDate AND bezoekdatum <= :endDate '
            . 'ORDER BY bezoekdatum ASC, id ASC',
        );
        $statement->execute($this->dates($criteria));
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'school' => trim((string) $row['schoolnaam']),
            'postal_code' => trim((string) ($row['postcode'] ?? '')),
            'country' => trim((string) ($row['land'] ?? '')),
            'visit_date' => (string) $row['bezoekdatum'],
            'sector' => (string) $row['onderwijs_sector'],
            'program' => (string) $row['programma'],
            'module' => is_string($row['keuzemodule_key']) && trim($row['keuzemodule_key']) !== ''
                ? trim($row['keuzemodule_key']) : null,
            'students' => $row['aantal_leerlingen'] === null ? null : (int) $row['aantal_leerlingen'],
            'remise_break' => (int) $row['remise_break'],
            'kazerne_break' => (int) $row['kazerne_break'],
            'fortgracht_break' => (int) $row['fortgracht_break'],
            'lemonade' => (int) $row['glas_limonade'],
            'water_ice' => (int) $row['waterijsje'],
            'remise_lunch' => (int) $row['remise_lunch'],
            'own_picnic' => (bool) $row['eigen_picknick'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{booking_id:int,sector:string,level_count:int,group_count:int}> */
    public function composition(BookingAnalyticsCriteria $criteria): array
    {
        if (!$criteria->hasOverlap()) return [];
        $statement = $this->pdo->prepare(
            'SELECT a.id AS booking_id, a.onderwijs_sector AS sector, '
            . 'COUNT(DISTINCT s.level_key) AS level_count, COUNT(*) AS group_count '
            . 'FROM aanvragen a INNER JOIN ('
            . 'SELECT DISTINCT aanvraag_id, level_key, group_key FROM aanvraag_onderwijs_selecties'
            . ') s ON s.aanvraag_id = a.id '
            . 'WHERE a.bezoekdatum >= :startDate AND a.bezoekdatum <= :endDate '
            . 'GROUP BY a.id, a.onderwijs_sector ORDER BY a.id',
        );
        $statement->execute($this->dates($criteria));
        return array_map(static fn (array $row): array => [
            'booking_id' => (int) $row['booking_id'],
            'sector' => (string) $row['sector'],
            'level_count' => (int) $row['level_count'],
            'group_count' => (int) $row['group_count'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{startDate:string,endDate:string} */
    private function dates(BookingAnalyticsCriteria $criteria): array
    {
        return ['startDate' => (string) $criteria->effectiveStartDate, 'endDate' => (string) $criteria->effectiveEndDate];
    }
}
