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

    public function assertCanSubmit(string $program, ?string $version, ?string $date = null): void
    {
        if ($date !== null) {
            if (!$this->repository->policy(true)->allows($program, $version, $date)) {
                throw new \GeoFort\Validation\FieldValidationException('programma', 'Dit programma is niet opengesteld voor de gekozen bezoekdatum. Kies een ander programma of een andere datum.');
            }
            return;
        }
        $access = $this->repository->get(true);
        if ($program === 'dag' && $version === null && !$access['fiveModuleOpen']) {
            throw new \GeoFort\Validation\FieldValidationException('programma', 'Het vijfmodulesformulier is gesloten. Kies het viermodulesprogramma.');
        }
    }

    public function configuration(): array { return $this->repository->configuration(); }

    public function saveConfiguration(mixed $open, mixed $periods, mixed $revision, int $actorId, string $role): array
    {
        if ($actorId < 1 || !self::canManage($role)) return ['ok' => false, 'code' => 'FORBIDDEN'];
        if (!is_bool($open) || !is_int($revision) || $revision < 0) return ['ok' => false, 'code' => 'INVALID_REQUEST'];
        try { $periods = \GeoFort\Booking\ProgramAvailabilityPolicy::validatePeriods($periods); }
        catch (\InvalidArgumentException $error) { return ['ok' => false, 'code' => 'INVALID_REQUEST', 'message' => $error->getMessage()]; }
        $after = $this->repository->saveConfiguration($open, $periods, $revision, $actorId);
        return $after === null ? ['ok' => false, 'code' => 'CONFLICT'] : ['ok' => true, 'data' => $after];
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
