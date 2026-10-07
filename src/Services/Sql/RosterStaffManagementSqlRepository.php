<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;

final readonly class RosterStaffManagementSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** Only mutable concepts participate; snapshots are never read or revalidated here. */
    public function conceptReferences(int $staffId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT p.id, p.visit_date, p.revision, r.kind, r.module_key
            FROM roster_plans p JOIN (
                SELECT roster_plan_id AS plan_id, 'selection' AS kind, NULL AS module_key
                FROM roster_plan_staff WHERE staff_id = ?
                UNION ALL
                SELECT s.roster_plan_id, 'guide', s.module_key FROM roster_session_staff ss
                JOIN roster_sessions s ON s.id = ss.roster_session_id WHERE ss.staff_id = ?
                UNION ALL
                SELECT s.roster_plan_id, 'guide', s.module_key FROM roster_session_group_staff gs
                JOIN roster_sessions s ON s.id = gs.roster_session_id WHERE gs.staff_id = ?
                UNION ALL
                SELECT roster_plan_id, 'cook', NULL FROM roster_plan_staffing_settings WHERE cook_staff_id = ?
            ) r ON r.plan_id = p.id
            WHERE p.status = 'concept'
            ORDER BY p.id, r.kind, r.module_key
            SQL);
        $statement->execute([$staffId, $staffId, $staffId, $staffId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(array $values): int
    {
        $statement = $this->pdo->prepare('INSERT INTO roster_staff_members (display_name, is_active, employment_type, can_guide, can_cook, version) VALUES (?, ?, ?, ?, ?, 1)');
        $statement->execute([$values['name'], (int) $values['isActive'], $values['employmentType'], (int) $values['canGuide'], (int) $values['canCook']]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, int $expectedVersion, array $values): bool
    {
        $statement = $this->pdo->prepare('UPDATE roster_staff_members SET display_name = ?, is_active = ?, employment_type = ?, can_guide = ?, can_cook = ?, version = version + 1 WHERE id = ? AND version = ?');
        $statement->execute([$values['name'], (int) $values['isActive'], $values['employmentType'], (int) $values['canGuide'], (int) $values['canCook'], $id, $expectedVersion]);
        return $statement->rowCount() === 1;
    }

    /** Preserve untouched legacy rows, including their ranks. */
    public function preferences(int $staffId, array $before, array $after): void
    {
        $old = array_column($before, 'rank', 'moduleKey');
        $new = array_column($after, 'rank', 'moduleKey');
        $delete = $this->pdo->prepare('DELETE FROM roster_staff_module_preferences WHERE staff_id = ? AND module_key = ?');
        foreach (array_diff_key($old, $new) as $key => $_) $delete->execute([$staffId, $key]);
        $insert = $this->pdo->prepare('INSERT INTO roster_staff_module_preferences (staff_id, module_key, preference_rank) VALUES (?, ?, ?)');
        $update = $this->pdo->prepare('UPDATE roster_staff_module_preferences SET preference_rank = ? WHERE staff_id = ? AND module_key = ?');
        $collision = $this->pdo->prepare('SELECT module_key FROM roster_staff_module_preferences WHERE staff_id = ? AND module_key = ?');
        foreach ($new as $key => $rank) {
            if (isset($old[$key])) {
                if ($old[$key] !== $rank) $update->execute([$rank, $staffId, $key]);
                continue;
            }
            // MySQL's case/accent-insensitive key may match a differently spelled legacy module.
            // Never turn an insert into an implicit overwrite of that legacy row.
            $collision->execute([$staffId, $key]);
            if ($collision->fetchColumn() !== false) {
                throw new RosterPlanException('LEGACY_MODULE_KEY_CONFLICT', 422, 'Deze module botst met een bestaande legacyvaardigheid. Beoordeel en verwijder die vaardigheid eerst expliciet.');
            }
            $insert->execute([$staffId, $key, $rank]);
        }
    }

    public function audit(int $id, int $actorId, int $previousVersion, ?array $before, array $after, array $impact): void
    {
        $json = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $statement = $this->pdo->prepare('INSERT INTO roster_staff_change_history (staff_id, actor_admin_id, action_type, previous_version, new_version, before_json, after_json, confirmed_impact_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
        $statement->execute([$id, $actorId, $before === null ? 'created' : 'updated', $previousVersion, $previousVersion + 1, $before === null ? null : $json($before), $json($after), $json($impact)]);
        $this->pdo->exec('UPDATE roster_staff_catalog_state SET revision = revision + 1 WHERE id = 1');
    }

    public function history(int $staffId): array
    {
        $statement = $this->pdo->prepare('SELECT id, actor_admin_id, action_type, previous_version, new_version, before_json, after_json, confirmed_impact_json, created_at FROM roster_staff_change_history WHERE staff_id = ? ORDER BY id DESC LIMIT 50');
        $statement->execute([$staffId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'actorId' => (int) $row['actor_admin_id'], 'action' => $row['action_type'],
            'previousVersion' => (int) $row['previous_version'], 'version' => (int) $row['new_version'],
            'before' => $row['before_json'] === null ? null : json_decode($row['before_json'], true, 32, JSON_THROW_ON_ERROR),
            'after' => json_decode($row['after_json'], true, 32, JSON_THROW_ON_ERROR),
            'impact' => json_decode($row['confirmed_impact_json'], true, 32, JSON_THROW_ON_ERROR), 'createdAt' => $row['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
