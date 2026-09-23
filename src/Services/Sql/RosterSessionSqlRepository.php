<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use PDOException;
use RuntimeException;

final readonly class RosterSessionSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function findByPlanId(int $planId): array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT
                    id,
                    roster_plan_id,
                    item_type,
                    module_key,
                    start_time,
                    end_time,
                    location_label,
                    created_at,
                    updated_at
                FROM roster_sessions
                WHERE roster_plan_id = :plan_id
                ORDER BY start_time ASC, end_time ASC, id ASC
                SQL);
            $statement->execute([':plan_id' => $planId]);
            $sessions = $statement->fetchAll(PDO::FETCH_ASSOC);

            if ($sessions === []) {
                return [];
            }

            $groups = $this->groupLinksForPlan($planId);
            foreach ($sessions as &$session) {
                $sessionId = (int) $session['id'];
                $session['group_ids'] = $groups[$sessionId] ?? [];
            }
            unset($session);

            return $sessions;
        } catch (PDOException $exception) {
            throw new RuntimeException('Roostersessies konden niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return array<string,mixed>|null */
    public function findByIdForPlan(int $sessionId, int $planId): ?array
    {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                SELECT id, roster_plan_id, item_type, module_key, start_time, end_time, location_label
                FROM roster_sessions
                WHERE id = :id AND roster_plan_id = :plan_id
                SQL);
            $statement->execute([
                ':id' => $sessionId,
                ':plan_id' => $planId,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (PDOException $exception) {
            throw new RuntimeException('Roostersessie kon niet worden opgehaald.', 0, $exception);
        }
    }

    public function insertActivity(
        int $planId,
        string $moduleKey,
        string $startTime,
        string $endTime,
        ?string $location,
        int $adminId,
    ): int {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_sessions (
                    roster_plan_id,
                    item_type,
                    module_key,
                    start_time,
                    end_time,
                    location_label,
                    created_by_admin_id,
                    updated_by_admin_id
                ) VALUES (
                    :plan_id,
                    'activity',
                    :module_key,
                    :start_time,
                    :end_time,
                    :location_label,
                    :created_by,
                    :updated_by
                )
                SQL);
            $statement->execute([
                ':plan_id' => $planId,
                ':module_key' => $moduleKey,
                ':start_time' => $startTime,
                ':end_time' => $endTime,
                ':location_label' => $location,
                ':created_by' => $adminId,
                ':updated_by' => $adminId,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            throw new RuntimeException('Roostersessie kon niet worden aangemaakt.', 0, $exception);
        }
    }

    public function updateActivity(
        int $sessionId,
        int $planId,
        string $moduleKey,
        string $startTime,
        string $endTime,
        ?string $location,
        int $adminId,
    ): void {
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE roster_sessions
                SET
                    module_key = :module_key,
                    start_time = :start_time,
                    end_time = :end_time,
                    location_label = :location_label,
                    updated_by_admin_id = :updated_by
                WHERE id = :id
                  AND roster_plan_id = :plan_id
                  AND item_type = 'activity'
                SQL);
            $statement->execute([
                ':module_key' => $moduleKey,
                ':start_time' => $startTime,
                ':end_time' => $endTime,
                ':location_label' => $location,
                ':updated_by' => $adminId,
                ':id' => $sessionId,
                ':plan_id' => $planId,
            ]);

            if ($statement->rowCount() > 1) {
                throw new RuntimeException('Onverwacht aantal gewijzigde roostersessies.');
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Roostersessie kon niet worden gewijzigd.', 0, $exception);
        }
    }

    /** @param list<int> $groupIds */
    public function replaceGroups(int $sessionId, array $groupIds): void
    {
        try {
            $delete = $this->pdo->prepare(
                'DELETE FROM roster_session_groups WHERE roster_session_id = :session_id',
            );
            $delete->execute([':session_id' => $sessionId]);

            $insert = $this->pdo->prepare(<<<'SQL'
                INSERT INTO roster_session_groups (roster_session_id, roster_group_id)
                VALUES (:session_id, :group_id)
                SQL);

            foreach ($groupIds as $groupId) {
                $insert->execute([
                    ':session_id' => $sessionId,
                    ':group_id' => $groupId,
                ]);
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Groepen konden niet aan de roostersessie worden gekoppeld.', 0, $exception);
        }
    }

    public function deleteByPlanId(int $planId): void
    {
        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM roster_sessions WHERE roster_plan_id = :plan_id',
            );
            $statement->execute([':plan_id' => $planId]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Bestaande roostersessies konden niet worden vervangen.', 0, $exception);
        }
    }

    public function delete(int $sessionId, int $planId): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM roster_sessions WHERE id = :id AND roster_plan_id = :plan_id',
            );
            $statement->execute([
                ':id' => $sessionId,
                ':plan_id' => $planId,
            ]);

            return $statement->rowCount() === 1;
        } catch (PDOException $exception) {
            throw new RuntimeException('Roostersessie kon niet worden verwijderd.', 0, $exception);
        }
    }

    /**
     * @param list<int> $groupIds
     * @return list<int>
     */
    public function overlappingGroupIds(
        int $planId,
        array $groupIds,
        string $startTime,
        string $endTime,
        ?int $excludeSessionId,
    ): array {
        if ($groupIds === []) {
            return [];
        }

        [$placeholders, $parameters] = $this->inParameters('group', $groupIds);
        $parameters[':plan_id'] = $planId;
        $parameters[':start_time'] = $startTime;
        $parameters[':end_time'] = $endTime;

        $exclude = '';
        if ($excludeSessionId !== null) {
            $exclude = ' AND rs.id <> :exclude_session_id';
            $parameters[':exclude_session_id'] = $excludeSessionId;
        }

        $sql = <<<SQL
            SELECT DISTINCT rsg.roster_group_id
            FROM roster_session_groups rsg
            INNER JOIN roster_sessions rs ON rs.id = rsg.roster_session_id
            WHERE rs.roster_plan_id = :plan_id
              AND rsg.roster_group_id IN ({$placeholders})
              AND rs.start_time < :end_time
              AND rs.end_time > :start_time
              {$exclude}
            SQL;

        return $this->intColumn($sql, $parameters);
    }

    /**
     * @param list<int> $groupIds
     * @return list<int>
     */
    public function duplicateModuleGroupIds(
        int $planId,
        string $moduleKey,
        array $groupIds,
        ?int $excludeSessionId,
    ): array {
        if ($groupIds === []) {
            return [];
        }

        [$placeholders, $parameters] = $this->inParameters('group', $groupIds);
        $parameters[':plan_id'] = $planId;
        $parameters[':module_key'] = $moduleKey;

        $exclude = '';
        if ($excludeSessionId !== null) {
            $exclude = ' AND rs.id <> :exclude_session_id';
            $parameters[':exclude_session_id'] = $excludeSessionId;
        }

        $sql = <<<SQL
            SELECT DISTINCT rsg.roster_group_id
            FROM roster_session_groups rsg
            INNER JOIN roster_sessions rs ON rs.id = rsg.roster_session_id
            WHERE rs.roster_plan_id = :plan_id
              AND rs.item_type = 'activity'
              AND rs.module_key = :module_key
              AND rsg.roster_group_id IN ({$placeholders})
              {$exclude}
            SQL;

        return $this->intColumn($sql, $parameters);
    }

    public function overlappingModuleSessionCount(
        int $planId,
        string $moduleKey,
        string $startTime,
        string $endTime,
        ?int $excludeSessionId,
    ): int {
        $parameters = [
            ':plan_id' => $planId,
            ':module_key' => $moduleKey,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
        ];

        $exclude = '';
        if ($excludeSessionId !== null) {
            $exclude = ' AND id <> :exclude_session_id';
            $parameters[':exclude_session_id'] = $excludeSessionId;
        }

        try {
            $statement = $this->pdo->prepare(<<<SQL
                SELECT COUNT(*)
                FROM roster_sessions
                WHERE roster_plan_id = :plan_id
                  AND item_type = 'activity'
                  AND module_key = :module_key
                  AND start_time < :end_time
                  AND end_time > :start_time
                  {$exclude}
                SQL);
            $statement->execute($parameters);
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException('Parallelle roostersessies konden niet worden gecontroleerd.', 0, $exception);
        }
    }

    /** @return array<int,list<int>> */
    private function groupLinksForPlan(int $planId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT rsg.roster_session_id, rsg.roster_group_id
            FROM roster_session_groups rsg
            INNER JOIN roster_sessions rs ON rs.id = rsg.roster_session_id
            WHERE rs.roster_plan_id = :plan_id
            ORDER BY rsg.roster_session_id ASC, rsg.roster_group_id ASC
            SQL);
        $statement->execute([':plan_id' => $planId]);

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sessionId = (int) $row['roster_session_id'];
            $result[$sessionId] ??= [];
            $result[$sessionId][] = (int) $row['roster_group_id'];
        }

        return $result;
    }

    /**
     * @param list<int> $values
     * @return array{0:string,1:array<string,int>}
     */
    private function inParameters(string $prefix, array $values): array
    {
        $placeholders = [];
        $parameters = [];

        foreach (array_values($values) as $index => $value) {
            $placeholder = ':' . $prefix . '_' . $index;
            $placeholders[] = $placeholder;
            $parameters[$placeholder] = $value;
        }

        return [implode(', ', $placeholders), $parameters];
    }

    /** @param array<string,mixed> $parameters @return list<int> */
    private function intColumn(string $sql, array $parameters): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($parameters);
            return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $exception) {
            throw new RuntimeException('Roosterconflicten konden niet worden gecontroleerd.', 0, $exception);
        }
    }
}