<?php

declare(strict_types=1);

use GeoFort\Services\Migration\LegacyBookingMapper;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mapper = new LegacyBookingMapper();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$base = [
    'id' => 1, 'status' => 'In optie', 'schoolnaam' => 'School', 'adres' => 'Straat 1', 'postcode' => '1234 AB', 'plaats' => 'Plaats',
    'school_telefoon' => '0123456789', 'contact_telefoon' => '0612345678', 'voornaam_contactpersoon' => 'Jan', 'achternaam_contactpersoon' => 'Jansen',
    'email' => 'jan@example.test', 'bezoekdatum' => '2026-09-01', 'hoe_kent_u_geofort' => 'Online', 'cjp_korting' => 0,
    'schooltype' => 'Primair Onderwijs', 'programma_duur' => 'dag', 'niveau1' => 'regulier', 'niveau2' => '', 'niveau3' => '',
    'leeftijdsgroep1' => 'Groep 5', 'leeftijdsgroep2' => 'Groep 6', 'leeftijdsgroep3' => '', 'leeftijdsgroep4' => '', 'leeftijdsgroep5' => '',
    'keuze_module' => 'Earth-Watch', 'aantal_leerlingen' => 30, 'aantal_begeleiders' => 3,
    'remise_break' => 0, 'kazerne_break' => 0, 'fortgracht_break' => 0, 'waterijsje' => 0, 'glas_limonade' => 0,
    'remise_lunch' => 0, 'eigen_picknick' => 0, 'opmerkingen' => "Regel 1\r\nRegel 2",
];

$po = $mapper->map($base);
$assert($po['errors'] === [], 'PO regulier hoort geldig te zijn.');
$assert(array_column($po['selections'], 'group_key') === ['groep5', 'groep6'], 'PO-groepsvolgorde klopt niet.');
$assert($po['booking']['opmerkingen'] === "Regel 1\nRegel 2", 'Multiline opmerkingen zijn niet naar LF genormaliseerd.');

$special = $mapper->map([...$base, 'id' => 2, 'niveau1' => 'speciaal', 'leeftijdsgroep2' => '']);
$assert($special['selections'][0]['level_key'] === 'speciaal', 'PO speciaal wordt niet gemapt.');

$morning = $mapper->map([...$base, 'id' => 3, 'programma_duur' => 'ochtend', 'keuze_module' => 'Standaard-Ochtend-Programma-PO']);
$assert($morning['booking']['keuzemodule_key'] === null, 'Ochtendstandaard moet NULL worden.');

$currentStandard = $mapper->map([...$base, 'id' => 4, 'schooltype' => 'Voortgezet Onderwijs bovenbouw', 'niveau1' => 'HAVO', 'leeftijdsgroep1' => 'HAVO 4', 'leeftijdsgroep2' => '', 'keuze_module' => 'Stop-de-Klimaat-Klok']);
$assert($currentStandard['errors'] === [], 'Een historische keuzemodule die tegenwoordig standaard is mag niet blokkeren.');
$assert(str_contains(implode(' ', $currentStandard['warnings']), 'Historische keuzemodule is tegenwoordig een standaardmodule; waarde behouden: legacy-ID 4, sector voortgezetBovenbouw, programma dag, module Stop-de-Klimaat-Klok'), 'Een tegenwoordig standaard geworden keuzemodule moet gericht waarschuwen.');

$differentSector = $mapper->map([...$base, 'id' => 5, 'schooltype' => 'Voortgezet Onderwijs onderbouw', 'niveau1' => 'HAVO', 'leeftijdsgroep1' => 'HAVO 1', 'leeftijdsgroep2' => '', 'keuze_module' => 'Minecraft-Klimaatspeurtocht']);
$assert($differentSector['errors'] === [], 'Een bekende module uit een historisch afwijkende sector mag niet blokkeren.');
$assert(str_contains(implode(' ', $differentSector['warnings']), 'Historische module past niet binnen de huidige sector/programmaconfiguratie; waarde behouden: legacy-ID 5, sector voortgezetOnderbouw, programma dag, module Minecraft-Klimaatspeurtocht'), 'Een bekende module buiten de huidige combinatie moet gericht waarschuwen.');
$assert($differentSector['booking']['keuzemodule_key'] === 'Minecraft-Klimaatspeurtocht', 'Een bekende afwijkende module moet exact worden opgeslagen.');

$unknownModule = $mapper->map([...$base, 'id' => 6, 'keuze_module' => 'Volledig-Onbekende-Module']);
$assert(in_array('Onbekende keuzemodule: Volledig-Onbekende-Module', $unknownModule['errors'], true), 'Een volledig onbekende module moet blokkeren.');

