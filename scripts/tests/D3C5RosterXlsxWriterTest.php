<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$column = static function (int $number): string {
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + $number % 26) . $name;
        $number = intdiv($number, 26);
    }
    return $name;
};
$coordinate = static function (string $reference): array {
    if (preg_match('/^([A-Z]+)([1-9][0-9]*)$/', $reference, $matches) !== 1) throw new RuntimeException('Bad cell reference: ' . $reference);
    $number = 0;
    foreach (str_split($matches[1]) as $character) $number = $number * 26 + ord($character) - 64;
    return [$number, (int) $matches[2]];
};
$readXml = static function (ZipArchive $zip, string $entry) use ($assert): DOMXPath {
    $text = $zip->getFromName($entry);
    $assert(is_string($text), 'Missing ZIP entry: ' . $entry);
    $document = new DOMDocument();
    $assert($document->loadXML($text, LIBXML_NONET), 'Malformed XML: ' . $entry);
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
    return $xpath;
};
$attribute = static fn (DOMXPath $xpath, string $expression): string => $xpath->evaluate('string(' . $expression . ')');
$palette = [
    'Dynamische Globe' => 'FFCFE2F3', 'Earth Watch' => 'FFD9EAD3',
    'Stop de Klimaat Klok' => 'FFF4CCCC', 'Klimaat Experience' => 'FFFCE5CD',
    'Voedsel Innovatie' => 'FFEADCF8',
];
$plans = require __DIR__ . '/fixtures/RosterXlsxPlans.php';
$writer = new RosterXlsxWriter();
foreach ($plans as $fixture => $plan) {
    $before = serialize($plan);
    $path = tempnam(sys_get_temp_dir(), 'geofort-export-regression-');
    if ($path === false) throw new RuntimeException('Cannot create temporary export.');
    try {
        $writer->write($path, $plan);
        $assert(serialize($plan) === $before, $fixture . ': exporter mutated input');
        $zip = new ZipArchive();
        $assert($zip->open($path, ZipArchive::CHECKCONS) === true, $fixture . ': corrupt ZIP');
        try {
            for ($entry = 0; $entry < $zip->numFiles; $entry++) {
                $readXml($zip, (string) $zip->getNameIndex($entry));
            }
            $workbook = $readXml($zip, 'xl/workbook.xml');
            $styles = $readXml($zip, 'xl/styles.xml');
            $relations = $readXml($zip, 'xl/_rels/workbook.xml.rels');
            foreach ($relations->query('/r:Relationships/r:Relationship') as $relation) {
                $assert($zip->locateName('xl/' . $relation->getAttribute('Target')) !== false, $fixture . ': broken workbook relationship');
            }
            $sheetNames = array_map(static fn (DOMElement $node): string => $node->getAttribute('name'), iterator_to_array($workbook->query('/s:workbook/s:sheets/s:sheet')));
            $assert($sheetNames === ['Printrooster', 'Dagrooster', 'Docentrooster'], $fixture . ': exact sheet order');
            foreach (['fonts' => 'font', 'fills' => 'fill', 'borders' => 'border', 'cellXfs' => 'xf'] as $collection => $item) {
                $assert((int) $attribute($styles, '/s:styleSheet/s:' . $collection . '/@count') === $styles->query('/s:styleSheet/s:' . $collection . '/s:' . $item)->length, 'Incorrect style count: ' . $collection);
            }
            $styleCache = [];
            foreach ($styles->query('/s:styleSheet/s:cellXfs/s:xf') as $id => $xf) {
                $font = (int) $xf->getAttribute('fontId') + 1;
                $fill = (int) $xf->getAttribute('fillId') + 1;
                $border = (int) $xf->getAttribute('borderId') + 1;
                $fontPath = '/s:styleSheet/s:fonts/s:font[' . $font . ']';
                $alignment = $xf->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'alignment')->item(0);
                $edges = [];
                foreach (['left', 'right', 'top', 'bottom'] as $side) {
                    $borderPath = '/s:styleSheet/s:borders/s:border[' . $border . ']/s:' . $side;
                    $edges[$side] = [
                        'style' => $attribute($styles, $borderPath . '/@style'),
                        'color' => $attribute($styles, $borderPath . '/s:color/@rgb'),
                    ];
                }
                $styleCache[$id] = [
                    'bold' => $styles->query($fontPath . '/s:b[not(@val="0") and not(@val="false")]')->length > 0,
                    'font' => $attribute($styles, $fontPath . '/s:name/@val'),
                    'size' => $attribute($styles, $fontPath . '/s:sz/@val'),
                    'color' => $attribute($styles, $fontPath . '/s:color/@rgb'),
                    'fill' => $attribute($styles, '/s:styleSheet/s:fills/s:fill[' . $fill . ']/s:patternFill/s:fgColor/@rgb'),
                    'horizontal' => $alignment?->getAttribute('horizontal') ?? '',
                    'vertical' => $alignment?->getAttribute('vertical') ?? '',
                    'wrap' => $alignment?->getAttribute('wrapText') ?? '',
                    'edges' => $edges,
                ];
            }
            foreach ($sheetNames as $sheetIndex => $sheetName) {
                $label = $fixture . '/' . $sheetName;
                $sheet = $readXml($zip, 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml');
                $cells = [];
                $heights = [];
                $maxColumn = 0;
                $maxRow = 0;
                foreach ($sheet->query('/s:worksheet/s:sheetData/s:row') as $row) {
                    $heights[(int) $row->getAttribute('r')] = (float) $row->getAttribute('ht');
                    $maxRow = max($maxRow, (int) $row->getAttribute('r'));
                    foreach ($row->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c') as $node) {
                        $reference = $node->getAttribute('r');
                        [$col, $rowNumber] = $coordinate($reference);
                        $assert(!isset($cells[$reference]), $label . ': duplicate cell');
                        $maxColumn = max($maxColumn, $col);
                        $value = str_replace("\u{00A0}", ' ', $sheet->evaluate('string(s:is/s:t)', $node));
                        $style = $styleCache[(int) $node->getAttribute('s')] ?? null;
                        $assert($style !== null, $label . ': invalid style reference');
                        $cells[$reference] = ['value' => $value, ...$style];
                        $assert($node->getAttribute('t') === 'inlineStr', $label . ': text/formula safety');
                        $assert($node->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'f')->length === 0, $label . ': formula in exported text');
                        if ($value !== '') {
                            $assert(!$style['bold'] && $style['font'] === 'Calibri' && $style['size'] === '10', $label . ': visible text must be Calibri 10, regular');
                            $assert($style['horizontal'] === 'center' && $style['vertical'] === 'center' && $style['wrap'] === '1', $label . ': visible text alignment');
                            $assert(!in_array($value, ['Roostertype', 'Status', 'Roosterstatus', 'Revisie'], true), $label . ': hidden metadata still visible');
                        }
                    }
                }
                $assert($sheet->query('//s:rPr/s:b[not(@val="0") and not(@val="false")]')->length === 0, $label . ': bold rich text');
                $assert($maxColumn >= 12, $label . ': information uses at least twelve columns');
                $zoneSize = intdiv($maxColumn, 3);
                $zones = [[1, $zoneSize], [$zoneSize + 1, 2 * $zoneSize], [2 * $zoneSize + 1, $maxColumn]];
                foreach (['Bezoek & onderwijsprogramma', 'School- en contactgegevens', 'Begeleiding'] as $index => $heading) {
                    $assert($cells[$column($zones[$index][0]) . '1']['value'] === $heading, $label . ': information heading');
                }
                foreach ([2 => ['Datum', $plan['visitDate']], 3 => ['Programma', $plan['education']['programLabel']],
                    4 => ['Sector', $plan['education']['sectorLabel']], 5 => ['Niveau(s)', 'Groep 7 · Groep 8'],
                    6 => ['Keuzemodule', $plan['education']['choiceModuleLabel']],
                    7 => ['Leerlingen', (string) $plan['education']['studentCount']], 8 => ['Groepen', (string) count($plan['groups'])]] as $row => [$key, $value]) {
                    $assert($cells['A' . $row]['value'] === $key && $cells['C' . $row]['value'] === $value, $label . ': metadata ' . $key);
                }
                $assert(!in_array('Rol', array_column($cells, 'value'), true), $label . ': obsolete role section');

                $titles = [];
                foreach ($cells as $reference => $cell) {
                    if (in_array($cell['value'], ['Lesrooster', 'Docentenrooster'], true)) {
                        [$col, $row] = $coordinate($reference);
                        $assert($col === 1, $label . ': table starts in column A');
                        $titles[$cell['value']] = $row;
                    }
                }
                $assert(array_keys($titles) === match ($sheetIndex) { 0 => ['Lesrooster', 'Docentenrooster'], 1 => ['Lesrooster'], 2 => ['Docentenrooster'] }, $label . ': table order');
                $firstTitle = min($titles);
                $infoEnd = $firstTitle - 2;
                $assert($infoEnd >= 7, $label . ': information height');
                $assert(!isset($cells['A' . ($firstTitle - 1)]), $label . ': spacer is outside block outlines');
                $outlines = [[1, $infoEnd, $maxColumn]];
                $expectedMerges = [];
                foreach ($zones as $index => [$left, $right]) {
                    $expectedMerges[] = $column($left) . '1:' . $column($right) . '1';
                    for ($row = 2; $row <= $infoEnd; $row++) {
                        $ranges = $index === 2 ? [[$left, $right]] : [[$left, $left + 1], [$left + 2, $right]];
                        foreach ($ranges as [$start, $end]) $expectedMerges[] = $column($start) . $row . ':' . $column($end) . $row;
                    }
                }
                $rounds = $plan['planning']['rounds'];
                foreach ($titles as $title => $top) {
                    $header = $top + 1;
                    $width = 0;
                    foreach ($cells as $reference => $cell) {
                        [$col, $row] = $coordinate($reference);
                        if ($row === $header) $width = max($width, $col);
                    }
                    $assert($cells['A' . $header]['value'] === 'Begintijd' && $cells['B' . $header]['value'] === 'Eindtijd', $label . ': separate time columns');
                    if ($title === 'Lesrooster') {
                        $assert($width === count($plan['groups']) + 2, $label . ': group table width');
                        foreach ($plan['groups'] as $index => $group) $assert($cells[$column($index + 3) . $header]['value'] === $group['label'], $label . ': group order');
                    } else {
                        $teacherHeaders = [];
                        for ($col = 3; $col <= $width; $col++) $teacherHeaders[] = $cells[$column($col) . $header]['value'];
                        foreach ($plan['planning']['staffCatalog'] as $member) {
                            if (in_array($member['id'], $plan['planning']['selectedStaffIds'], true)) {
                                $assert(in_array($member['name'], $teacherHeaders, true), $label . ': selected/free teacher missing');
                            }
                        }
                        if ($fixture === 'modules-3') $assert($width === 6, $label . ': four teachers for six groups');
                        if ($fixture === 'modules-4' || $fixture === 'long-multiple-free') $assert($width === 11, $label . ': nine teachers for four groups');
                        if ($fixture === 'no-staff') $assert($width === 2, $label . ': no invented teacher');
                    }
                    $bottom = $header + count($rounds);
                    $outlines[] = [$top, $bottom, $width];
                    $expectedMerges[] = 'A' . $top . ':' . $column($width) . $top;
                    $assert($cells['A' . $top]['fill'] === 'FFF1F3F5' && $heights[$top] === 22.0, $label . ': title style');
                    for ($col = 1; $col <= $width; $col++) {
                        $headCell = $cells[$column($col) . $header];
                        $assert($headCell['fill'] === 'FF737373' && $headCell['color'] === 'FFFFFFFF', $label . ': header palette');
                        foreach (['top', 'bottom'] as $edge) $assert($headCell['edges'][$edge]['style'] === 'medium', $label . ': medium header edge');
                        if ($col > 1) $assert($headCell['edges']['left']['style'] === 'medium', $label . ': medium header vertical edge');
                    }
                    foreach ($rounds as $index => $round) {
                        $row = $header + $index + 1;
                        $assert($cells['A' . $row]['value'] === $round['startTime'] && $cells['B' . $row]['value'] === $round['endTime'], $label . ': full original timeline, exact times/order');
                        $neutral = $round['type'] !== 'round';
                        $assert($cells['A' . $row]['fill'] === ($neutral ? 'FFFFFFFF' : 'FFF7E6D5') && $cells['B' . $row]['fill'] === $cells['A' . $row]['fill'], $label . ': both time fills');
                        for ($col = 3; $col <= $width; $col++) {
                            $cell = $cells[$column($col) . $row];
                            if ($neutral) {
                                $assert($cell['value'] === $round['label'] && $cell['fill'] === 'FFFFFFFF', $label . ': separate white neutral cells');
                            } elseif ($title === 'Lesrooster') {
                                $groupId = $plan['groups'][$col - 3]['id'];
                                $matches = array_values(array_filter($plan['planning']['sessions'], static fn (array $session): bool =>
                                    $session['startTime'] === $round['startTime'] && $session['endTime'] === $round['endTime'] && in_array($groupId, $session['groupIds'], true)));
                                $expected = $matches[0]['moduleLabel'] ?? '';
                                $assert($cell['value'] === $expected, $label . ': exact module label without location or staff');
                                $assert($cell['fill'] === ($expected === '' ? 'FFFFFFFF' : ($palette[$expected] ?? 'FFD9EAF7')), $label . ': unchanged export module palette');
                                $assert($cell['color'] === 'FF000000', $label . ': unchanged module text color');
                            } elseif ($cell['value'] !== '') {
                                $module = explode("\n", $cell['value'])[0];
                                $assert($cell['fill'] === ($palette[$module] ?? 'FFD9EAF7'), $label . ': teacher module palette');
                            }
                            if ($col === 3) $assert($cell['edges']['left']['style'] === 'medium', $label . ': time/table divider');
                            elseif ($col < $width) $assert($cell['edges']['left']['style'] === 'thin', $label . ': thin interior lines');
                            if ($row < $bottom) $assert($cell['edges']['bottom']['style'] === 'thin', $label . ': thin horizontal lines');
                        }
                        if ($width > 2) $assert($cells['B' . $row]['edges']['right']['style'] === 'medium', $label . ': time divider');
                        if (str_starts_with($fixture, 'modules-')) $assert($heights[$row] === ($neutral ? 24.0 : 30.0), $label . ': ordinary row heights');
                    }
                    if ($title === 'Docentenrooster' && $fixture === 'long-multiple-free') {
                        $row = $header + 2;
                        $a = array_search($plan['planning']['staffCatalog'][0]['name'], $teacherHeaders, true);
                        $b = array_search($plan['planning']['staffCatalog'][1]['name'], $teacherHeaders, true);
                        $assert($cells[$column($a + 3) . $row]['value'] === "Dynamische Globe\n(" . $plan['groups'][0]['label'] . ') (Groep 2)', $label . ': one teacher, multiple groups');
                        $assert($cells[$column($b + 3) . $row]['value'] === "Dynamische Globe\n(Groep 2)", $label . ': group-specific second teacher');
                        $assert($heights[$row] > 30.0, $label . ': multiline group list must grow');
                        for ($col = 3; $col <= $width; $col++) $assert($cells[$column($col) . ($bottom - 1)]['value'] === '', $label . ': free round preserved');
                    }
                    if ($title === 'Docentenrooster' && $fixture === 'literal-labels-school-open') {
                        $assert(in_array('School', $teacherHeaders, true) && in_array('OPEN', $teacherHeaders, true), $label . ': school/open columns preserved');
                        $assert($cells[$column(array_search('School', $teacherHeaders, true) + 3) . ($header + 1)]['value'] === "Klimaat Experience\n(Groep 4)", $label . ': school assignment');
                    }
                }
                $assert($maxRow === max(array_column($outlines, 1)), $label . ': no invented timeline rows');
                foreach ($outlines as [$top, $bottom, $width]) {
                    for ($row = $top; $row <= $bottom; $row++) {
                        for ($col = 1; $col <= $width; $col++) {
                            $cell = $cells[$column($col) . $row];
                            foreach (['left', 'right', 'top', 'bottom'] as $edge) {
                                $isOuter = ($edge === 'left' && $col === 1) || ($edge === 'right' && $col === $width)
                                    || ($edge === 'top' && $row === $top) || ($edge === 'bottom' && $row === $bottom);
                                if ($isOuter) $assert($cell['edges'][$edge] === ['style' => 'thick', 'color' => 'FF000000'], $label . ': continuous thick black outline ' . $column($col) . $row . '/' . $edge);
                                elseif ($cell['edges'][$edge]['style'] !== '') $assert($cell['edges'][$edge]['color'] === 'FF000000', $label . ': black inner border');
                            }
                        }
                    }
                }
                $actualMerges = [];
                $covered = [];
                foreach ($sheet->query('/s:worksheet/s:mergeCells/s:mergeCell') as $merge) {
                    $range = $merge->getAttribute('ref');
                    $actualMerges[] = $range;
                    [$start, $end] = explode(':', $range);
                    [$left, $top] = $coordinate($start);
                    [$right, $bottom] = $coordinate($end);
                    $assert($right > $left && $top === $bottom && $right <= $maxColumn && $bottom <= $maxRow, $label . ': valid merge range');
                    for ($col = $left; $col <= $right; $col++) {
                        $reference = $column($col) . $top;
                        $assert(!isset($covered[$reference]), $label . ': overlapping merge');
                        $covered[$reference] = true;
                        if ($col !== $left) $assert($cells[$reference]['value'] === '', $label . ': merge hides data');
                    }
                }
                sort($actualMerges);
                sort($expectedMerges);
                $assert($actualMerges === $expectedMerges, $label . ': only info values and titles merged');
                $printPrefix = '/s:workbook/s:definedNames/s:definedName[@localSheetId="' . $sheetIndex . '"]';
                $assert($attribute($workbook, $printPrefix . '[@name="_xlnm.Print_Area"]') === "'" . $sheetName . "'!" . '$A$1:$' . $column($maxColumn) . '$' . $maxRow, $label . ': print area includes entire info/role list and tables');
                $expectedRepeat = $sheetIndex === 0 ? '' : "'" . $sheetName . "'!$" . $firstTitle . ':$' . ($firstTitle + 1);
                $assert($attribute($workbook, $printPrefix . '[@name="_xlnm.Print_Titles"]') === $expectedRepeat, $label . ': correct repeated titles');
                $assert($attribute($sheet, '/s:worksheet/s:dimension/@ref') === 'A1:' . $column($maxColumn) . $maxRow, $label . ': worksheet dimension');
                foreach (['orientation' => 'landscape', 'paperSize' => '9', 'fitToWidth' => '1', 'fitToHeight' => '0'] as $key => $value) {
                    $assert($attribute($sheet, '/s:worksheet/s:pageSetup/@' . $key) === $value, $label . ': page setup ' . $key);
                }
                foreach (['left' => 0.3, 'right' => 0.3, 'top' => 0.35, 'bottom' => 0.35, 'header' => 0.15, 'footer' => 0.15] as $key => $value) {
                    $assert((float) $attribute($sheet, '/s:worksheet/s:pageMargins/@' . $key) === $value, $label . ': margin ' . $key);
                }
                $assert($attribute($sheet, '/s:worksheet/s:printOptions/@horizontalCentered') === '1' && $attribute($sheet, '/s:worksheet/s:sheetPr/s:pageSetUpPr/@fitToPage') === '1', $label . ': fit and center printing');
                foreach ($sheet->query('/s:worksheet/s:cols/s:col') as $definition) {
                    $col = (int) $definition->getAttribute('min');
                    $assert((float) $definition->getAttribute('width') === ($col <= 2 ? 11.5 : 24.0), $label . ': column width');
                }
                if ($fixture === 'modules-4' || $fixture === 'long-multiple-free') {
                    $assert($infoEnd === 10, $label . ': nine teachers determine info height');
                    for ($row = 2; $row <= 10; $row++) $assert($cells[$column($zones[2][0]) . $row]['value'] !== '', $label . ': full selected teacher list');
                }
            }
        } finally {
            $zip->close();
        }
    } finally {
        @unlink($path);
    }
    fwrite(STDOUT, 'OK: ' . $fixture . " (3 worksheets, input unchanged).\n");
}
fwrite(STDOUT, 'OK: roster XLSX regression passed (' . count($plans) . ' fixtures, ' . $checks . " checks).\n");
