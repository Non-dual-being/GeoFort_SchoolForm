<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Dashboard\Booking\DashboardBookingFilters;
use PDO;
use PDOException;
use RuntimeException;

final class DashboardBookingSqlService
{
    public function __construct(private readonly PDO $pdo) {}

    public function countBookings(DashboardBookingFilters $filters): int
    {
        [$where, $params] = $this->buildFilters($filters);
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM aanvragen{$where}");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('[SQL ERROR][DashboardBookingSqlService::countBookings]: ' . $e->getMessage());
            throw new RuntimeException('Aanvragen konden niet worden geteld.', 0, $e);
        }
    }

    /** @return array{min: string|null, max: string|null} */
    public function getGlobalDateRange(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT MIN(bezoekdatum) AS min_date, MAX(bezoekdatum) AS max_date FROM aanvragen'
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return ['min' => null, 'max' => null];
            }

            return [
                'min' => is_string($row['min_date'] ?? null) ? $row['min_date'] : null,
                'max' => is_string($row['max_date'] ?? null) ? $row['max_date'] : null,
            ];
        } catch (PDOException $e) {
            error_log('[SQL ERROR][DashboardBookingSqlService::getGlobalDateRange]: ' . $e->getMessage());
            throw new RuntimeException('Datumbandbreedte kon niet worden opgehaald.', 0, $e);
        }
    }

    /** @return list<array<string, int|string|null>> */
    public function findBookings(DashboardBookingFilters $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = "
            SELECT id, status, bezoekdatum, schoolnaam, plaats, onderwijs_sector,
                   programma, keuzemodule_key, aantal_leerlingen,
                   contactpersoon_voornaam, contactpersoon_achternaam
            FROM aanvragen{$where}
            ORDER BY bezoekdatum ASC, id ASC
            LIMIT :limit OFFSET :offset
        ";
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $placeholder => $value) {
                $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
        } catch (PDOException $e) {
            error_log('[SQL ERROR][DashboardBookingSqlService::findBookings]: ' . $e->getMessage());
            throw new RuntimeException('Aanvragen konden niet worden opgehaald.', 0, $e);
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function buildFilters(DashboardBookingFilters $filters): array
    {
        $conditions = [];
        $params = [];
        if ($filters->search !== null) {
            $term = '%' . $this->escapeLike($filters->search) . '%';
            foreach (['schoolnaam', 'plaats', 'contactpersoon_voornaam', 'contactpersoon_achternaam', 'email'] as $index => $column) {
                $placeholder = ':search' . $index;
                $conditions[] = "{$column} LIKE {$placeholder} ESCAPE '\\\\'";
                $params[$placeholder] = $term;
            }
            $searchConditions = array_splice($conditions, -5);
            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
        }
        foreach ([
            'status' => ['status', $filters->status],
            'sector' => ['onderwijs_sector', $filters->sector],
            'program' => ['programma', $filters->program],
            'module' => ['keuzemodule_key', $filters->module],
        ] as $name => [$column, $value]) {
            if ($value !== null) {
                $conditions[] = "{$column} = :{$name}";
                $params[':' . $name] = $value;
            }
        }
        if ($filters->dateFrom !== null) {
            $conditions[] = 'bezoekdatum >= :dateFrom';
            $params[':dateFrom'] = $filters->dateFrom;
        }
        if ($filters->dateTo !== null) {
            $conditions[] = 'bezoekdatum <= :dateTo';
            $params[':dateTo'] = $filters->dateTo;
        }
        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** @param array<string, mixed> $row @return array<string, int|string|null> */
    private function normalizeRow(array $row): array
    {
        $requiredStrings = ['status', 'bezoekdatum', 'schoolnaam', 'plaats', 'onderwijs_sector', 'programma', 'contactpersoon_voornaam', 'contactpersoon_achternaam'];
        foreach ($requiredStrings as $column) {
            if (!array_key_exists($column, $row) || !is_string($row[$column])) {
                throw new RuntimeException("Aanvragenoverzicht bevat een ongeldige databasekolom: {$column}");
            }
        }
        foreach (['id'] as $column) {
            if (!array_key_exists($column, $row) || !(is_int($row[$column]) || (is_string($row[$column]) && preg_match('/^\d+$/', $row[$column]) === 1))) {
                throw new RuntimeException("Aanvragenoverzicht bevat een ongeldige databasekolom: {$column}");
            }
        }
        if (
            !array_key_exists('aantal_leerlingen', $row)
            || !(
                $row['aantal_leerlingen'] === null
                || is_int($row['aantal_leerlingen'])
                || (is_string($row['aantal_leerlingen']) && preg_match('/^\d+$/', $row['aantal_leerlingen']) === 1)
            )
        ) {
            throw new RuntimeException('Aanvragenoverzicht bevat een ongeldige databasekolom: aantal_leerlingen');
        }
        if (!array_key_exists('keuzemodule_key', $row) || ($row['keuzemodule_key'] !== null && !is_string($row['keuzemodule_key']))) {
            throw new RuntimeException('Aanvragenoverzicht bevat een ongeldige databasekolom: keuzemodule_key');
        }
        return [
            'id' => (int) $row['id'], 'status' => $row['status'], 'bezoekdatum' => $row['bezoekdatum'],
            'schoolnaam' => $row['schoolnaam'], 'plaats' => $row['plaats'], 'onderwijs_sector' => $row['onderwijs_sector'],
            'programma' => $row['programma'], 'keuzemodule_key' => $row['keuzemodule_key'],
            'aantal_leerlingen' => $row['aantal_leerlingen'] === null ? null : (int) $row['aantal_leerlingen'],
            'contactpersoon_voornaam' => $row['contactpersoon_voornaam'],
            'contactpersoon_achternaam' => $row['contactpersoon_achternaam'],
        ];
    }
}