$voCases = [
    ['Voortgezet Onderwijs onderbouw', 'VMBO Basis Kader', 'VMBO 1, VMBO 2', 'vmboBasisKader', ['vmbo1', 'vmbo2']],
    ['Voortgezet Onderwijs onderbouw', 'VMBO Gemengd Theoretisch', 'VMBO 3', 'vmboGemengdTheoretisch', ['vmbo3']],
    ['Voortgezet Onderwijs onderbouw', 'HAVO', 'HAVO 1', 'havo', ['havo1']],
    ['Voortgezet Onderwijs onderbouw', 'VWO', 'Atheneum 1', 'vwo', ['atheneum1']],
    ['Voortgezet Onderwijs onderbouw', 'VWO', 'Gymnasium 2', 'vwo', ['gymnasium2']],
    ['Voortgezet Onderwijs onderbouw', 'Praktijk Onderwijs', 'Praktijk 3', 'praktijkOnderwijs', ['praktijk3']],
    ['Voortgezet Onderwijs bovenbouw', 'VMBO_GL_TL', 'VMBO 4', 'vmbo', ['vmbo4']],
];
foreach ($voCases as $index => [$schooltype, $level, $groups, $expectedLevel, $expectedGroups]) {
    $result = $mapper->map([...$base, 'id' => 10 + $index, 'schooltype' => $schooltype, 'niveau1' => $level, 'leeftijdsgroep1' => $groups, 'leeftijdsgroep2' => '', 'keuze_module' => $schooltype === 'Voortgezet Onderwijs bovenbouw' ? 'Crisismanagement' : 'Klimparcours']);
    $assert($result['errors'] === [], "VO-case {$index} hoort geldig te zijn: " . implode('; ', $result['errors']));
    $assert(($result['selections'][0]['level_key'] ?? null) === $expectedLevel, "VO-level {$index} klopt niet.");
    $assert(array_column($result['selections'], 'group_key') === $expectedGroups, "VO-groepen {$index} kloppen niet.");
}

$unknownLevel = $mapper->map([...$base, 'id' => 30, 'schooltype' => 'Voortgezet Onderwijs onderbouw', 'niveau1' => 'VMBO', 'leeftijdsgroep1' => 'VMBO 1', 'keuze_module' => 'Klimparcours']);
$assert($unknownLevel['errors'] !== [], 'Generiek onderbouw-VMBO moet blokkeren.');
$unknownGroup = $mapper->map([...$base, 'id' => 31, 'leeftijdsgroep1' => 'Groep 4', 'leeftijdsgroep2' => '']);
$assert($unknownGroup['errors'] !== [], 'Onbekende groep moet blokkeren.');
$html = $mapper->map([...$base, 'id' => 32, 'schoolnaam' => '&#039;t Bussche Kempke']);
$assert($html['booking']['schoolnaam'] === "'t Bussche Kempke" && $html['has_html_entities'], 'HTML-entiteiten worden niet correct gedecodeerd.');

$longComment = str_repeat('x', 601);
$commentResult = $mapper->map([...$base, 'id' => 33, 'opmerkingen' => $longComment]);
$assert($commentResult['errors'] === [], 'Een opmerking van 601 tekens mag niet door de formulierlimiet blokkeren.');
$assert($commentResult['booking']['opmerkingen'] === $longComment, 'Een opmerking van 601 tekens wordt niet volledig behouden.');
$assert(str_contains(implode(' ', $commentResult['warnings']), '600'), 'Een opmerking van 601 tekens moet waarschuwen.');

$longMultiline = str_repeat("regel\r\n", 110);
$multilineResult = $mapper->map([...$base, 'id' => 34, 'opmerkingen' => $longMultiline]);
$assert(!str_contains((string) $multilineResult['booking']['opmerkingen'], "\r"), 'Lange multiline-opmerking is niet naar LF genormaliseerd.');
$assert(mb_strlen((string) $multilineResult['booking']['opmerkingen'], 'UTF-8') > 600, 'Testopmerking hoort langer dan 600 tekens te blijven.');

$longDiscovery = str_repeat('d', 121);
$discoveryResult = $mapper->map([...$base, 'id' => 35, 'hoe_kent_u_geofort' => $longDiscovery]);
$assert($discoveryResult['errors'] === [], 'Discovery van 121 tekens mag niet door de formulierlimiet blokkeren.');
$assert($discoveryResult['booking']['hoe_kent_u_geofort'] === $longDiscovery, 'Discovery van 121 tekens wordt niet volledig behouden.');
$capacityErrors = LegacyBookingMapper::validateTargetCharacterCapacities($discoveryResult['booking'], ['opmerkingen' => 65_535, 'hoe_kent_u_geofort' => 255]);
$assert($capacityErrors === [], 'Formulierlimieten worden ten onrechte als databasecapaciteit gebruikt.');
$tooLongErrors = LegacyBookingMapper::validateTargetCharacterCapacities([...$discoveryResult['booking'], 'hoe_kent_u_geofort' => str_repeat('d', 256)], ['opmerkingen' => 65_535, 'hoe_kent_u_geofort' => 255]);
$assert($tooLongErrors !== [], 'Waarde boven werkelijke doelkolomlengte moet blokkeren.');

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "OK: " . (10 + count($voCases) + 8) . " mapperchecks geslaagd.\n");
