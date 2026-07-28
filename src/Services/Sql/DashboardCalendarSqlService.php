<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingPolicy;
use PDO;
use PDOException;
use RuntimeException;

final readonly class DashboardCalendarSqlService
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string, list<array{id: int, status: string, schoolName: string, program: string, studentCount: int|null}>> */
    public function findBookingsByDateForRange(string $startDate, string $endDate): array
    {
        $placeholders = [];
        $params = [':startDate' => $startDate, ':endDate' => $endDate];

        foreach (BookingPolicy::ALLOWED_STATUSES as $index => $status) {
            $placeholder = ':status' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $status;
        }

        $sql = sprintf(
            'SELECT id, status, bezoekdatum, schoolnaam, programma, aantal_leerlingen
             FROM aanvragen
             WHERE bezoekdatum BETWEEN :startDate AND :endDate
               AND status IN (%s)
             ORDER BY bezoekdatum ASC, id ASC',
            implode(', ', $placeholders),
        );

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $result = [];

            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $date = (string) $row['bezoekdatum'];
                $result[$date][] = [
                    'id' => (int) $row['id'],
                    'status' => (string) $row['status'],
                    'schoolName' => (string) $row['schoolnaam'],
                    'program' => (string) $row['programma'],
                    'studentCount' => $row['aantal_leerlingen'] === null ? null : (int) $row['aantal_leerlingen'],
                ];
            }

            return $result;
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][DashboardCalendarSqlService::findBookingsByDateForRange]: ' . $exception->getMessage());
            throw new RuntimeException('Agenda-aanvragen konden niet worden opgehaald.', 0, $exception);
        }
    }
}
