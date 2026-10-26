<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteria;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use GeoFort\Booking\BookingPolicy;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingRevenueReportSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function findAvailableVisitDateRange(): BookingExportDateBounds
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT MIN(bezoekdatum) AS min_date, MAX(bezoekdatum) AS max_date '
                . "FROM aanvragen WHERE status IN (:confirmed, :option) AND bezoekdatum IS NOT NULL AND bezoekdatum >= '1000-01-01'",
            );
            $statement->execute([
                ':confirmed' => BookingPolicy::STATUS_CONFIRMED,
                ':option' => BookingPolicy::STATUS_OPTION,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return new BookingExportDateBounds(
                is_array($row) && is_string($row['min_date'] ?? null) ? $row['min_date'] : null,
                is_array($row) && is_string($row['max_date'] ?? null) ? $row['max_date'] : null,
            );
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingRevenueReportSqlRepository::findAvailableVisitDateRange]: ' . $exception->getMessage());
            throw new RuntimeException('Beschikbare bezoekdatums konden niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return list<array<string, mixed>> */
    public function findRows(BookingRevenueCriteria $criteria, ?int $limit = null, int $offset = 0): array
    {
        $sql = <<<'SQL'
            SELECT a.id, a.bezoekdatum, a.schoolnaam, a.onderwijs_sector, a.programma, a.program_version, a.aantal_leerlingen, a.status,
                   snapshot.sequence_number, snapshot.calculation_state, snapshot.pricing_version, snapshot.calculation_details_json,
                   snapshot.visit_amount_incl_vat_cents, snapshot.catering_amount_incl_vat_cents,
                   snapshot.total_amount_incl_vat_cents, snapshot.total_amount_excl_vat_cents,
                   snapshot.vat_amount_cents
            FROM aanvragen a
            LEFT JOIN (
                SELECT prices.*
                FROM booking_price_snapshots prices
                INNER JOIN (
                    SELECT booking_id, MAX(sequence_number) AS latest_sequence
                    FROM booking_price_snapshots
                    GROUP BY booking_id
                ) latest ON latest.booking_id = prices.booking_id
                        AND latest.latest_sequence = prices.sequence_number
            ) snapshot ON snapshot.booking_id = a.id
            WHERE a.bezoekdatum >= :startDate AND a.bezoekdatum <= :endDate
              AND a.status IN (%s) AND (%s)
            ORDER BY a.bezoekdatum ASC, a.id ASC
            SQL;
        try {
            $statuses = $this->statuses($criteria);
            $placeholders = implode(', ', array_map(static fn (int $index): string => ':status' . $index, array_keys($statuses)));
            $sql = sprintf($sql, $placeholders, $this->variantPredicate());
            if ($limit !== null) $sql .= ' LIMIT :limit OFFSET :offset';
            $statement = $this->pdo->prepare($sql);
            $statement->bindValue(':programVariant', $criteria->programVariant);
            $statement->bindValue(':selectedVariant', $criteria->programVariant);
            $statement->bindValue(':startDate', $criteria->startDate);
            $statement->bindValue(':endDate', $criteria->endDate);
            foreach ($statuses as $index => $status) $statement->bindValue(':status' . $index, $status);
            if ($limit !== null) { $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); }
            $statement->execute();
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingRevenueReportSqlRepository::findRows]: ' . $exception->getMessage());
            throw new RuntimeException('Omzetrapportage kon niet worden opgehaald.', 0, $exception);
        }
    }

    public function countRows(BookingRevenueCriteria $criteria): int
    {
        $statuses = $this->statuses($criteria);
        $placeholders = implode(', ', array_map(static fn (int $index): string => ':status' . $index, array_keys($statuses)));
        $variant = $this->variantPredicate();
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM aanvragen a WHERE bezoekdatum >= :startDate AND bezoekdatum <= :endDate AND status IN ({$placeholders}) AND ({$variant})");
        $statement->bindValue(':programVariant', $criteria->programVariant);
        $statement->bindValue(':selectedVariant', $criteria->programVariant);
        $statement->bindValue(':startDate', $criteria->startDate); $statement->bindValue(':endDate', $criteria->endDate);
        foreach ($statuses as $index => $status) $statement->bindValue(':status' . $index, $status);
        $statement->execute();
        return (int) $statement->fetchColumn();
    }

    private function variantPredicate(): string
    {
        // Fixed CASE and a bound filter; classification mirrors BookingProgramVariant.
        return "CASE WHEN :programVariant = 'all' THEN 1 ELSE "
            . "CASE WHEN a.programma = 'dag' AND a.program_version = 'day-four-2027' THEN 'day-four' "
            . "WHEN COALESCE(a.program_version, '') <> '' THEN 'unknown' "
            . "WHEN a.programma = 'dag' THEN 'day-five' WHEN a.programma = 'ochtend' THEN 'morning' ELSE 'unknown' END = :selectedVariant END";
    }

    /** @return list<string> */
    private function statuses(BookingRevenueCriteria $criteria): array
    {
        return match ($criteria->scope) {
            BookingRevenueCriteria::SCOPE_OPTION => [BookingPolicy::STATUS_OPTION],
            BookingRevenueCriteria::SCOPE_COMBINED => [BookingPolicy::STATUS_CONFIRMED, BookingPolicy::STATUS_OPTION],
            default => [BookingPolicy::STATUS_CONFIRMED],
        };
    }
}
