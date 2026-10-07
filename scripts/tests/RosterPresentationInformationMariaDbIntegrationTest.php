<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\RosterFinalSnapshot;
use GeoFort\Services\Dashboard\Roster\RosterXlsxWriter;

$db = DisposableBookingMariaDb::create('roster_round_a_information');
$path = tempnam(sys_get_temp_dir(), 'roster-round-a-frozen-');
$xml = static function (array $plan) use ($path): array {
    (new RosterXlsxWriter())->write($path, $plan);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Cannot open snapshot export.');
    try { return array_map(static fn (int $sheet): string => $zip->getFromName('xl/worksheets/sheet' . $sheet . '.xml'), [1, 2, 3]); }
    finally { $zip->close(); }
};
try {
    RosterLifecycleFixtures::schema($db->pdo);
    $s = RosterLifecycleFixtures::services($db->pdo);
    $assert = RosterLifecycleFixtures::assert(...);
    foreach ([false, true] as $legacy) {
        $date = $legacy ? '2028-10-12' : '2028-10-11';
        $db->pdo->prepare("INSERT INTO aanvragen(schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,onderwijs_sector,programma,aantal_leerlingen) VALUES ('Historische school','België','Bevroren adres 7','1234 AB','Oude plaats','0301234567','0612345678','Oude','Contact','oud@example.test',?,'primairOnderwijs','dag',28)")->execute([$date]);
        $bookingId = (int) $db->pdo->lastInsertId();
        $db->pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,sector_label,level_key,level_label,level_position,group_key,group_label,group_position) VALUES (?,'primairOnderwijs','Primair onderwijs','regulier','Regulier basisonderwijs',1,'groep7','Groep 7',1)")->execute([$bookingId]);
        $plan = RosterLifecycleFixtures::validPlan($db->pdo, $s, 28, 2, 2, $date, $bookingId);
        $id = $plan['id'];
        $assert($plan['school']['address'] === 'Bevroren adres 7' && $plan['education']['selectionLabels'] === ['Groep 7'], 'Concept readmodel missing real booking data.');
        if ($legacy) {
            // Construct a pre-Round-A snapshot through the existing append-only repository.
            $oldView = $plan;
            foreach (['address', 'postalCode', 'country', 'schoolPhone', 'contactEmail'] as $field) unset($oldView['school'][$field]);
            unset($oldView['education']['selectionLabels']);
            $raw = $s['raw']->load($id);
            $raw['educationSelections'][0]['group_label'] = 'Historisch groepzeven';
            $content = ['formatVersion' => RosterFinalSnapshot::FORMAT_VERSION, 'planId' => $id, 'sourceRevision' => 1,
                'publishedRevision' => 2, 'presentation' => $oldView, 'stored' => $raw, 'occupancy' => [], 'rules' => []];
            $db->pdo->beginTransaction();
            $time = '2026-10-02 17:00:00.000000';
            $sid = $s['snapshots']->append($id, 1, 1, $time, $content);
            $s['snapshots']->transition($s['plans']->lockPlan($id), 'published', $sid, 1, $time);
            $db->pdo->commit();
            $final = $s['service']->getPlan($id);
        } else {
            $final = $s['lifecycle']->finalize($id, 1, 1);
        }
        $assert($final['education']['selectionLabels'] === [$legacy ? 'Historisch groepzeven' : 'Groep 7'], 'Frozen selection labels missing.');
        $frozenXml = $xml($final);
        $snapshot = $s['snapshots']->state($id)['current'];
        $bytes = $snapshot['content_json'];
        $db->pdo->prepare("UPDATE aanvragen SET schoolnaam='Gewijzigd',adres='Nieuw adres',postcode='9999 ZZ',plaats='Nieuw',land='Nederland',email='nieuw@example.test',school_telefoonnummer='000',contactpersoon_telefoonnummer='111',contactpersoon_voornaam='Nieuwe',contactpersoon_achternaam='Persoon' WHERE id=?")->execute([$bookingId]);
        $db->pdo->prepare("UPDATE aanvraag_onderwijs_selecties SET group_key='groep8',group_label='Nieuwe groep 8' WHERE aanvraag_id=?")->execute([$bookingId]);
        $db->pdo->exec("UPDATE roster_staff_members SET display_name=CONCAT('Nieuw ',id), is_active=0");
        $again = $s['service']->getPlan($id);
        $assert($again === $final && $xml($again) === $frozenXml, 'Live booking/staff/education changes leaked into final view/export.');
        $assert($s['snapshots']->state($id)['current']['content_json'] === $bytes, 'Reading enriched history rewrote snapshot.');
        $s['lifecycle']->reopen($id, 2, 1);
        $concept = $s['service']->getPlan($id);
        $assert($concept['school']['address'] === 'Nieuw adres' && $concept['education']['selectionLabels'] === ['Groep 8'], 'Reopened concept did not return to live readmodel.');
    }
    echo "OK: concept information and old/new immutable snapshot view/XLSX survive live contact, education and staff edits.\n";
} finally {
    if ($db->pdo->inTransaction()) $db->pdo->rollBack();
    if (is_file($path)) unlink($path);
    $db->drop();
}
