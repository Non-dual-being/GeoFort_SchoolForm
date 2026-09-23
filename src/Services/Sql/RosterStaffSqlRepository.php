<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class RosterStaffSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        try {
            $members = $this->pdo->query(<<<'SQL'
                SELECT
                    id,
                    display_name,
                    is_active,
                    employment_type,
                    can_guide,
                    can_cook,
                    notes
                FROM roster_staff_members
                ORDER BY display_name ASC, id ASC
                SQL)->fetchAll(PDO::FETCH_ASSOC);

            $preferences = $this->pdo->query(<<<'SQL'
                SELECT staff_id, module_key, preference_rank
                FROM roster_staff_module_preferences
                ORDER BY staff_id ASC, preference_rank ASC, module_key ASC
                SQL)->fetchAll(PDO::FETCH_ASSOC);

            $rates = $this->pdo->query(<<<'SQL'
                SELECT staff_id, valid_from, valid_to, hourly_cost_cents
                FROM roster_staff_cost_rates
                ORDER BY staff_id ASC, valid_from DESC, id DESC
                SQL)->fetchAll(PDO::FETCH_ASSOC);

            $preferencesByStaff = [];
            foreach ($preferences as $row) {
                $staffId = (int) $row['staff_id'];
                $preferencesByStaff[$staffId] ??= [];
                $preferencesByStaff[$staffId][] = [
                    'moduleKey' => (string) $row['module_key'],
                    'rank' => (int) $row['preference_rank'],
                ];
            }

            $ratesByStaff = [];
            foreach ($rates as $row) {
                $staffId = (int) $row['staff_id'];
                $ratesByStaff[$staffId] ??= [];
                $ratesByStaff[$staffId][] = [
                    'validFrom' => (string) $row['valid_from'],
                    'validTo' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
                    'hourlyCostCents' => (int) $row['hourly_cost_cents'],
                ];
            }

            return array_map(
                static fn (array $member): array => [
                    'id' => (int) $member['id'],
                    'name' => (string) $member['display_name'],
                    'isActive' => (int) $member['is_active'] === 1,
                    'employmentType' => (string) $member['employment_type'],
                    'canGuide' => (int) $member['can_guide'] === 1,
                    'canCook' => (int) $member['can_cook'] === 1,
                    'notes' => $member['notes'] === null ? null : (string) $member['notes'],
                    'preferences' => $preferencesByStaff[(int) $member['id']] ?? [],
                    'costRates' => $ratesByStaff[(int) $member['id']] ?? [],
                ],
                $members,
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Personeelsconfiguratie kon niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    public function memberById(int $staffId): ?array
    {
        foreach ($this->catalog() as $member) {
            if ($member['id'] === $staffId) {
                return $member;
            }
        }

        return null;
    }

    /** @param list<int> $staffIds @return list<array<string,mixed>> */
    public function membersByIds(array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        $wanted = array_fill_keys(array_map('intval', $staffIds), true);

        return array_values(array_filter(
            $this->catalog(),
            static fn (array $member): bool => isset($wanted[$member['id']]),
        ));
    }

    /** @return list<int> */
    public function selectedIdsForPlan(int $planId): array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT staff_id
                FROM roster_plan_staff
                WHERE roster_plan_id = :plan_id
                ORDER BY staff_id ASC
                SQL);
            $statement->execute([':plan_id' => $planId]);

            return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterpersoneel kon niet worden opgehaald.', 0, $exception);
        }
    }

    /** @param list<int> $staffIds */
    public function replacePlanSelection(int $planId, array $staffIds): void
    {
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM roster_plan_staff WHERE roster_plan_id = :plan_id',
            );
            $delete->execute([':plan_id' => $planId]);

            $this->addPlanSelection($planId, $staffIds);
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterpersoneel kon niet worden opgeslagen.', 0, $exception);
        }
    }

    /** @param list<int> $staffIds */
    public function addPlanSelection(int $planId, array $staffIds): void
    {
        if ($staffIds === []) {
            return;
        }

        try {
            $insert = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_plan_staff (roster_plan_id, staff_id)
                VALUES (:plan_id, :staff_id)
                ON DUPLICATE KEY UPDATE staff_id = VALUES(staff_id)
                SQL);

            foreach (array_values(array_unique(array_map('intval', $staffIds))) as $staffId) {
                $insert->execute([
                    ':plan_id' => $planId,
                    ':staff_id' => $staffId,
                ]);
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterpersoneel kon niet worden aangevuld.', 0, $exception);
        }
    }

    /** @param list<int> $staffIds */
    public function replaceSessionAssignments(int $sessionId, array $staffIds, string $source = 'auto'): void
    {
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM roster_session_staff WHERE roster_session_id = :session_id',
            );
            $delete->execute([':session_id' => $sessionId]);

            if ($staffIds === []) {
                return;
            }

            $insert = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_session_staff (
                    roster_session_id,
                    staff_id,
                    assignment_source
                ) VALUES (
                    :session_id,
                    :staff_id,
                    :assignment_source
                )
                SQL);

            foreach (array_values(array_unique(array_map('intval', $staffIds))) as $staffId) {
                $insert->execute([
                    ':session_id' => $sessionId,
                    ':staff_id' => $staffId,
                    ':assignment_source' => $source,
                ]);
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Docenttoewijzing kon niet worden opgeslagen.', 0, $exception);
        }
    }

    /** @return array<int,list<array{id:int,name:string}>> */
    public function assignmentsForPlan(int $planId): array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT
                    rss.roster_session_id,
                    sm.id AS staff_id,
                    sm.display_name
                FROM roster_session_staff rss
                INNER JOIN roster_sessions rs ON rs.id = rss.roster_session_id
                INNER JOIN roster_staff_members sm ON sm.id = rss.staff_id
                WHERE rs.roster_plan_id = :plan_id
                ORDER BY rs.start_time ASC, rss.roster_session_id ASC, sm.display_name ASC
                SQL);
            $statement->execute([':plan_id' => $planId]);

            $result = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sessionId = (int) $row['roster_session_id'];
                $result[$sessionId] ??= [];
                $result[$sessionId][] = [
                    'id' => (int) $row['staff_id'],
                    'name' => (string) $row['display_name'],
                ];
            }

            return $result;
        } catch (PDOException $exception) {
            throw new RuntimeException('Docenttoewijzingen konden niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return array{staffingMode:string,preferGeoFortKe:bool,cookStaffId:?int} */
    public function settingsForPlan(int $planId): array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT staffing_mode, prefer_geofort_ke, cook_staff_id
                FROM roster_plan_staffing_settings
                WHERE roster_plan_id = :plan_id
                SQL);
            $statement->execute([':plan_id' => $planId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                return [
                    'staffingMode' => 'with_staff',
                    'preferGeoFortKe' => true,
                    'cookStaffId' => null,
                ];
            }

            return [
                'staffingMode' => (string) $row['staffing_mode'],
                'preferGeoFortKe' => (int) $row['prefer_geofort_ke'] === 1,
                'cookStaffId' => $row['cook_staff_id'] === null ? null : (int) $row['cook_staff_id'],
            ];
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterpersoneelsinstellingen konden niet worden opgehaald.', 0, $exception);
        }
    }

    public function savePlanSettings(
        int $planId,
        string $staffingMode,
        bool $preferGeoFortKe,
        ?int $cookStaffId,
    ): void {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_plan_staffing_settings (
                    roster_plan_id,
                    staffing_mode,
                    prefer_geofort_ke,
                    cook_staff_id
                ) VALUES (
                    :plan_id,
                    :staffing_mode,
                    :prefer_geofort_ke,
                    :cook_staff_id
                )
                ON DUPLICATE KEY UPDATE
                    staffing_mode = VALUES(staffing_mode),
                    prefer_geofort_ke = VALUES(prefer_geofort_ke),
                    cook_staff_id = VALUES(cook_staff_id)
                SQL);
            $statement->execute([
                ':plan_id' => $planId,
                ':staffing_mode' => $staffingMode,
                ':prefer_geofort_ke' => $preferGeoFortKe ? 1 : 0,
                ':cook_staff_id' => $cookStaffId,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterpersoneelsinstellingen konden niet worden opgeslagen.', 0, $exception);
        }
    }

    /** @return list<int> */
    public function overlappingSessionIdsForStaff(
        int $planId,
        int $staffId,
        string $startTime,
        string $endTime,
        ?int $excludeSessionId,
    ): array {
        $sql = <<<'SQL'
            SELECT rs.id
            FROM roster_session_staff rss
            INNER JOIN roster_sessions rs ON rs.id = rss.roster_session_id
            WHERE rs.roster_plan_id = :plan_id
              AND rss.staff_id = :staff_id
              AND rs.start_time < :end_time
              AND rs.end_time > :start_time
            SQL;

        $parameters = [
            ':plan_id' => $planId,
            ':staff_id' => $staffId,
            ':end_time' => $endTime,
            ':start_time' => $startTime,
        ];

        if ($excludeSessionId !== null) {
            $sql .= ' AND rs.id <> :exclude_session_id';
            $parameters[':exclude_session_id'] = $excludeSessionId;
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $exception) {
            throw new RuntimeException('Docentconflicten konden niet worden gecontroleerd.', 0, $exception);
        }
    }

    /** @return list<string> */
    public function assignedModuleKeysForStaff(int $planId, int $staffId, ?int $excludeSessionId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT rs.module_key
            FROM roster_session_staff rss
            INNER JOIN roster_sessions rs ON rs.id = rss.roster_session_id
            WHERE rs.roster_plan_id = :plan_id
              AND rss.staff_id = :staff_id
              AND rs.module_key IS NOT NULL
            SQL;
        $parameters = [
            ':plan_id' => $planId,
            ':staff_id' => $staffId,
        ];

        if ($excludeSessionId !== null) {
            $sql .= ' AND rs.id <> :exclude_session_id';
            $parameters[':exclude_session_id'] = $excludeSessionId;
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        } catch (PDOException $exception) {
            throw new RuntimeException('Docentmoduleverdeling kon niet worden gecontroleerd.', 0, $exception);
        }
    }

    /** @return list<array{startTime:string,endTime:string}> */
    public function assignmentIntervalsForStaff(int $planId, int $staffId): array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT rs.start_time, rs.end_time
                FROM roster_session_staff rss
                INNER JOIN roster_sessions rs ON rs.id = rss.roster_session_id
                WHERE rs.roster_plan_id = :plan_id
                  AND rss.staff_id = :staff_id
                ORDER BY rs.start_time ASC, rs.end_time ASC
                SQL);
            $statement->execute([
                ':plan_id' => $planId,
                ':staff_id' => $staffId,
            ]);

            return array_map(
                static fn (array $row): array => [
                    'startTime' => substr((string) $row['start_time'], 0, 5),
                    'endTime' => substr((string) $row['end_time'], 0, 5),
                ],
                $statement->fetchAll(PDO::FETCH_ASSOC),
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('Docenturen konden niet worden gecontroleerd.', 0, $exception);
        }
    }

    /** @return list<array{startTime:string,endTime:string}> */
    public function viIntervalsForPlan(int $planId): array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT DISTINCT start_time, end_time
                FROM roster_sessions
                WHERE roster_plan_id = :plan_id
                  AND module_key = 'Voedsel-Innovatie'
                ORDER BY start_time ASC, end_time ASC
                SQL);
            $statement->execute([':plan_id' => $planId]);

            return array_map(
                static fn (array $row): array => [
                    'startTime' => substr((string) $row['start_time'], 0, 5),
                    'endTime' => substr((string) $row['end_time'], 0, 5),
                ],
                $statement->fetchAll(PDO::FETCH_ASSOC),
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('VI-tijdvakken konden niet worden gecontroleerd.', 0, $exception);
        }
    }

    public function planHasViOverlap(int $planId, string $startTime, string $endTime): bool
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT 1
                FROM roster_sessions
                WHERE roster_plan_id = :plan_id
                  AND module_key = 'Voedsel-Innovatie'
                  AND start_time < :end_time
                  AND end_time > :start_time
                LIMIT 1
                SQL);
            $statement->execute([
                ':plan_id' => $planId,
                ':end_time' => $endTime,
                ':start_time' => $startTime,
            ]);

            return $statement->fetchColumn() !== false;
        } catch (PDOException $exception) {
            throw new RuntimeException('Kokconflict kon niet worden gecontroleerd.', 0, $exception);
        }
    }
}
