<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Revenue;

final class BookingRevenueReportMapper
{
    /** @return array<string, mixed> */
    public function map(BookingRevenueReport $report): array
    {
        return [
            'period' => ['startDate' => $report->period->startDate, 'endDate' => $report->period->endDate],
            'counts' => $report->counts,
            'definitiveRevenue' => $report->definitiveRevenue,
            'potentialRevenue' => $report->potentialRevenue,
            'bookings' => $report->bookings,
        ];
    }
}
