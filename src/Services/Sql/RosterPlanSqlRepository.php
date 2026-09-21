<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class RosterPlanSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * @return array{id:int,created:bool}
     */
    public function createForBooking(
        int $bookingId,
        string $visitDate,
        string $sourceBookingFingerprint,
        int $adminId,
    ): array {
        $existingId = $this->findIdByBookingId($bookingId);
        if ($existingId !== null) {
            return ['id' => $existingId, 'created' => false];
        }

        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_plans (
                    booking_id,
                    visit_date,
                    status,
                    revision,
                    source_booking_fingerprint,
                    created_by_admin_id,
                    updated_by_admin_id
                ) VALUES (
                    :booking_id,
                    :visit_date,
                    'concept',
                    1,
                    :source_booking_fingerprint,
                    :created_by_admin_id,
                    :updated_by_admin_id
                )
                SQL);
            $statement->execute([
                ':booking_id' => $bookingId,
                ':visit_date' => $visitDate,
                ':source_booking_fingerprint' => $sourceBookingFingerprint,
                ':created_by_admin_id' => $adminId,
                ':updated_by_admin_id' => $adminId,
            ]);

            return [
                'id' => (int) $this->pdo->lastInsertId(),
                'created' => true,
            ];
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $existingId = $this->findIdByBookingId($bookingId);
                if ($existingId !== null) {
                    return ['id' => $existingId, 'created' => false];
                }
            }

            throw new RuntimeException('Roosterplan kon niet worden aangemaakt.', 0, $exception);
        }
    }

    public function insertGroups(int $planId, int $groupCount): void
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_groups (
                    roster_plan_id,
                    label,
                    position,
                    student_count
                ) VALUES (
                    :roster_plan_id,
                    :label,
                    :position,
                    NULL
                )
                SQL);

            for ($position = 1; $position <= $groupCount; $position++) {
                $statement->execute([
                    ':roster_plan_id' => $planId,
                    ':label' => 'Groep ' . $position,
                    ':position' => $position,
                ]);
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Roostergroepen konden niet worden aangemaakt.', 0, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT
                    rp.id,
                    rp.booking_id,
                    rp.visit_date,
                    rp.status,
                    rp.revision,
                    rp.source_booking_fingerprint,
                    rp.created_at,
                    rp.updated_at,
                    a.schoolnaam,
                    a.plaats,
                    a.bezoekdatum AS booking_visit_date,
                    a.onderwijs_sector,
                    a.programma,
                    a.keuzemodule_key,
                    a.aantal_leerlingen
                FROM roster_plans rp
                LEFT JOIN aanvragen a ON a.id = rp.booking_id
                WHERE rp.id = :id
                SQL);
            $statement->bindValue(':id', $id, PDO::PARAM_INT);
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return null;
            }

            $row['groups'] = $this->findGroups($id);

            return $row;
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterplan kon niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return list<array<string,mixed>> */
    public function listPlans(): array
    {
        try {
            $statement = $this->pdo->query(<<<'SQL'
                SELECT
                    rp.id,
                    rp.booking_id,
                    rp.visit_date,
                    rp.status,
                    rp.revision,
                    rp.created_at,
                    rp.updated_at,
                    a.schoolnaam,
                    a.plaats,
                    a.onderwijs_sector,
                    a.programma,
                    a.keuzemodule_key,
                    a.aantal_leerlingen,
                    (
                        SELECT COUNT(*)
                        FROM roster_groups rg
                        WHERE rg.roster_plan_id = rp.id
                    ) AS group_count
                FROM roster_plans rp
                LEFT JOIN aanvragen a ON a.id = rp.booking_id
                ORDER BY rp.visit_date DESC, rp.id DESC
                SQL);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterplannen konden niet worden opgehaald.', 0, $exception);
        }
    }

    private function findIdByBookingId(int $bookingId): ?int
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT id FROM roster_plans WHERE booking_id = :booking_id LIMIT 1',
            );
            $statement->bindValue(':booking_id', $bookingId, PDO::PARAM_INT);
            $statement->execute();
            $value = $statement->fetchColumn();

            return $value === false ? null : (int) $value;
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterplan kon niet worden gecontroleerd.', 0, $exception);
        }
    }

    /** @return list<array{id:int,label:string,position:int,student_count:int|null}> */
    private function findGroups(int $planId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, label, position, student_count
            FROM roster_groups
            WHERE roster_plan_id = :roster_plan_id
            ORDER BY position ASC, id ASC
            SQL);
        $statement->bindValue(':roster_plan_id', $planId, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{id:int,label:string,position:int,student_count:int|null}> $rows */
        $rows = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'position' => (int) $row['position'],
                'student_count' => $row['student_count'] === null ? null : (int) $row['student_count'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );

        return $rows;
    }
}