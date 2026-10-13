<?php
declare(strict_types=1);

/** CLI release helper; no booking mutations, backfills, schema removal or mail. */
final class FourModuleSchema
{
    public function __construct(private PDO $pdo, private string $root) {}

    public function inspect(): array
    {
        foreach (['aanvragen', 'roster_manual_plan_context', 'roster_plan_snapshots', 'roster_plan_archives'] as $table) {
            if (!$this->table($table)) throw new RuntimeException('Required base table missing: ' . $table);
        }
        $booking = $this->versionColumn('aanvragen');
        $manual = $this->versionColumn('roster_manual_plan_context');
        $access = $this->table('booking_program_access');
        $setting = $access ? $this->pdo->query('SELECT id, five_module_open, revision FROM booking_program_access')->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($access && (count($setting) !== 1 || (int)$setting[0]['id'] !== 1
            || !in_array((int)$setting[0]['five_module_open'], [0,1], true))) {
            throw new RuntimeException('Unexpected access-setting state; inspect manually.');
        }
        $newBookings = $booking ? (int)$this->pdo->query('SELECT COUNT(*) FROM aanvragen WHERE program_version IS NOT NULL')->fetchColumn() : 0;
        $newCustom = $manual ? (int)$this->pdo->query('SELECT COUNT(*) FROM roster_manual_plan_context WHERE program_version IS NOT NULL')->fetchColumn() : 0;
        $newSnapshots = (int)$this->pdo->query("SELECT COUNT(*) FROM roster_plan_snapshots WHERE content_json LIKE '%day-four-2027%'")->fetchColumn();
        $newArchives = (int)$this->pdo->query("SELECT COUNT(*) FROM roster_plan_archives WHERE presentation_json LIKE '%day-four-2027%'")->fetchColumn();
        return ['bookingVersionColumn'=>$booking, 'manualVersionColumn'=>$manual, 'accessTable'=>$access,
            'schemaReady'=>$booking && $manual && $access, 'setting'=>$setting[0] ?? null,
            'versionedBookings'=>$newBookings, 'versionedCustomPlans'=>$newCustom, 'fourModuleSnapshots'=>$newSnapshots,
            'fourModuleArchives'=>$newArchives, 'codeRollbackAllowed'=>$newBookings + $newCustom + $newSnapshots + $newArchives === 0];
    }

    /** Maintenance/write lock and verified backup are enforced by the operator before this call. */
    public function migrate(): array
    {
        if ((int)$this->pdo->query("SELECT GET_LOCK('geofort_four_module_schema', 0)")->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration is active.');
        }
        try {
            $state = $this->inspect();
            foreach ([
                'bookingVersionColumn'=>'2026-10-13_add_booking_program_version.sql',
                'accessTable'=>'2026-10-13_create_booking_program_access.sql',
                'manualVersionColumn'=>'2026-10-13_add_roster_manual_program_version.sql',
            ] as $key=>$file) {
                if (!$state[$key]) $this->pdo->exec(file_get_contents($this->root . '/database/sql/' . $file));
            }
            return $this->inspect();
        } finally { $this->pdo->query("SELECT RELEASE_LOCK('geofort_four_module_schema')"); }
    }

    private function table(string $name): bool
    {
        $q = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$name]); return (int)$q->fetchColumn() === 1;
    }

    private function versionColumn(string $table): bool
    {
        $q = $this->pdo->prepare("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name='program_version'");
        $q->execute([$table]); $column = $q->fetch(PDO::FETCH_ASSOC);
        if (!$column) return false;
        if ($column['DATA_TYPE'] !== 'varchar' || (int)$column['CHARACTER_MAXIMUM_LENGTH'] !== 40 || $column['IS_NULLABLE'] !== 'YES'
            || ($column['COLUMN_DEFAULT'] !== null && strtoupper($column['COLUMN_DEFAULT']) !== 'NULL')) {
            throw new RuntimeException('Unexpected existing program_version definition: ' . $table);
        }
        return true;
    }
}
