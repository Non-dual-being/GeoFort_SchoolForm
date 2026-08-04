<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Overview;

use DateTimeImmutable;
use DateTimeZone;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Dashboard\Overview\DashboardOverviewData;
use GeoFort\Services\Sql\DashboardOverviewSqlRepository;

final readonly class DashboardOverviewService
{
    public const TIMEZONE = 'Europe/Amsterdam';
    private const OPTION_LIMIT = 5;

    public function __construct(private DashboardOverviewSqlRepository $repository) {}

    public function get(?DateTimeImmutable $now = null): DashboardOverviewData
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $now = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
        $today = $now->format('Y-m-d');
        $monthStart = new DateTimeImmutable($now->format('Y-m-01'), $timezone);
        $monthEnd = $monthStart->modify('first day of next month');
        $options = $this->repository->optionSummary(self::OPTION_LIMIT);
        $next = $this->repository->nextOptions($today);
        $statistics = [
            'year' => (int) $monthStart->format('Y'),
            'month' => (int) $monthStart->format('n'),
            'total' => 0,
            'confirmed' => 0,
            'option' => 0,
            'rejected' => 0,
            'students' => 0,
            'studentsPrimary' => 0,
            'studentsSecondary' => 0,
        ];
        foreach ($this->repository->monthStatistics($monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')) as $row) {
            $statistics['total'] += $row['bookings'];
            $statistics['students'] += $row['students'];
            if ($row['status'] === BookingPolicy::STATUS_CONFIRMED) {
                $statistics['confirmed'] += $row['bookings'];
            }
            if ($row['status'] === BookingPolicy::STATUS_OPTION) {
                $statistics['option'] += $row['bookings'];
            }
            if ($row['status'] === BookingPolicy::STATUS_REJECTED) {
                $statistics['rejected'] += $row['bookings'];
            }
            if ($row['sector'] === 'primairOnderwijs') {
                $statistics['studentsPrimary'] += $row['students'];
            } elseif (str_starts_with($row['sector'], 'voortgezet')) {
                $statistics['studentsSecondary'] += $row['students'];
            }
        }
        $map = fn (array $row): array => $this->mapBooking($row, $today);
        return new DashboardOverviewData(
            $today,
            self::TIMEZONE,
            $options['total'],
            array_map($map, $options['items']),
            $next === [] ? null : (string) $next[0]['bezoekdatum'],
            array_map($map, $next),
            $statistics,
        );
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapBooking(array $row, string $today): array
    {
        $program = (string) $row['programma'];
        $sector = (string) $row['onderwijs_sector'];
        return [
            'id' => (int) $row['id'],
            'visitDate' => is_string($row['bezoekdatum']) ? $row['bezoekdatum'] : null,
            'schoolName' => (string) $row['schoolnaam'],
            'programKey' => $program,
            'programLabel' => BookingPolicy::isAllowedProgram($program) ? BookingPolicy::getProgramLabel($program) : $program,
            'sectorKey' => $sector,
            'sectorLabel' => BookingProgramConfig::isValidSchoolSectorValue($sector) ? BookingProgramConfig::getSchoolSectorLabel($sector) : $sector,
            'studentCount' => $row['aantal_leerlingen'] === null ? null : (int) $row['aantal_leerlingen'],
            'expired' => is_string($row['bezoekdatum']) && $row['bezoekdatum'] < $today,
        ];
    }
}
