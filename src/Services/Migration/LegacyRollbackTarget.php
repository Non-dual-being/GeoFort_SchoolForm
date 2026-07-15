<?php
declare(strict_types=1);
namespace GeoFort\Services\Migration;

interface LegacyRollbackTarget
{
    public function inspectRun(int $runId, string $sourceSystem): ?array;
    public function integritySnapshot(): array;
    public function begin(): void;
    public function deleteRunBookings(int $runId, string $sourceSystem): int;
    public function deleteRun(int $runId, string $sourceSystem): int;
    public function assertRollbackIntegrity(array $before, array $plan): void;
    public function commit(): void;
    public function rollback(): void;
}
