<?php

declare(strict_types=1);

use GeoFort\Services\Http\Api\Admin\DashboardCalendarRequest;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$failures = 0;
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        $failures++;
    }
};

$valid = DashboardCalendarRequest::fromQuery([
    'startDate' => '2027-02-01',
    'endDate' => '2027-03-14',
]);
$assert($valid?->startDate === '2027-02-01' && $valid->endDate === '2027-03-14', 'Geldige 42-daagse range wordt niet geaccepteerd.');
$assert(DashboardCalendarRequest::fromQuery(['startDate' => '2027-02-01', 'endDate' => '2027-03-15']) === null, 'Range langer dan 42 dagen wordt niet geweigerd.');
$assert(DashboardCalendarRequest::fromQuery(['startDate' => '2027-02-30', 'endDate' => '2027-03-01']) === null, 'Ongeldige datum wordt niet geweigerd.');
$assert(DashboardCalendarRequest::fromQuery(['startDate' => '2027-03-02', 'endDate' => '2027-03-01']) === null, 'Omgekeerde range wordt niet geweigerd.');

exit($failures === 0 ? 0 : 1);
