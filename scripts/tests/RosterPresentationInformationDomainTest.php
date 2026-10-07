<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Dashboard\Roster\RosterFinalSnapshot;
use GeoFort\Services\Dashboard\Roster\RosterPresentationInformation;

$assert = static function (bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); };
$row = array_fill_keys(['status', 'bezoekdatum', 'schoolnaam', 'land', 'adres', 'postcode', 'plaats',
    'school_telefoonnummer', 'contactpersoon_telefoonnummer', 'contactpersoon_voornaam', 'contactpersoon_achternaam',
    'email', 'cjpPasGebruik', 'onderwijs_sector', 'programma'], '');
$row = array_replace($row, ['id' => 1, 'onderwijs_sector' => 'primairOnderwijs', 'adres' => 'Oude straat 7',
    'postcode' => '1234 AB', 'land' => 'België', 'school_telefoonnummer' => '0301234567', 'email' => 'oud@example.test']);
foreach ([
    ['primairOnderwijs', [['regulier', 'groep7'], ['regulier', 'groep8']], ['Groep 7', 'Groep 8']],
    ['voortgezetOnderbouw', [['vmboGemengdTheoretisch', 'vmbo1'], ['havo', 'havo1'], ['vwo', 'atheneum1']], ['VMBO 1', 'HAVO 1', 'Atheneum 1']],
] as [$sector, $selection, $expected]) {
    $booking = (new StoredBookingAssembler())->assemble(array_replace($row, ['onderwijs_sector' => $sector]),
        array_map(static fn (array $s): array => ['sector_key' => $sector, 'level_key' => $s[0], 'group_key' => $s[1]], $selection));
    $info = RosterPresentationInformation::fromBooking($booking);
    $assert($info['education']['selectionLabels'] === $expected, 'Actual stored selection/configuration labels lost.');
    $assert($info['school'] === ['address' => 'Oude straat 7', 'postalCode' => '1234 AB', 'country' => 'België',
        'schoolPhone' => '0301234567', 'contactEmail' => 'oud@example.test'], 'Contact fields mixed up.');
}
$snapshot = ['presentation' => ['school' => ['name' => 'Bevroren naam', 'contactEmail' => '', 'country' => null],
    'education' => ['sectorLabel' => 'Historische sector', 'choiceModuleLabel' => 'Historische module'],
    'planning' => ['staffCatalog' => [['id' => 8, 'name' => 'Historische docent']], 'selectedStaffIds' => [8],
        'staffingSettings' => ['cookStaffId' => 8]]], 'stored' => ['booking' => $row,
        'educationSelections' => [
            ['level_label' => 'Oud niveau', 'group_label' => 'Bevroren groep 8', 'level_position' => 1, 'group_position' => 2],
            ['level_label' => 'Oud niveau', 'group_label' => 'Bevroren groep 7', 'level_position' => 1, 'group_position' => 1],
        ]]];
$before = serialize($snapshot);
$view = RosterPresentationInformation::fromSnapshot($snapshot);
$assert($view['school']['contactEmail'] === '' && $view['school']['country'] === null, 'Frozen empty/null value was overwritten.');
$assert($view['school']['address'] === 'Oude straat 7', 'Stored snapshot fallback missing.');
$assert($view['education']['selectionLabels'] === ['Bevroren groep 7', 'Bevroren groep 8'], 'Historical labels replaced/reordered by current taxonomy.');
$assert($view['planning'] === $snapshot['presentation']['planning'], 'Frozen staff/cook/selection changed.');
$assert($view['education']['sectorLabel'] === 'Historische sector' && $view['education']['choiceModuleLabel'] === 'Historische module', 'Frozen labels changed.');
$assert(serialize($snapshot) === $before, 'Snapshot mutated.');
$new = $snapshot;
$new['presentation'] = $view;
$new['stored']['booking']['adres'] = 'Afwijkende ruwe waarde';
$new['stored']['educationSelections'] = [];
$assert(RosterPresentationInformation::fromSnapshot($new) === $view, 'New snapshot presentation is not authoritative.');
$missing = RosterPresentationInformation::fromSnapshot(['presentation' => ['school' => [], 'education' => []],
    'stored' => ['educationSelections' => [['level_key' => 'regulier', 'group_key' => 'groep7']]]]);
$assert($missing['school']['address'] === '' && $missing['education']['selectionLabels'] === [], 'Missing historical data was invented.');
$assert(RosterPresentationInformation::fromBooking(null)['education']['selectionLabels'] === [], 'Unlinked custom roster invented education selection.');
echo "OK: actual PO/VO labels, contact mapping, frozen precedence, legacy raw snapshot fallback and immutable/missing history.\n";
