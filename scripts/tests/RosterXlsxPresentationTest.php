<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;

$assert = static function (bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); };
$cases = json_decode(file_get_contents(__DIR__ . '/fixtures/RosterPresentationCases.json'), true, 128, JSON_THROW_ON_ERROR);
$path = tempnam(sys_get_temp_dir(), 'roster-round-a-xlsx-');
$column = static function (string $reference): int {
    $value = 0;
    foreach (str_split(preg_replace('/[0-9]/', '', $reference)) as $letter) $value = $value * 26 + ord($letter) - 64;
    return $value;
};
try {
    foreach ($cases as $fixture) foreach (['po', 'vo', 'missing'] as $variant) {
        $school = ['name' => 'Één & twee academie', 'city' => 'Voorbeeldstad', 'address' => 'Een bijzonder lange straatnaam met huisnummer 123 en toevoeging B',
            'postalCode' => '1234 AB', 'country' => $variant === 'vo' ? 'België' : 'Nederland',
            'contactName' => 'Sam Voorbeeld', 'contactEmail' => 'lang.contactadres.voor.de.roosterplanner@example.test',
            'schoolPhone' => '0612345678', 'contactPhone' => '0612345678'];
        $education = ['programLabel' => 'Dagprogramma', 'sectorLabel' => $variant === 'vo' ? 'Voortgezet onderwijs onderbouw' : 'Primair onderwijs',
            'selectionLabels' => $variant === 'vo' ? ['VMBO 1', 'HAVO 1', 'Atheneum 1'] : ['Groep 7', 'Groep 8'],
            'choiceModuleLabel' => 'Minecraft Klimaatspeurtocht', 'studentCount' => 84];
        if ($variant === 'missing') {
            $school = ['name' => 'Zelfstandig rooster', 'city' => ''];
            $education = ['programLabel' => 'Dagprogramma', 'studentCount' => 84];
        }
        $rounds = [];
        foreach ($fixture['sessions'] as $session) $rounds[$session['startTime'] . '|' . $session['endTime']] = [
            'type' => 'round', 'label' => 'Les', 'startTime' => $session['startTime'], 'endTime' => $session['endTime'],
        ];
        $plan = ['visitDate' => '2028-10-11', 'school' => $school, 'education' => $education, 'groups' => $fixture['groups'],
            'planning' => ['sessions' => $fixture['sessions'], 'rounds' => array_values($rounds),
                'selectedStaffIds' => $fixture['selectedStaffIds'], 'staffCatalog' => $fixture['staffCatalog'],
                'staffingSettings' => ['cookStaffId' => $fixture['cookStaffId']]]];
        $before = serialize($plan);
        (new RosterXlsxWriter())->write($path, $plan);
        $assert(serialize($plan) === $before, 'Export changed cook/guide/source data.');
        $zip = new ZipArchive(); $assert($zip->open($path, ZipArchive::CHECKCONS) === true, 'Invalid XLSX.');
        try {
            for ($sheet = 1; $sheet <= 3; $sheet++) {
                $document = new DOMDocument(); $assert($document->loadXML($zip->getFromName('xl/worksheets/sheet' . $sheet . '.xml'), LIBXML_NONET), 'Invalid worksheet XML.');
                $xpath = new DOMXPath($document); $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $rows = []; $heights = [];
                foreach ($xpath->query('//s:row') as $row) foreach ($row->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $cell) {
                    $assert(!str_contains($cell->textContent, '(Groep '), 'Group token can break between Groep and its number.');
                    $rows[(int) $row->getAttribute('r')][$column($cell->getAttribute('r'))] = str_replace("\u{00A0}", ' ', $cell->textContent);
                    $heights[(int) $row->getAttribute('r')] = (float) $row->getAttribute('ht');
                }
                $firstTitle = null; $teacherTitle = null;
                foreach ($rows as $number => $row) {
                    if (in_array($row[1] ?? '', ['Lesrooster', 'Docentenrooster'], true)) $firstTitle ??= $number;
                    if (($row[1] ?? '') === 'Docentenrooster') $teacherTitle = $number;
                }
                $headers = $rows[1];
                $zoneColumns = array_keys(array_filter($headers, static fn (string $value): bool => $value !== ''));
                $assert(array_values(array_filter($headers)) === ['Bezoek & onderwijsprogramma', 'School- en contactgegevens', 'Begeleiding'], 'Three information sections missing.');
                $metadata = []; $guides = [];
                foreach ($rows as $number => $row) {
                    if ($number <= 1 || $number >= $firstTitle - 1) continue;
                    foreach (array_slice($zoneColumns, 0, 2) as $start) if (($row[$start] ?? '') !== '') $metadata[$row[$start]] = $row[$start + 2];
                    if (($row[$zoneColumns[2]] ?? '') !== '') $guides[] = $row[$zoneColumns[2]];
                }
                $expectedMeta = ['Datum' => '2028-10-11', 'Programma' => 'Dagprogramma', 'Leerlingen' => '84', 'Groepen' => (string) count($fixture['groups']), 'School' => $school['name']];
                if ($variant !== 'missing') $expectedMeta += ['Sector' => $education['sectorLabel'], 'Niveau(s)' => implode(' · ', $education['selectionLabels']),
                    'Keuzemodule' => 'Minecraft Klimaatspeurtocht', 'Adres' => $school['address'], 'Postcode & plaats' => '1234 AB Voorbeeldstad',
                    'Contactpersoon' => 'Sam Voorbeeld', 'E-mailadres' => $school['contactEmail'], 'Schooltelefoon' => '0612345678', 'Telefoon contactpersoon' => '0612345678'];
                if ($variant === 'vo') $expectedMeta['Land'] = 'België';
                ksort($expectedMeta); ksort($metadata);
                $assert($metadata === $expectedMeta, $fixture['name'] . '/' . $variant . ': information field/value mismatch.');
                $expectedGuides = array_column($fixture['staffCatalog'], 'name'); sort($guides); sort($expectedGuides);
                $assert($guides === $expectedGuides && !in_array('School', $guides, true) && !in_array('OPEN', $guides, true), 'Selected/free staff list or pseudo-staff filtering wrong.');
                $assert(!in_array('Rol', $headers, true), 'Role section survived.');
                if ($teacherTitle === null) continue;
                $teacherHeaders = array_slice($rows[$teacherTitle + 1], 2, null, true);
                $staffIds = array_column($fixture['staffCatalog'], 'id', 'name');
                $responsibleByColumn = [];
                foreach ($teacherHeaders as $col => $name) $responsibleByColumn[$col] = match ($name) {
                    'School' => 'school', 'OPEN' => 'open', default => 'staff:' . $staffIds[$name],
                };
                $expectedCells = [];
                foreach ($fixture['expected'] as $item) $expectedCells[$item['time']][$item['responsible']][] = $item['text'];
                $seen = [];
                foreach ($rows as $number => $row) {
                    if ($number <= $teacherTitle + 1) continue;
                    $time = $row[1] . '|' . $row[2];
                    foreach ($responsibleByColumn as $col => $responsible) {
                        $expected = implode("\n\n", $expectedCells[$time][$responsible] ?? []);
                        if ($expected === '' && str_starts_with($responsible, 'staff:')) $expected = 'Vrij';
                        $assert(($row[$col] ?? '') === $expected, $fixture['name'] . '/' . $responsible . '/' . $time . ': source-to-XLSX assignment mismatch.');
                        if (substr_count($expected, '(Groep ') >= 3) $assert($heights[$number] >= 42, 'Three groups need a wrapped third text line without clipping.');
                        if ($expected !== '') $seen[$time][$responsible] = true;
                    }
                }
                foreach ($expectedCells as $time => $people) foreach ($people as $person => $_) $assert(isset($seen[$time][$person]), 'Assigned staff/module column missing.');
            }
        } finally { $zip->close(); }
    }
    // Identical display labels must still use different Minecraft styles, based on immutable keys.
    $minecraft = ['Minecraft-Klimaatspeurtocht' => 'FFD9B5DB', 'Minecraft-Programmeren' => 'FFC7C3EE'];
    $plan = ['visitDate' => '2028-10-10', 'school' => ['name' => 'Minecraft test'], 'education' => [],
        'groups' => [['id' => 1, 'label' => 'Groep 1'], ['id' => 2, 'label' => 'Groep 2']],
        'planning' => ['sessions' => [], 'rounds' => [], 'selectedStaffIds' => [], 'staffCatalog' => []]];
    foreach (array_keys($minecraft) as $i => $key) $plan['planning']['sessions'][] = [
        'moduleKey' => $key, 'moduleLabel' => 'Gelijke historische weergavenaam', 'groupIds' => [$i + 1],
        'startTime' => '10:15', 'endTime' => '11:00', 'staffAssignments' => [],
    ];
    (new RosterXlsxWriter())->write($path, $plan);
    $zip = new ZipArchive(); $assert($zip->open($path, ZipArchive::CHECKCONS) === true, 'Invalid Minecraft XLSX');
    try {
        $styles = new DOMDocument(); $styles->loadXML($zip->getFromName('xl/styles.xml'), LIBXML_NONET);
        $styleQuery = new DOMXPath($styles); $styleQuery->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ([1, 2] as $sheet) {
            $xml = new DOMDocument(); $xml->loadXML($zip->getFromName('xl/worksheets/sheet' . $sheet . '.xml'), LIBXML_NONET);
            $query = new DOMXPath($xml); $query->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $colors = [];
            foreach ($query->query('//s:c[s:is/s:t="Gelijke historische weergavenaam"]') as $cell) {
                $styleIndex = (int) $cell->getAttribute('s') + 1;
                $fillId = (int) $styleQuery->evaluate('string(/s:styleSheet/s:cellXfs/s:xf[' . $styleIndex . ']/@fillId)') + 1;
                $colors[] = $styleQuery->evaluate('string(/s:styleSheet/s:fills/s:fill[' . $fillId . ']/s:patternFill/s:fgColor/@rgb)');
            }
            $assert($colors === array_values($minecraft), 'Minecraft XLSX styling relies on label instead of exact key');
        }
    } finally { $zip->close(); }
    echo "OK: generated XLSX mappings, information and separate Minecraft styles driven by exact module keys.\n";
} finally { if (is_file($path)) unlink($path); }
