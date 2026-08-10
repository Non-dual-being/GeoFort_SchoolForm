<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Dashboard\CapacityTarget\CapacityTarget;
use PDO;
use RuntimeException;

final readonly class CapacityTargetSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<CapacityTarget> */
    public function findEffectiveThrough(string $endDate): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, effective_date, students_per_available_day, bookings_per_available_day,
                    created_by_admin_id, updated_by_admin_id, created_at, updated_at
             FROM capacity_targets WHERE effective_date <= :endDate ORDER BY effective_date ASC, id ASC',
        );
        $statement->execute([':endDate' => $endDate]);
        return array_map($this->map(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findEffectiveOn(string $date): ?CapacityTarget
    {
        $statement = $this->pdo->prepare(
            'SELECT id, effective_date, students_per_available_day, bookings_per_available_day,
                    created_by_admin_id, updated_by_admin_id, created_at, updated_at
             FROM capacity_targets WHERE effective_date <= :date
             ORDER BY effective_date DESC, id DESC LIMIT 1',
        );
        $statement->execute([':date' => $date]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->map($row) : null;
    }

    public function lockByEffectiveDate(string $date): ?CapacityTarget
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Targetlock vereist een actieve transactie.');
        $statement = $this->pdo->prepare(
            'SELECT id, effective_date, students_per_available_day, bookings_per_available_day,
                    created_by_admin_id, updated_by_admin_id, created_at, updated_at
             FROM capacity_targets WHERE effective_date = :date FOR UPDATE',
        );
        $statement->execute([':date' => $date]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->map($row) : null;
    }

    public function insert(string $date, int $students, float $bookings, int $adminId): CapacityTarget
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO capacity_targets
                (effective_date, students_per_available_day, bookings_per_available_day,
                 created_by_admin_id, updated_by_admin_id, created_at, updated_at)
             VALUES (:date, :students, :bookings, :createdAdminId, :updatedAdminId, NOW(6), NOW(6))',
        );
        $statement->execute([
            ':date' => $date,
            ':students' => $students,
            ':bookings' => number_format($bookings, 1, '.', ''),
            ':createdAdminId' => $adminId,
            ':updatedAdminId' => $adminId,
        ]);
        return $this->lockByEffectiveDate($date) ?? throw new RuntimeException('Opgeslagen target kon niet worden gelezen.');
    }

    public function update(CapacityTarget $target, int $students, float $bookings, int $adminId): CapacityTarget
    {
        $statement = $this->pdo->prepare(
            'UPDATE capacity_targets SET students_per_available_day = :students,
                    bookings_per_available_day = :bookings, updated_by_admin_id = :adminId, updated_at = NOW(6)
             WHERE id = :id',
        );
        $statement->execute([':students' => $students, ':bookings' => number_format($bookings, 1, '.', ''), ':adminId' => $adminId, ':id' => $target->id]);
        return $this->lockByEffectiveDate($target->effectiveDate) ?? throw new RuntimeException('Bijgewerkt target kon niet worden gelezen.');
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): CapacityTarget
    {
        return new CapacityTarget(
            (int) $row['id'], (string) $row['effective_date'], (int) $row['students_per_available_day'],
            (float) $row['bookings_per_available_day'], (int) $row['created_by_admin_id'],
            (int) $row['updated_by_admin_id'], (string) $row['created_at'], (string) $row['updated_at'],
        );
    }
}
