<?php
declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\BookingVisitDateCalendarRequest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {fwrite(STDERR, "FAIL: {$message}\n");$failures++;}
};

$valid = BookingVisitDateCalendarRequest::fromQuery(['bookingId' => '181', 'startDate' => '2027-02-01', 'endDate' => '2027-03-14']);
$assert($valid?->bookingId === 181 && $valid->startDate === '2027-02-01', 'Geldige 42-daagse range wordt niet geaccepteerd.');
$assert(BookingVisitDateCalendarRequest::fromQuery(['bookingId' => '181', 'startDate' => '2027-02-01', 'endDate' => '2027-03-15']) === null, 'Range langer dan 42 dagen wordt niet geweigerd.');
$assert(BookingVisitDateCalendarRequest::fromQuery(['bookingId' => '0', 'startDate' => '2027-02-01', 'endDate' => '2027-02-01']) === null, 'Ongeldig booking-id wordt niet geweigerd.');
$assert(BookingVisitDateCalendarRequest::fromQuery(['bookingId' => '181', 'startDate' => '2027-02-30', 'endDate' => '2027-03-01']) === null, 'Ongeldige kalenderdatum wordt niet geweigerd.');
$assert(BookingVisitDateCalendarRequest::fromQuery(['bookingId' => '181', 'startDate' => '2027-03-02', 'endDate' => '2027-03-01']) === null, 'Omgekeerde range wordt niet geweigerd.');

exit($failures === 0 ? 0 : 1);
