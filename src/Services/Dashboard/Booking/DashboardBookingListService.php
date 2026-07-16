<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Sql\DashboardBookingSqlService;

final readonly class DashboardBookingListService
{
    public function __construct(private DashboardBookingSqlService $sql) {}

    public function getPage(DashboardBookingFilters $filters): DashboardBookingPage
    {
        $totalItems = $this->sql->countBookings($filters);
        $totalPages = $totalItems === 0 ? 0 : (int) ceil($totalItems / $filters->perPage);
        $currentPage = $totalPages === 0 ? 1 : min($filters->page, $totalPages);
        $offset = ($currentPage - 1) * $filters->perPage;
        $rows = $this->sql->findBookings($filters, $filters->perPage, $offset);
        $items = array_map(fn (array $row): DashboardBookingListItem => $this->toListItem($row), $rows);
        $from = $totalItems === 0 ? 0 : $offset + 1;

        return new DashboardBookingPage(
            items: $items,
            currentPage: $currentPage,
            perPage: $filters->perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
            from: $from,
            to: $totalItems === 0 ? 0 : min($offset + count($items), $totalItems),
        );
    }

    /** @return array{statuses: list<array{value: string, label: string}>, sectors: list<array{value: string, label: string}>, programs: list<array{value: string, label: string}>, modules: list<array{value: string, label: string}>, dateRange: array{min: string|null, max: string|null}} */
    public function getFilterOptions(): array
    {
        $dateRange = $this->sql->getGlobalDateRange();

        return [
            'statuses' => array_map(
                static fn (string $status): array => ['value' => $status, 'label' => $status],
                BookingPolicy::ALLOWED_STATUSES,
            ),
            'sectors' => array_map(
                static fn (string $value, array $config): array => ['value' => $value, 'label' => (string) $config['label']],
                array_keys(BookingProgramConfig::SCHOOL_TYPES_BY_KEY),
                array_values(BookingProgramConfig::SCHOOL_TYPES_BY_KEY),
            ),
            'programs' => array_map(
                static fn (string $value, array $config): array => ['value' => $value, 'label' => (string) $config['label']],
                array_keys(BookingProgramConfig::PROGRAMS),
                array_values(BookingProgramConfig::PROGRAMS),
            ),
            'modules' => array_map(
                static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(BookingProgramConfig::MODULE_LABELS),
                array_values(BookingProgramConfig::MODULE_LABELS),
            ),
            'dateRange' => (new DashboardBookingDateRange(
                min: $dateRange['min'],
                max: $dateRange['max'],
            ))->toArray(),
        ];
    }

    /** @param array<string, int|string|null> $row */
    private function toListItem(array $row): DashboardBookingListItem
    {
        $sectorKey = (string) $row['onderwijs_sector'];
        $programKey = (string) $row['programma'];
        $moduleKey = is_string($row['keuzemodule_key']) && $row['keuzemodule_key'] !== '' ? $row['keuzemodule_key'] : null;
        $firstName = trim((string) $row['contactpersoon_voornaam']);
        $lastName = trim((string) $row['contactpersoon_achternaam']);

        return new DashboardBookingListItem(
            id: (int) $row['id'],
            status: (string) $row['status'],
            visitDate: (string) $row['bezoekdatum'],
            schoolName: (string) $row['schoolnaam'],
            city: (string) $row['plaats'],
            sectorKey: $sectorKey,
            sectorLabel: isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sectorKey]['label'])
                ? (string) BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sectorKey]['label']
                : $this->unknownLabel($sectorKey),
            programKey: $programKey,
            programLabel: BookingPolicy::PROGRAM_LABELS[$programKey] ?? $this->unknownLabel($programKey),
            moduleKey: $moduleKey,
            moduleLabel: $moduleKey === null
                ? 'Geen keuzemodule'
                : (BookingProgramConfig::MODULE_LABELS[$moduleKey] ?? $this->unknownLabel($moduleKey)),
            studentCount: is_int($row['aantal_leerlingen']) ? $row['aantal_leerlingen'] : null,
            contactPersonName: trim($firstName . ' ' . $lastName),
        );
    }

    private function unknownLabel(string $value): string
    {
        $label = trim(str_replace(['-', '_'], ' ', $value));
        return ($label !== '' ? $label : 'Onbekend') . ' (onbekend)';
    }
}
