<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;

$assert = static function (bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); };
$plan = ['groups' => [['id' => 1, 'label' => 'Groep 1'], ['id' => 2, 'label' => 'Groep 2']], 'planning' => ['sessions' => [[
    'startTime' => '10:00', 'endTime' => '10:30', 'moduleLabel' => 'Klimaat Experience', 'groupIds' => [1, 2],
    'staffAssignments' => [], 'groupStaffAssignments' => [], 'schoolSupervisionAllowed' => true, 'minimumGeoFortStaff' => 1,
    'groupSupervision' => [['groupId' => 1, 'kind' => 'school'], ['groupId' => 2, 'kind' => 'open']],
]]]];
$path = tempnam(sys_get_temp_dir(), 'roster-v1-xlsx-');
try {
    foreach ([true, false] as $explicit) {
        if (!$explicit) unset($plan['planning']['sessions'][0]['groupSupervision']);
        (new RosterXlsxWriter())->write($path, $plan);
        $zip = new ZipArchive(); $assert($zip->open($path) === true, 'Invalid workbook.');
        $xml = new DOMDocument(); $assert($xml->loadXML($zip->getFromName('xl/worksheets/sheet3.xml'), LIBXML_NONET), 'Invalid teacher sheet.'); $zip->close();
        $xpath = new DOMXPath($xml); $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = []; foreach ($xpath->query('//s:c') as $cell) $cells[$cell->getAttribute('r')] = str_replace("\u{00A0}", ' ', $cell->textContent);
        $assert(in_array('OPEN', $cells, true), 'OPEN column missing.');
        if ($explicit) {
            $openHeaders = array_keys($cells, 'OPEN', true); $openHeader = end($openHeaders);
            $schoolCell = array_search("Klimaat Experience\n(Groep 1)", $cells, true); $openCell = array_search("Klimaat Experience\n(Groep 2)", $cells, true);
            $assert($schoolCell !== false && $openCell !== false, 'School/OPEN group labels mixed.');
            // The metadata also contains School; use the last School header for the teacher column.
            $schoolHeaders = array_keys($cells, 'School', true); $schoolHeader = end($schoolHeaders);
            $assert(preg_replace('/\d+/', '', $schoolHeader) === preg_replace('/\d+/', '', $schoolCell)
                && preg_replace('/\d+/', '', $openHeader) === preg_replace('/\d+/', '', $openCell), 'Supervision printed under wrong teacher column.');
        } else {
            $assert(in_array("Klimaat Experience\n(Groep 1) (Groep 2)", $cells, true), 'Unconfirmed empty groups silently became school supervision.');
        }
    }
    echo "OK: XLSX distinguishes School and OPEN per group; legacy empty assignments stay OPEN.\n";
} finally { if (is_file($path)) unlink($path); }
