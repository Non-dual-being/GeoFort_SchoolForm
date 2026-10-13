<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Program;

use GeoFort\Services\Sql\BookingProgramAccessSqlRepository;

final readonly class BookingProgramAccessService
{
    public function __construct(private BookingProgramAccessSqlRepository $repository) {}

    public static function canManage(string $role): bool
    {
        return in_array($role, ['admin', 'planner'], true);
    }

    public function get(): array
    {
        return $this->repository->get();
    }

    public function assertCanSubmit(string $program, ?string $version): void
    {
        $access = $this->repository->get(true);
        if ($program === 'dag' && $version === null && !$access['fiveModuleOpen']) {
            throw new \GeoFort\Validation\FieldValidationException('programma', 'Het vijfmodulesformulier is gesloten. Kies het viermodulesprogramma.');
        }
    }

    public function save(mixed $open, mixed $revision, int $actorId, string $role): array
    {
        if ($actorId < 1 || !self::canManage($role)) return ['ok' => false, 'code' => 'FORBIDDEN'];
        if (!is_bool($open) || !is_int($revision) || $revision < 0) {
            return ['ok' => false, 'code' => 'INVALID_REQUEST'];
        }
        if (!$this->repository->update($open, $revision, $actorId)) {
            return ['ok' => false, 'code' => 'CONFLICT'];
        }

        return ['ok' => true, 'data' => ['fiveModuleOpen' => $open, 'revision' => $revision + 1]];
    }
}
