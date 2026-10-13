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
                    planning_mode,
                    visit_date,
                    status,
                    revision,
                    source_booking_fingerprint,
                    created_by_admin_id,
                    updated_by_admin_id
                ) VALUES (
                    :booking_id,
                    'booking_locked',
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

    public function createManualPlan(string $visitDate, int $adminId, ?int $bookingId = null): int
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_plans (
                    booking_id,
                    planning_mode,
                    visit_date,
                    status,
                    revision,
                    source_booking_fingerprint,
                    created_by_admin_id,
                    updated_by_admin_id
                ) VALUES (
                    :booking_id,
                    'custom',
                    :visit_date,
                    'concept',
                    1,
                    NULL,
                    :created_by_admin_id,
                    :updated_by_admin_id
                )
                SQL);
            $statement->execute([
                ':booking_id' => $bookingId,
                ':visit_date' => $visitDate,
                ':created_by_admin_id' => $adminId,
                ':updated_by_admin_id' => $adminId,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            throw new RuntimeException('Handmatig roosterplan kon niet worden aangemaakt.', 0, $exception);
        }
    }

    public function convertBookingPlanToCustom(int $planId, string $visitDate): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Roosterconversie vereist een actieve transactie.');
        }

        $pair = (new RosterPairSqlRepository($this->pdo))->forPlan($planId);
        if ($pair !== null && $pair['visitDate'] !== $visitDate) {
            throw new \GeoFort\Services\Dashboard\Roster\RosterPlanException('PAIR_DATE_MISMATCH', 409, 'Gekoppelde scholen moeten op dezelfde bezoekdatum blijven.');
        }
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE roster_plans
                SET planning_mode = 'custom',
                    visit_date = :visit_date,
                    source_booking_fingerprint = NULL
                WHERE id = :plan_id
                  AND planning_mode = 'booking_locked'
                SQL);
            $statement->execute([
                ':visit_date' => $visitDate,
                ':plan_id' => $planId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Roosterplan kon niet eenduidig naar maatwerk worden omgezet.');
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterplan kon niet naar maatwerk worden omgezet.', 0, $exception);
        }
    }

    public function saveManualContext(
        int $planId,
        string $schoolName,
        string $city,
        string $sectorKey,
        string $programKey,
        int $studentCount,
        string $dayStartTime,
        string $dayEndTime,
        ?string $programVersion = null,
    ): void {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_manual_plan_context (
                    roster_plan_id,
                    school_name,
                    city,
                    sector_key,
                    program_key,
                    program_version,
                    student_count,
                    day_start_time,
                    day_end_time
                ) VALUES (
                    :plan_id,
                    :school_name,
                    :city,
                    :sector_key,
                    :program_key,
                    :program_version,
                    :student_count,
                    :day_start_time,
                    :day_end_time
                )
                ON DUPLICATE KEY UPDATE
                    school_name = VALUES(school_name),
                    city = VALUES(city),
                    sector_key = VALUES(sector_key),
                    program_key = VALUES(program_key),
                    program_version = VALUES(program_version),
                    student_count = VALUES(student_count),
                    day_start_time = VALUES(day_start_time),
                    day_end_time = VALUES(day_end_time)
                SQL);
            $statement->execute([
                ':plan_id' => $planId,
                ':school_name' => $schoolName,
                ':city' => $city,
                ':sector_key' => $sectorKey,
                ':program_key' => $programKey,
                ':program_version' => $programVersion,
                ':student_count' => $studentCount,
                ':day_start_time' => $dayStartTime,
                ':day_end_time' => $dayEndTime,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Handmatige roosterbasis kon niet worden opgeslagen.', 0, $exception);
        }
    }

    /** @param list<string> $moduleKeys */
    public function saveManualModuleKeys(int $planId, array $moduleKeys): void
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE roster_manual_plan_context
                SET module_keys_json = :module_keys_json
                WHERE roster_plan_id = :plan_id
                SQL);
            $statement->execute([
                ':module_keys_json' => json_encode(
                    array_values($moduleKeys),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
                ':plan_id' => $planId,
            ]);

            if ($statement->rowCount() > 1) {
                throw new RuntimeException('Handmatig programma kon niet eenduidig worden opgeslagen.');
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Handmatig programma kon niet worden opgeslagen.', 0, $exception);
        }
    }

    /** @param list<array{type:string,label:string,startTime:string,endTime:string}> $rounds */
    public function saveManualRounds(int $planId, array $rounds): void
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE roster_manual_plan_context
                SET rounds_json = :rounds_json
                WHERE roster_plan_id = :plan_id
                SQL);
            $statement->execute([
                ':rounds_json' => json_encode($rounds, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ':plan_id' => $planId,
            ]);

            if ($statement->rowCount() > 1) {
                throw new RuntimeException('Handmatige dagindeling kon niet eenduidig worden opgeslagen.');
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Handmatige dagindeling kon niet worden opgeslagen.', 0, $exception);
        }
    }

    public function clearManualRounds(int $planId): void
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE roster_manual_plan_context
                SET rounds_json = NULL
                WHERE roster_plan_id = :plan_id
                SQL);
            $statement->execute([':plan_id' => $planId]);

            if ($statement->rowCount() > 1) {
                throw new RuntimeException('Handmatige dagindeling kon niet eenduidig worden gereset.');
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Handmatige dagindeling kon niet worden gereset.', 0, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    public function manualContextForPlan(int $planId): ?array
    {
        // Net als module_keys_json mogen nog niet gemigreerde kolommen bij lezen ontbreken.
        // Gebruik uitsluitend deze vaste projecties; behoud iedere aanwezige JSON-kolom.
        $lastException = null;
        foreach ([
            'module_keys_json, rounds_json',
            'module_keys_json, NULL AS rounds_json',
            'NULL AS module_keys_json, rounds_json',
            'NULL AS module_keys_json, NULL AS rounds_json',
        ] as $jsonColumns) {
            try {
                $statement = $this->pdo->prepare(<<<SQL
                    SELECT
                        roster_plan_id,
                        school_name,
                        city,
                        sector_key,
                        program_key,
                    program_version,
                        student_count,
                        {$jsonColumns},
                        day_start_time,
                        day_end_time,
                        created_at,
                        updated_at
                    FROM roster_manual_plan_context
                    WHERE roster_plan_id = :plan_id
                    SQL);
                $statement->execute([':plan_id' => $planId]);
                $row = $statement->fetch(PDO::FETCH_ASSOC);

                return is_array($row) ? $row : null;
            } catch (PDOException $exception) {
                if ($this->isMissingManualContextTable($exception)) {
                    return null;
                }
                if (!$this->isMissingManualContextColumn($exception)) {
                    throw new RuntimeException('Handmatige roosterbasis kon niet worden opgehaald.', 0, $exception);
                }
                $lastException = $exception;
            }
        }

        throw new RuntimeException('Handmatige roosterbasis kon niet worden opgehaald.', 0, $lastException);
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
                    rp.planning_mode,
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
                    a.program_version,
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

    /** @return array{id:int,booking_id:int|null,status:string,revision:int}|null */
    public function lockPlan(int $id): ?array
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Roosterlock vereist een actieve transactie.');
        }

        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT id, booking_id, planning_mode, status, revision
                FROM roster_plans
                WHERE id = :id
                FOR UPDATE
                SQL);
            $statement->execute([':id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return null;
            }

            return [
                'id' => (int) $row['id'],
                'booking_id' => $row['booking_id'] === null ? null : (int) $row['booking_id'],
                'planning_mode' => (string) $row['planning_mode'],
                'status' => (string) $row['status'],
                'revision' => (int) $row['revision'],
            ];
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterplan kon niet worden gelockt.', 0, $exception);
        }
    }

    public function deleteById(int $planId): bool
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Roosterverwijdering vereist een actieve transactie.');
        }

        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM roster_plans WHERE id = :plan_id',
            );
            $statement->execute([':plan_id' => $planId]);

            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Roosterplan kon niet worden verwijderd.',
                0,
                $exception,
            );
        }
    }

    public function advanceRevision(int $planId, int $adminId): int
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Revisiewijziging vereist een actieve transactie.');
        }

        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE roster_plans
                SET revision = revision + 1,
                    updated_by_admin_id = :admin_id
                WHERE id = :plan_id
                SQL);
            $statement->execute([
                ':admin_id' => $adminId,
                ':plan_id' => $planId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Roosterrevisie kon niet worden verhoogd.');
            }

            $read = $this->pdo->prepare('SELECT revision FROM roster_plans WHERE id = :plan_id');
            $read->execute([':plan_id' => $planId]);
            return (int) $read->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterrevisie kon niet worden bijgewerkt.', 0, $exception);
        }
    }

    /** @return list<int> */
    public function findGroupIds(int $planId): array
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT id FROM roster_groups WHERE roster_plan_id = :plan_id ORDER BY position ASC, id ASC',
            );
            $statement->execute([':plan_id' => $planId]);
            return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $exception) {
            throw new RuntimeException('Roostergroepen konden niet worden gecontroleerd.', 0, $exception);
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
                    rp.planning_mode,
                    rp.visit_date,
                    rp.status,
                    rp.revision,
                    rp.created_at,
                    rp.updated_at,
                    a.schoolnaam,
                    a.plaats,
                    a.onderwijs_sector,
                    a.programma,
                    a.program_version,
                    a.keuzemodule_key,
                    a.aantal_leerlingen,
                    (
                        SELECT COUNT(*)
                        FROM roster_groups rg
                        WHERE rg.roster_plan_id = rp.id
                    ) AS group_count
                FROM roster_plans rp
                LEFT JOIN aanvragen a ON a.id = rp.booking_id
                WHERE rp.status <> 'archived'
                ORDER BY rp.visit_date DESC, rp.id DESC
                SQL);

            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterplannen konden niet worden opgehaald.', 0, $exception);
        }
    }

    public function findIdByBookingId(int $bookingId): ?int
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

    private function isMissingManualContextColumn(PDOException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1054;
    }

    private function isMissingManualContextTable(PDOException $exception): bool
    {
        return $exception->getCode() === '42S02'
            || (int) ($exception->errorInfo[1] ?? 0) === 1146;
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

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'position' => (int) $row['position'],
                'student_count' => $row['student_count'] === null ? null : (int) $row['student_count'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}