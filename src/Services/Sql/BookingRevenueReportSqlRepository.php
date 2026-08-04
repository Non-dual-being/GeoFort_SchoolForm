<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Services\Dashboard\Booking\Revenue\BookingRevenueCriteria;
use PDO;
use PDOException;
use RuntimeException;

final readonly class BookingRevenueReportSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function findRows(BookingRevenueCriteria $criteria): array
    {
        $sql = <<<'SQL'
            SELECT a.id, a.bezoekdatum, a.schoolnaam, a.onderwijs_sector, a.aantal_leerlingen, a.status,
                   snapshot.sequence_number, snapshot.calculation_state,
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
            ORDER BY a.bezoekdatum ASC, a.id ASC
            SQL;
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([':startDate' => $criteria->startDate, ':endDate' => $criteria->endDate]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingRevenueReportSqlRepository::findRows]: ' . $exception->getMessage());
            throw new RuntimeException('Omzetrapportage kon niet worden opgehaald.', 0, $exception);
        }
    }
}
