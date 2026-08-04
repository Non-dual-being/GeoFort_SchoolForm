<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Pricing\BookingPriceSnapshot;
use GeoFort\Services\Sql\BookingRevenueReportSqlRepository;

final readonly class BookingRevenueReportService
{
    private const MONEY_KEYS = ['visitInclVatCents', 'cateringInclVatCents', 'totalInclVatCents', 'totalExclVatCents', 'vatCents'];

    public function __construct(private BookingRevenueReportSqlRepository $repository) {}

    public function report(BookingRevenueCriteria $criteria): BookingRevenueReport
    {
        $counts = ['bookingsTotal'=>0,'definitive'=>0,'option'=>0,'rejected'=>0,'missingPrice'=>0,'studentsTotal'=>0,'studentsPrimary'=>0,'studentsSecondary'=>0];
        $definitive = $this->emptyRevenue();
        $potential = $this->emptyRevenue();
        $bookings = [];
        foreach ($this->repository->findRows($criteria) as $row) {
            $status = (string) $row['status'];
            $students = (int) ($row['aantal_leerlingen'] ?? 0);
            $sector = (string) ($row['onderwijs_sector'] ?? '');
            $complete = ($row['calculation_state'] ?? null) === BookingPriceSnapshot::STATE_COMPLETE;
            ++$counts['bookingsTotal'];
            $counts['studentsTotal'] += $students;
            if ($status === BookingPolicy::STATUS_CONFIRMED) ++$counts['definitive'];
            elseif ($status === BookingPolicy::STATUS_OPTION) ++$counts['option'];
            elseif ($status === BookingPolicy::STATUS_REJECTED) ++$counts['rejected'];
            $sectorCategory = BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['category'] ?? null;
            if ($sectorCategory === 'basis') $counts['studentsPrimary'] += $students;
            elseif ($sectorCategory === 'voortgezet') $counts['studentsSecondary'] += $students;
            if (!$complete) ++$counts['missingPrice'];
            $amounts = $complete ? $this->amounts($row) : null;
            if ($amounts !== null && $status === BookingPolicy::STATUS_CONFIRMED) $this->add($definitive, $amounts);
            if ($amounts !== null && $status === BookingPolicy::STATUS_OPTION) $this->add($potential, $amounts);
            $bookings[] = [
                'id'=>(int)$row['id'], 'visitDate'=>(string)$row['bezoekdatum'], 'schoolName'=>(string)$row['schoolnaam'],
                'sectorKey'=>$sector, 'sectorLabel'=>$this->sectorLabel($sector), 'studentCount'=>$students, 'status'=>$status,
                'snapshotState'=>$row['calculation_state'] === null ? 'missing' : (string)$row['calculation_state'],
                'snapshotSequence'=>$row['sequence_number'] === null ? null : (int)$row['sequence_number'], 'amounts'=>$amounts,
            ];
        }
        return new BookingRevenueReport($criteria, $counts, $definitive, $potential, $bookings);
    }

    /** @return array<string, int> */
    private function emptyRevenue(): array { return array_fill_keys(self::MONEY_KEYS, 0); }

    /** @param array<string, mixed> $row @return array<string, int> */
    private function amounts(array $row): array
    {
        return ['visitInclVatCents'=>(int)$row['visit_amount_incl_vat_cents'],'cateringInclVatCents'=>(int)$row['catering_amount_incl_vat_cents'],'totalInclVatCents'=>(int)$row['total_amount_incl_vat_cents'],'totalExclVatCents'=>(int)$row['total_amount_excl_vat_cents'],'vatCents'=>(int)$row['vat_amount_cents']];
    }

    /** @param array<string, int> $total @param array<string, int> $amounts */
    private function add(array &$total, array $amounts): void { foreach (self::MONEY_KEYS as $key) $total[$key] += $amounts[$key]; }

    private function sectorLabel(string $sector): string
    {
        return isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label']) ? (string)BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label'] : 'Onbekende sector';
    }
}
