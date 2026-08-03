<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingPolicy;
use PDO;

final readonly class DashboardCalendarOverviewSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string, list<array<string, int|string>>> */
    public function aggregateForRange(string $gridStart, string $gridEnd): array
    {
        $sql = <<<'SQL'
            SELECT bezoekdatum, status, programma,
                   COUNT(*) AS booking_count,
                   COALESCE(SUM(CASE WHEN aantal_leerlingen > 0 THEN aantal_leerlingen ELSE 0 END), 0) AS student_count,
                   COALESCE(SUM(CASE WHEN aantal_leerlingen IS NULL THEN 1 ELSE 0 END), 0) AS unknown_student_count,
                   COALESCE(SUM(CASE WHEN aantal_leerlingen < 0 THEN 1 ELSE 0 END), 0) AS invalid_student_count
            FROM aanvragen
            WHERE bezoekdatum BETWEEN :gridStart AND :gridEnd
              AND status IN (:statusOption, :statusConfirmed, :statusRejected)
              AND programma IN (:programDay, :programMorning)
            GROUP BY bezoekdatum, status, programma
            ORDER BY bezoekdatum ASC, status ASC, programma ASC
            SQL;
        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':gridStart' => $gridStart,
            ':gridEnd' => $gridEnd,
            ':statusOption' => BookingPolicy::STATUS_OPTION,
            ':statusConfirmed' => BookingPolicy::STATUS_CONFIRMED,
            ':statusRejected' => BookingPolicy::STATUS_REJECTED,
            ':programDay' => BookingPolicy::PROGRAM_DAY,
            ':programMorning' => BookingPolicy::PROGRAM_MORNING,
        ]);

        $byDate = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $date = (string) $row['bezoekdatum'];
            $byDate[$date][] = [
                'program' => (string) $row['programma'],
                'status' => (string) $row['status'],
                'bookingCount' => (int) $row['booking_count'],
                'studentCount' => (int) $row['student_count'],
                'unknownStudentCount' => (int) $row['unknown_student_count'],
                'invalidStudentCount' => (int) $row['invalid_student_count'],
            ];
        }
        return $byDate;
    }
}
