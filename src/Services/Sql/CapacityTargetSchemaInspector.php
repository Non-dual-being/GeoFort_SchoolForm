<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;

final readonly class CapacityTargetSchemaInspector
{
    public function __construct(private PDO $pdo) {}

    public function tableExists(): bool
    {
        $statement = $this->pdo->query("SHOW TABLES LIKE 'capacity_targets'");
        return $statement !== false && $statement->fetchColumn() !== false;
    }

    public function usesLegacyUnsignedTargetColumns(): bool
    {
        if (!$this->tableExists()) return false;
        $columns = $this->columns('capacity_targets');

        return $this->normalizeColumnType((string) ($columns['students_per_available_day']['Type'] ?? '')) === 'smallint unsigned'
            && $this->normalizeColumnType((string) ($columns['bookings_per_available_day']['Type'] ?? '')) === 'decimal(2,1) unsigned';
    }

    /** @return list<string> */
    public function issues(): array
    {
        if (!$this->tableExists()) return ['De tabel capacity_targets ontbreekt.'];

        $issues = [];
        $columns = $this->columns('capacity_targets');
        $expected = [
            'id' => ['bigint unsigned', 'NO'],
            'effective_date' => ['date', 'NO'],
            'students_per_available_day' => ['smallint', 'NO'],
            'bookings_per_available_day' => ['decimal(2,1)', 'NO'],
            'created_by_admin_id' => ['int unsigned', 'NO'],
            'updated_by_admin_id' => ['int unsigned', 'NO'],
            'created_at' => ['datetime(6)', 'NO'],
            'updated_at' => ['datetime(6)', 'NO'],
        ];
        foreach ($expected as $name => [$type, $nullable]) {
            if (!isset($columns[$name])) {
                $issues[] = "Kolom capacity_targets.{$name} ontbreekt.";
                continue;
            }
            if ($this->normalizeColumnType((string) $columns[$name]['Type']) !== $type || (string) $columns[$name]['Null'] !== $nullable) {
                $issues[] = "Kolom capacity_targets.{$name} heeft niet het verwachte type {$type} {$nullable}.";
            }
        }

        $adminColumns = $this->columns('admin_users');
        if (!isset($adminColumns['id'])) {
            $issues[] = 'Kolom admin_users.id ontbreekt.';
        } else {
            foreach (['created_by_admin_id', 'updated_by_admin_id'] as $foreignColumn) {
                if (isset($columns[$foreignColumn])
                    && $this->normalizeColumnType((string) $columns[$foreignColumn]['Type']) !== $this->normalizeColumnType((string) $adminColumns['id']['Type'])) {
                    $issues[] = "{$foreignColumn} is niet datatype-compatibel met admin_users.id.";
                }
            }
        }

        $create = $this->showCreate('capacity_targets');
        foreach ([
            'uniq_capacity_targets_effective_date',
            'chk_capacity_targets_students',
            'chk_capacity_targets_bookings',
            'fk_capacity_targets_created_admin',
            'fk_capacity_targets_updated_admin',
        ] as $requiredDefinition) {
            if (stripos($create, $requiredDefinition) === false) {
                $issues[] = "Schemadefinitie {$requiredDefinition} ontbreekt.";
            }
        }
        $normalizedCreate = str_replace(['`', "\r", "\n"], ['', ' ', ' '], $create);
        if (preg_match('/students_per_available_day.*?between\s+0(?:\.0)?\s+and\s+160(?:\.0)?/i', $normalizedCreate) !== 1) {
            $issues[] = 'De databasegrens 0-160 voor leerlingen ontbreekt of wijkt af.';
        }
        if (preg_match('/bookings_per_available_day.*?between\s+0(?:\.0)?\s+and\s+2(?:\.0)?/i', $normalizedCreate) !== 1) {
            $issues[] = 'De databasegrens 0,0-2,0 voor boekingen ontbreekt of wijkt af.';
        }

        $targetStatus = $this->tableStatus('capacity_targets');
        $adminStatus = $this->tableStatus('admin_users');
        if (strcasecmp((string) ($targetStatus['Engine'] ?? ''), 'InnoDB') !== 0) {
            $issues[] = 'capacity_targets gebruikt niet de vereiste InnoDB-engine.';
        }
        if (($targetStatus['Collation'] ?? null) !== ($adminStatus['Collation'] ?? null)) {
            $issues[] = 'De collation van capacity_targets wijkt af van admin_users.';
        }

        return $issues;
    }

    /** @return array<string,array<string,mixed>> */
    private function columns(string $table): array
    {
        $statement = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        $result = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['Field']] = $row;
        }
        return $result;
    }

    private function showCreate(string $table): string
    {
        $statement = $this->pdo->query('SHOW CREATE TABLE `' . $table . '`');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_NUM);
        return is_array($row) ? (string) ($row[1] ?? '') : '';
    }

    private function normalizeColumnType(string $type): string
    {
        return strtolower((string) preg_replace('/\b(bigint|int|smallint)\(\d+\)/i', '$1', $type));
    }

    /** @return array<string,mixed> */
    private function tableStatus(string $table): array
    {
        $statement = $this->pdo->query("SHOW TABLE STATUS LIKE '" . $table . "'");
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}
