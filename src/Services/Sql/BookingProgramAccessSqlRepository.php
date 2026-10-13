<?php

declare(strict_types=1);

namespace GeoFort\Services\Sql;

use PDO;
use RuntimeException;

final readonly class BookingProgramAccessSqlRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{fiveModuleOpen:bool,revision:int} */
    public function get(bool $lock = false): array
    {
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('Instellingslock vereist een transactie.');
        $suffix = $lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $row = $this->pdo->query('SELECT five_module_open, revision FROM booking_program_access WHERE id = 1' . $suffix)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Programma-instelling ontbreekt.');

        return ['fiveModuleOpen' => (bool) $row['five_module_open'], 'revision' => (int) $row['revision']];
    }

    public function update(bool $open, int $expectedRevision, int $actorId): bool
    {
        $statement = $this->pdo->prepare('UPDATE booking_program_access
            SET five_module_open = :open, revision = revision + 1, updated_by = :actor, updated_at = CURRENT_TIMESTAMP
            WHERE id = 1 AND revision = :expected');
        $statement->execute(['open' => (int) $open, 'actor' => $actorId, 'expected' => $expectedRevision]);

        return $statement->rowCount() === 1;
    }
}
