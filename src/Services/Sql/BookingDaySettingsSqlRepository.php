<?php
declare(strict_types=1);
namespace GeoFort\Services\Sql;

use GeoFort\Booking\Capacity\BookingDaySettings;
use PDO;
use RuntimeException;

final readonly class BookingDaySettingsSqlRepository
{
    public function __construct(private PDO $pdo) {}

    public function lockDate(string $visitDate): BookingDaySettings
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Datumlock vereist een actieve database-transactie.');
        $insert = $this->pdo->prepare('INSERT IGNORE INTO booking_day_settings (visit_date, created_at, updated_at) VALUES (:date, NOW(), NOW())');
        $insert->execute([':date' => $visitDate]);
        $select = $this->pdo->prepare('SELECT visit_date, max_schools_override, max_students_override FROM booking_day_settings WHERE visit_date = :date FOR UPDATE');
        $select->execute([':date' => $visitDate]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Datumrij kon niet worden vergrendeld.');
        return new BookingDaySettings((string) $row['visit_date'], $row['max_schools_override'] === null ? null : (int) $row['max_schools_override'], $row['max_students_override'] === null ? null : (int) $row['max_students_override']);
    }
}
