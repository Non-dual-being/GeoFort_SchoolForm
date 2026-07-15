<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

interface LegacyMigrationTarget
{
    public function findSourceRecord(string $sourceSystem, int $sourceRecordId): ?array;
    public function targetIdExists(int $targetId): bool;
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function createImportRun(string $sourceSystem, string $filename, string $checksum, int $warningCount): int;
    public function insertBooking(array $booking): int;
    public function insertSelection(array $selection): void;
    public function completeImportRun(int $runId, int $imported, int $skipped, int $warnings): void;
    public function integritySnapshot(): array;
    public function assertIntegrity(array $before, int $runId, int $imported, int $selectionCount): void;
}
