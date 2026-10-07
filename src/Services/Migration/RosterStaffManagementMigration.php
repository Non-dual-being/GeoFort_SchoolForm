<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use PDO;
use RuntimeException;

/** Explicit, one-time schema upgrade; no seeds in schema scripts and no HTTP entrypoint. */
final readonly class RosterStaffManagementMigration
{
    public function __construct(private PDO $pdo, private string $root) {}

    public function inspect(): array
    {
        $columns = $this->pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('roster_staff_members', 'roster_staff_module_preferences', 'roster_staff_catalog_state', 'roster_staff_change_history', 'roster_plans', 'roster_plan_snapshots') ORDER BY TABLE_NAME, ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $byTable = [];
        foreach ($columns as $row) $byTable[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        foreach (['employment_type', 'can_guide', 'can_cook'] as $column) if (!in_array($column, $byTable['roster_staff_members'] ?? [], true)) throw new RuntimeException('Previous personnel role schema is incomplete.');
        if (!isset($byTable['roster_plan_snapshots']) || !in_array('published_snapshot_id', $byTable['roster_plans'] ?? [], true)) throw new RuntimeException('V1 lifecycle/snapshot schema is incomplete.');
        $triggers = $this->pdo->query("SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('roster_staff_no_delete', 'roster_staff_history_no_update', 'roster_staff_history_no_delete') ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC);
        $constraints = $this->pdo->query("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('roster_staff_members', 'roster_staff_module_preferences', 'roster_staff_catalog_state', 'roster_staff_change_history') ORDER BY TABLE_NAME, CONSTRAINT_NAME")->fetchAll(PDO::FETCH_ASSOC);
        $hasVersion = in_array('version', $byTable['roster_staff_members'], true);
        $hasAny = $hasVersion || isset($byTable['roster_staff_catalog_state']) || isset($byTable['roster_staff_change_history']) || $triggers !== [];
        $missing = [];
        foreach ([
            'roster_staff_catalog_state' => ['id', 'revision', 'bootstrap_status', 'bootstrap_seed_hash', 'bootstrap_completed_at'],
            'roster_staff_change_history' => ['id', 'staff_id', 'actor_admin_id', 'action_type', 'previous_version', 'new_version', 'before_json', 'after_json', 'confirmed_impact_json', 'created_at'],
        ] as $table => $expected) foreach ($expected as $column) if (!in_array($column, $byTable[$table] ?? [], true)) $missing[] = $table . '.' . $column;
        if (!$hasVersion) $missing[] = 'roster_staff_members.version';
        foreach (['roster_staff_no_delete' => ['roster_staff_members', 'DELETE'], 'roster_staff_history_no_update' => ['roster_staff_change_history', 'UPDATE'], 'roster_staff_history_no_delete' => ['roster_staff_change_history', 'DELETE']] as $name => [$table, $event]) {
            $found = array_values(array_filter($triggers, static fn (array $row): bool => $row['TRIGGER_NAME'] === $name && $row['EVENT_OBJECT_TABLE'] === $table && $row['EVENT_MANIPULATION'] === $event && $row['ACTION_TIMING'] === 'BEFORE'));
            if ($found === []) $missing[] = $name;
        }
        foreach (['chk_roster_staff_version', 'chk_roster_staff_active', 'chk_roster_staff_guide', 'chk_roster_staff_cook', 'chk_roster_staff_employment',
            'chk_roster_staff_module_rank', 'chk_roster_staff_catalog_singleton', 'chk_roster_staff_catalog_revision', 'chk_roster_staff_bootstrap_status',
            'chk_roster_staff_change_action', 'chk_roster_staff_change_version', 'uq_roster_staff_change_version', 'fk_roster_staff_change_member', 'fk_roster_staff_change_actor'] as $name) {
            if (!in_array($name, array_column($constraints, 'CONSTRAINT_NAME'), true)) $missing[] = $name;
        }
        $invalid = $this->pdo->query("SELECT id FROM roster_staff_members WHERE is_active NOT IN (0,1) OR can_guide NOT IN (0,1) OR can_cook NOT IN (0,1) OR employment_type NOT IN ('paid','volunteer') LIMIT 1")->fetchColumn();
        if ($invalid !== false || $this->pdo->query('SELECT staff_id FROM roster_staff_module_preferences WHERE preference_rank < 1 LIMIT 1')->fetchColumn() !== false) throw new RuntimeException('Existing personnel values require review; no automatic repairs are allowed.');
        $state = !$hasAny ? 'absent' : ($missing === [] ? 'complete' : 'partial');
        return ['schemaState' => $state, 'missingObjects' => $missing, 'columns' => $byTable, 'constraints' => $constraints, 'triggers' => $triggers];
    }

    public function apply(bool $freshInstallation = false): array
    {
        $before = $this->inspect();
        if ($before['schemaState'] === 'partial') throw new RuntimeException('Partial Ronde C schema detected; stop and inspect manually. No migration replay.');
        if ($before['schemaState'] === 'absent') {
            // No retries/rollback/other migrations. A DDL exception stops execution immediately.
            $sql = file_get_contents($this->root . '/database/sql/2026-10-05_add_roster_staff_management.sql');
            if ($sql === false) throw new RuntimeException('Ronde C schema file missing.');
            $this->pdo->exec($sql);
        }
        $after = $this->inspect();
        if ($after['schemaState'] !== 'complete') throw new RuntimeException('Ronde C schema verification failed; bootstrap not executed.');
        $bootstrap = (new RosterStaffBootstrapService($this->pdo, $this->root . '/database/seeds/roster_staff_v1.json'))->run($freshInstallation);
        return ['before' => $before, 'after' => $after, 'bootstrap' => $bootstrap];
    }
}
