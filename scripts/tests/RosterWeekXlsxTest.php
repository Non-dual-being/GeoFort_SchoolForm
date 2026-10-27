<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Roster\{RosterWeekExportService, RosterXlsxWriter, RosterPlanException};

$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert(RosterWeekExportService::monday('2020-W53')->format('Y-m-d') === '2020-12-28', 'ISO year boundary');
foreach (['2021-W53', '2027-W00', '2027-W54', '2027-02', '../../x'] as $week) {
    try { RosterWeekExportService::monday($week); throw new RuntimeException('Invalid week accepted'); }
    catch (RosterPlanException $error) { $assert($error->httpStatus === 422, 'Week validation'); }
}
$fixtures = require __DIR__ . '/fixtures/RosterXlsxPlans.php';
$plans = [];
foreach (['primairOnderwijs', 'voortgezetOnderbouw', 'voortgezetBovenbouw'] as $index => $sector) {
    $plan = $fixtures['modules-5'];
    $plan['id'] = $index + 1; $plan['bookingId'] = $index + 1; $plan['planningMode'] = 'booking_locked';
    $plan['visitDate'] = $index < 2 ? '2027-01-11' : '2027-01-13';
    $plan['school']['name'] = $index === 0 ? '=1+1 & Testschool' : 'Testschool ' . $index;
    $plan['education']['sector'] = $sector; $plan['education']['studentCount'] = 40 + $index;
    $plan['education']['programVersion'] = $index === 0 ? 'day-four-2027' : null;
    $plan['planning']['staffCatalog'][] = ['id' => 888, 'name' => 'Niet ingeroosterd', 'canCook' => false];
    $plan['planning']['selectedStaffIds'][] = 888;
    $plan['planning']['staffCatalog'][] = ['id' => 999, 'name' => 'Testkok', 'canCook' => true];
    $plan['planning']['staffingSettings']['cookStaffId'] = 999;
    $plans[] = $plan;
}
$file = tempnam(sys_get_temp_dir(), 'week-test-');
try {
    (new RosterXlsxWriter())->writeWeek($file, ['week' => '2027-W02', 'startDate' => '2027-01-11', 'plans' => $plans]);
    $zip = new ZipArchive(); $assert($zip->open($file) === true, 'Invalid zip');
    $parse = static function(string $xml): SimpleXMLElement { $result = simplexml_load_string($xml); if (!$result) throw new RuntimeException('Invalid XML'); $result->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'); return $result; };
    $workbook = $parse($zip->getFromName('xl/workbook.xml'));
    $assert(count($workbook->xpath('//s:sheet')) === 6, 'Overview plus five weekdays');
    $sheetNames = array_map(static fn(SimpleXMLElement $sheet): string => (string)$sheet['name'], $workbook->xpath('//s:sheet'));
    $assert($sheetNames === ['Overzicht', 'maandag 11 januari 2027', 'dinsdag 12 januari 2027', 'woensdag 13 januari 2027', 'donderdag 14 januari 2027', 'vrijdag 15 januari 2027'], 'Tab names must include the full Dutch date, including empty days');
    $sheetNumbers = array_flip($sheetNames);
    $overviewXml = $zip->getFromName('xl/worksheets/sheet1.xml'); $overview = $parse($overviewXml);
    $assert((string) $overview->xpath('//s:c[@r="B5"]/s:v')[0] === '3', 'Schools total numeric');
    $assert((string) $overview->xpath('//s:c[@r="C5"]/s:v')[0] === '123', 'Pupils total numeric');
    $assert(str_contains($overviewXml, 'vier modules') && str_contains($overviewXml, 'vijf modules'), 'Variants');
    $assert(str_contains($overviewXml, 'Testkok') && !str_contains($overviewXml, 'Niet ingeroosterd'), 'Effective staff only, including cook');
    $assert(!str_contains($overviewXml, '<f>') && str_contains($overviewXml, '=1+1 &amp; Testschool'), 'Text cannot become a formula');
    $links = $overview->xpath('//s:hyperlink'); $assert(count($links) === 3, 'Every school linked');
    $assert((string)$links[0]['location'] !== (string)$links[1]['location'], 'Same-day rosters have distinct anchors');
    foreach ($links as $index => $link) {
        preg_match("/'(.*?)'!A(\d+)/", (string)$link['location'], $match);
        $assert(isset($sheetNumbers[$match[1]]), 'Internal link targets an existing fully dated tab');
        $sheetNumber = $sheetNumbers[$match[1]] + 1;
        $sheet = $parse($zip->getFromName("xl/worksheets/sheet{$sheetNumber}.xml"));
        $heading = (string)$sheet->xpath('//s:c[@r="A' . $match[2] . '"]/s:is/s:t')[0];
        $assert(str_contains($heading, $plans[$index]['school']['name']), 'Link lands at correct school');
    }
    $assert(str_contains($zip->getFromName('xl/worksheets/sheet3.xml'), 'Geen roosters'), 'Empty Tuesday present');
    $assert(!str_contains($overviewXml, 'Geen roosters') && !str_contains($overviewXml, 'dinsdag 12 januari 2027'), 'Empty days omitted from overview');
    $assert(str_contains($overviewXml, 'maandag 11 januari 2027') && str_contains($overviewXml, 'woensdag 13 januari 2027'), 'Full Dutch dates on overview');
    $assert(str_contains($zip->getFromName('xl/worksheets/sheet3.xml'), 'dinsdag 12 januari 2027'), 'Full Dutch date on empty individual day');
    for ($i = 0; $i < $zip->numFiles; $i++) if (str_ends_with($zip->getNameIndex($i), '.xml')) $parse($zip->getFromIndex($i));
    $zip->close();
    if ($path = getenv('WEEK_XLSX_SAMPLE')) copy($file, $path);
    foreach (['2026-W41' => 'maandag 5 oktober 2026', '2020-W53' => 'vrijdag 1 januari 2021'] as $week => $expectedName) {
        (new RosterXlsxWriter())->writeWeek($file, ['week' => $week, 'startDate' => RosterWeekExportService::monday($week)->format('Y-m-d'), 'plans' => []]);
        $assert($zip->open($file) === true, 'Date fixture zip');
        $dateSheets = $parse($zip->getFromName('xl/workbook.xml'))->xpath('//s:sheet');
        $dateNames = array_map(static fn(SimpleXMLElement $sheet): string => (string)$sheet['name'], $dateSheets);
        $assert(in_array($expectedName, $dateNames, true), 'Full tab date across months and calendar years');
        foreach ($dateNames as $name) $assert(mb_strlen($name) <= 31, 'Valid Excel tab length');
        $zip->close();
    }
    echo "PASS: week XLSX, totals, ISO weeks, effective staff, links, empty days and safe text\n";
} finally { unlink($file); }
