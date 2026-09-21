<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Booking\Stored\StoredBooking;
use GeoFort\Services\Booking\Roster\RosterGroupCountResolver;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class RosterPlanService
{
    public function __construct(
        private PDO $pdo,
        private StoredBookingSqlRepository $bookings,
        private RosterPlanSqlRepository $plans,
        private RosterGroupCountResolver $groupCountResolver,
    ) {}

    /**
     * @return array{created:bool,plan:array<string,mixed>}
     */
    public function createFromBooking(int $bookingId, int $actingAdminId): array
    {
        $booking = $this->bookings->findById($bookingId);
        if ($booking === null) {
            throw new RosterPlanException(
                'BOOKING_NOT_FOUND',
                404,
                'De aanvraag bestaat niet.',
            );
        }

        if ($booking->studentCount === null) {
            throw new RosterPlanException(
                'STUDENT_COUNT_REQUIRED',
                422,
                'Het leerlingaantal ontbreekt; er kan nog geen rooster worden gestart.',
            );
        }

        $groupCount = $this->groupCountResolver->resolve(
            $booking->schoolSector,
            $booking->program,
            $booking->studentCount,
        );

        if ($groupCount === null) {
            throw new RosterPlanException(
                'GROUP_COUNT_UNAVAILABLE',
                422,
                'Voor deze programma- en leerlingcombinatie is geen roostergroepsindeling beschikbaar.',
            );
        }

        try {
            if (!$this->pdo->beginTransaction()) {
                throw new RuntimeException('Roostertransactie kon niet worden gestart.');
            }

            $creation = $this->plans->createForBooking(
                bookingId: $booking->id,
                visitDate: $booking->visitDate,
                sourceBookingFingerprint: $this->fingerprint($booking),
                adminId: $actingAdminId,
            );

            if ($creation['created']) {
                $this->plans->insertGroups($creation['id'], $groupCount);
            }

            if (!$this->pdo->commit()) {
                throw new RuntimeException('Roostertransactie kon niet worden vastgelegd.');
            }
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                }
            }

            if ($exception instanceof RosterPlanException) {
                throw $exception;
            }

            throw new RuntimeException('Roosterplan kon niet worden aangemaakt.', 0, $exception);
        }

        $plan = $this->getPlan($creation['id']);
        if ($plan === null) {
            throw new RuntimeException('Aangemaakt roosterplan kon niet worden teruggelezen.');
        }

        return [
            'created' => $creation['created'],
            'plan' => $plan,
        ];
    }

    /** @return array<string,mixed>|null */
    public function getPlan(int $planId): ?array
    {
        $row = $this->plans->findById($planId);
        if ($row === null) {
            return null;
        }

        $bookingId = $this->nullableInt($row['booking_id'] ?? null);
        $currentBooking = $bookingId === null ? null : $this->bookings->findById($bookingId);
        $sourceFingerprint = $this->nullableString($row['source_booking_fingerprint'] ?? null);
        $sourceCurrent = $currentBooking !== null
            && $sourceFingerprint !== null
            && hash_equals($sourceFingerprint, $this->fingerprint($currentBooking));

        return [
            'id' => (int) $row['id'],
            'bookingId' => $bookingId,
            'visitDate' => (string) $row['visit_date'],
            'status' => (string) $row['status'],
            'revision' => (int) $row['revision'],
            'sourceCurrent' => $sourceCurrent,
            'needsReview' => $bookingId !== null && !$sourceCurrent,
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => (string) $row['updated_at'],
            'school' => [
                'name' => $this->stringOrFallback($row['schoolnaam'] ?? null, 'Los rooster'),
                'city' => $this->stringOrFallback($row['plaats'] ?? null, ''),
            ],
            'education' => $this->education($row),
            'groups' => array_map(
                static fn (array $group): array => [
                    'id' => (int) $group['id'],
                    'label' => (string) $group['label'],
                    'position' => (int) $group['position'],
                    'studentCount' => $group['student_count'] === null
                        ? null
                        : (int) $group['student_count'],
                ],
                is_array($row['groups'] ?? null) ? $row['groups'] : [],
            ),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listPlans(): array
    {
        return array_map(
            fn (array $row): array => [
                'id' => (int) $row['id'],
                'bookingId' => $this->nullableInt($row['booking_id'] ?? null),
                'visitDate' => (string) $row['visit_date'],
                'status' => (string) $row['status'],
                'revision' => (int) $row['revision'],
                'schoolName' => $this->stringOrFallback($row['schoolnaam'] ?? null, 'Los rooster'),
                'city' => $this->stringOrFallback($row['plaats'] ?? null, ''),
                'sectorLabel' => $this->sectorLabel($this->nullableString($row['onderwijs_sector'] ?? null)),
                'programLabel' => $this->programLabel($this->nullableString($row['programma'] ?? null)),
                'moduleLabel' => $this->moduleLabel($this->nullableString($row['keuzemodule_key'] ?? null)),
                'studentCount' => $this->nullableInt($row['aantal_leerlingen'] ?? null),
                'groupCount' => (int) ($row['group_count'] ?? 0),
            ],
            $this->plans->listPlans(),
        );
    }

    /** @return array<string,mixed> */
    private function education(array $row): array
    {
        $sector = $this->nullableString($row['onderwijs_sector'] ?? null);
        $program = $this->nullableString($row['programma'] ?? null);
        $module = $this->nullableString($row['keuzemodule_key'] ?? null);

        return [
            'sector' => $sector,
            'sectorLabel' => $this->sectorLabel($sector),
            'program' => $program,
            'programLabel' => $this->programLabel($program),
            'choiceModule' => $module,
            'choiceModuleLabel' => $this->moduleLabel($module),
            'studentCount' => $this->nullableInt($row['aantal_leerlingen'] ?? null),
        ];
    }

    private function fingerprint(StoredBooking $booking): string
    {
        return hash('sha256', json_encode([
            'visitDate' => $booking->visitDate,
            'schoolSector' => $booking->schoolSector,
            'program' => $booking->program,
            'choiceModuleKey' => $booking->choiceModuleKey,
            'studentCount' => $booking->studentCount,
            'supervisorCount' => $booking->supervisorCount,
            'educationSelection' => $booking->educationSelection->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function sectorLabel(?string $sector): string
    {
        if ($sector === null) {
            return 'Niet gekoppeld';
        }

        return isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label'])
            ? (string) BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label']
            : $this->unknownLabel($sector);
    }

    private function programLabel(?string $program): string
    {
        if ($program === null) {
            return 'Niet gekoppeld';
        }

        return isset(BookingProgramConfig::PROGRAMS[$program]['label'])
            ? (string) BookingProgramConfig::PROGRAMS[$program]['label']
            : $this->unknownLabel($program);
    }

    private function moduleLabel(?string $module): ?string
    {
        if ($module === null) {
            return null;
        }

        return BookingProgramConfig::MODULE_LABELS[$module] ?? $this->unknownLabel($module);
    }

    private function unknownLabel(string $value): string
    {
        $label = trim(str_replace(['-', '_'], ' ', $value));
        return ($label !== '' ? $label : 'Onbekend') . ' (onbekend)';
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function stringOrFallback(mixed $value, string $fallback): string
    {
        $value = $this->nullableString($value);
        return $value ?? $fallback;
    }
}