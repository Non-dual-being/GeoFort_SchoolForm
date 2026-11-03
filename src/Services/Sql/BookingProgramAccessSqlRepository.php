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

    public function configuration(bool $lock = false): array
    {
        if ($lock && !$this->pdo->inTransaction()) throw new RuntimeException('Instellingslock vereist een transactie.');
        $suffix = $lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $row = $this->pdo->query('SELECT a.five_module_open, a.revision, a.updated_at, s.periods_json
            FROM booking_program_access a JOIN booking_program_schedule s ON s.id = a.id WHERE a.id = 1' . $suffix)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Programmaconfiguratie ontbreekt.');
        return ['fiveModuleOpen' => (bool) $row['five_module_open'], 'revision' => (int) $row['revision'],
            'periods' => json_decode($row['periods_json'], true, 16, JSON_THROW_ON_ERROR), 'updatedAt' => $row['updated_at']];
    }

    public function policy(bool $lock = false): \GeoFort\Booking\ProgramAvailabilityPolicy
    {
        return \GeoFort\Booking\ProgramAvailabilityPolicy::fromArray($this->configuration($lock));
    }

    public function saveConfiguration(bool $open, array $periods, int $revision, int $actor): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $before = $this->configuration(true);
            if ($before['revision'] !== $revision) { $this->pdo->rollBack(); return null; }
            if ($before['fiveModuleOpen'] === $open && $before['periods'] === $periods) { $this->pdo->rollBack(); return $before; }
            if (!$this->update($open, $revision, $actor)) throw new RuntimeException('Programmaconfiguratie gewijzigd.');
            $statement = $this->pdo->prepare('UPDATE booking_program_schedule SET periods_json = ? WHERE id = 1');
            $statement->execute([json_encode($periods, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            $after = $this->configuration();
            $statement = $this->pdo->prepare('INSERT INTO booking_program_access_history (revision, before_json, after_json, changed_by) VALUES (?, ?, ?, ?)');
            $statement->execute([$after['revision'], json_encode($before, JSON_THROW_ON_ERROR), json_encode($after, JSON_THROW_ON_ERROR), $actor]);
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
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
