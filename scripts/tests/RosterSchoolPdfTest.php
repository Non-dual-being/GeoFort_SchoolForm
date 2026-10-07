<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Roster\{RosterSchoolProjection, RosterSchoolPdfWriter, RosterPlanningConfig, RosterGenerationTemplateProvider};

$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$projection = new RosterSchoolProjection(); $writer = new RosterSchoolPdfWriter();
$config = new RosterPlanningConfig(); $templates = new RosterGenerationTemplateProvider();
$make = static function (int $count, int $modules, bool $long = false) use ($config, $templates): array {
    $keys = ['Dynamische-Globe', 'Earth-Watch', 'Minecraft-Windenergiespeurtocht', 'Voedsel-Innovatie', 'Klimaat-Experience'];
    $groups = []; $sessions = [];
    for ($i = 1; $i <= $count; $i++) $groups[] = ['id' => $i, 'position' => $i, 'label' => 'Groep ' . $i];
    foreach ($templates->defaultRounds('dag', $modules) as $r => $round) foreach ($groups as $i => $group) {
        $module = $config->moduleOption($keys[($r + $i) % $modules]);
        $sessions[] = ['startTime' => $round['startTime'], 'endTime' => $round['endTime'], 'groupIds' => [$group['id']],
            'moduleLabel' => $long ? $module['label'] . ' – klimaatonderzoek en samenwerking' : $module['label'], 'color' => $module['color'],
            'staffAssignments' => [['id' => 98765, 'name' => 'INTERNAL_TEACHER_SENTINEL']], 'location' => 'INTERNAL_LOCATION_SENTINEL',
            'groupSupervision' => [['groupId' => $group['id'], 'kind' => 'school']], 'notes' => 'OPEN kok INTERNAL_NOTES_SENTINEL'];
    }
    return ['formatVersion' => 1, 'planId' => 983456, 'publishedRevision' => 938374, 'stored' => ['secret' => 'INTERNAL_STORED_SENTINEL'],
        'presentation' => ['school' => ['name' => $long ? 'Scholengemeenschap voor internationaal en tweetalig voortgezet onderwijs De Lange Schoolnaam & Één Wereld, vestiging Rivierenland' : 'De Voorbeeldschool',
                'contactName' => 'Sam Voorbeeld', 'contactEmail' => 'school@example.test', 'contactPhone' => '0612345678', 'schoolPhone' => '0183123456', 'notes' => 'INTERNAL_SCHOOL_NOTES'],
            'visitDate' => '2028-10-10', 'education' => ['programLabel' => 'Dagprogramma', 'sectorLabel' => 'Voortgezet onderwijs onderbouw',
                'studentCount' => $count * 20, 'selectionLabels' => ['HAVO 1', 'Atheneum 1'], 'choiceModuleLabel' => 'Minecraft Programmeren'], 'groups' => $groups,
            'planning' => ['rounds' => $templates->defaultTimeline('dag', $modules), 'sessions' => $sessions, 'staffCatalog' => [['name' => 'INTERNAL_STAFF_SENTINEL']]]]];
};
$output = getenv('ROSTER_ROUND_B_OUTPUT') ?: sys_get_temp_dir() . '/geofort-round-b-pdf-tests';
if (!is_dir($output)) mkdir($output, 0700, true);
$cases = [];
for ($count = 1; $count <= 10; $count++) $cases['groups-' . $count] = $make($count, max(2, (int) ceil($count / 2)));
$cases['six-five-modules'] = $make(6, 5);
$cases['six-long-labels'] = $make(6, 5, true);
$cases['ten-long-labels'] = $make(10, 5, true);
$cases['five-five-modules'] = $make(5, 5);
$different = $make(6, 3);
$different['presentation']['planning']['rounds'][0]['groupIds'] = [1, 2, 3, 4, 5];
$different['presentation']['planning']['rounds'][] = ['type' => 'welcome', 'label' => 'Eigen aankomst', 'startTime' => '10:00', 'endTime' => '10:15', 'groupIds' => [6]];
$cases['six-different-neutral'] = $different;
$results = [];
foreach ($cases as $name => $snapshot) {
    $before = serialize($snapshot); $school = $projection->fromSnapshot($snapshot);
    $assert(serialize($snapshot) === $before, 'Projection changed its snapshot');
    $assert(!str_contains(json_encode($school), 'INTERNAL_') && !str_contains(json_encode($school), '983456'), 'Internal data leaked');
    $bytes = $writer->render($school);
    file_put_contents($output . '/' . $name . '.pdf', $bytes);
    $assert(str_starts_with($bytes, '%PDF-') && str_contains($bytes, '%%EOF'), 'Invalid PDF');
    preg_match_all('~/Type\s*/Page\b~', $bytes, $pageMatches);
    $expected = count($school['groups']) <= 6 ? 2 : 3;
    $assert(count($pageMatches[0]) === $expected, $name . ': expected ' . $expected . ' pages, got ' . count($pageMatches[0]));
    preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $bytes, $streams);
    $pageStreams = [];
    // Resolve each page's actual Contents object. Image streams may contain arbitrary binary data.
    preg_match_all('~(?m)^\d+ 0 obj\s*<<\s*/Type /Page\b.*?/Contents (\d+) 0 R~s', $bytes, $contents);
    foreach ($contents[1] as $object) {
        $assert(preg_match('~(?m)^' . $object . ' 0 obj\s*<<.*?/Length (\d+).*?>>\s*stream\r?\n~s', $bytes, $match, PREG_OFFSET_CAPTURE) === 1, 'Missing PDF page stream');
        $start = $match[0][1] + strlen($match[0][0]);
        $decoded = gzuncompress(substr($bytes, $start, (int) $match[1][0]));
        $assert($decoded !== false, 'Invalid PDF page compression');
        $pageStreams[] = $decoded;
    }
    $textStreams = implode('', $pageStreams);
    $pdfText = static fn (string $text): string => mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    $assert(str_contains($textStreams, $pdfText('Gepland lesrooster')), 'PDF title missing from actual text stream');
    $assert(str_contains($bytes, '/Subtype /Image'), 'Official logo missing from real PDF');
    foreach (['Sam Voorbeeld', 'school@example.test', '0612345678', 'HAVO 1', 'Minecraft Programmeren'] as $info) {
        $assert(str_contains($pageStreams[$expected - 1], $pdfText($info)), 'Snapshot information missing from final page: ' . $info);
        $assert(!str_contains($pageStreams[0], $pdfText($info)), 'Contact/program information occupied timetable page');
    }
    foreach ($pageStreams as $pageIndex => $pageText) $assert(str_contains($pageText, $pdfText('Pagina ' . ($pageIndex + 1) . ' / ' . $expected)), 'Incorrect total page numbering');
    foreach (['INTERNAL_', '983456', '938374', '98765', 'OPEN', 'kok'] as $internal) {
        $assert(!str_contains($textStreams, $pdfText($internal)), 'Internal data present in actual PDF text: ' . $internal);
    }
    $groupCount = count($school['groups']);
    $expectedSets = match ($groupCount) { 7 => [4, 3], 8 => [4, 4], 9 => [5, 4], 10 => [5, 5], default => [$groupCount] };
    $offset = 0;
    foreach ($expectedSets as $pageIndex => $setSize) {
        $pageText = $pageStreams[$pageIndex];
        foreach (['Gepland lesrooster', 'Start', 'Einde', '10:00'] as $repeated) $assert(str_contains($pageText, $pdfText($repeated)), 'Page lost title/time headers');
        for ($group = 1; $group <= $groupCount; $group++) {
            $assert(str_contains($pageText, $pdfText('Groep ' . $group) . ')') === ($group > $offset && $group <= $offset + $setSize), 'Wrong group split in actual PDF');
        }
        $offset += $setSize;
    }
    // The last word remains visible once per cell even when the label wraps over two lines.
    $welcomeWords = preg_split('/\s+/u', $school['rows'][0]['cells'][0]['label']);
    $welcome = $pdfText(end($welcomeWords)) . ')';
    $assert(substr_count($pageStreams[0], $welcome) === ($name === 'six-different-neutral' ? 5 : 1), $name . ': neutral blocks merged incorrectly in PDF');
    preg_match_all('~/F\w+\s+([\d.]+)\s+Tf~', $textStreams, $sizes);
    $assert($sizes[1] !== [] && min(array_map('floatval', $sizes[1])) >= 10, 'PDF fonts below 10pt');
    $assert(!str_contains($bytes, '/JavaScript') && !str_contains($bytes, '/URI'), 'Active PDF content');
    file_put_contents($output . '/' . $name . '.pdf', $bytes);
    file_put_contents($output . '/' . $name . '.projection.json', json_encode($school, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $results[] = ['case' => $name, 'groups' => count($school['groups']), 'groupSets' => $expectedSets, 'pages' => count($pageMatches[0]), 'minimumFontPt' => min(array_map('floatval', $sizes[1])), 'actualTextChecked' => true, 'sha256' => hash('sha256', $bytes)];
}
$assert($projection->fromSnapshot($different)['rows'][0]['cells'][5]['label'] === 'Eigen aankomst', 'Divergent neutral block lost');
// Older snapshots may obtain presentation fields only from their own frozen stored payload.
$legacy = $make(2, 2);
unset($legacy['presentation']['school']['contactEmail'], $legacy['presentation']['education']['selectionLabels']);
$legacy['stored']['booking'] = ['email' => 'frozen@example.test'];
$legacy['stored']['educationSelections'] = [['level_label' => 'HAVO', 'group_label' => 'HAVO 2']];
$before = serialize($legacy);
$oldProjection = $projection->fromSnapshot($legacy);
$assert($oldProjection['information']['school']['E-mailadres'] === 'frozen@example.test' && $oldProjection['information']['program']['Niveau(s)'] === 'HAVO 2', 'Frozen legacy information lost');
$assert(serialize($legacy) === $before, 'Historical snapshot was modified');
$legacy['presentation']['school']['contactEmail'] = '';
$assert($projection->fromSnapshot($legacy)['information']['school']['E-mailadres'] === '', 'Explicitly empty frozen value was overwritten');
unset($legacy['stored']['booking'], $legacy['stored']['educationSelections']);
file_put_contents($output . '/missing-information.pdf', $writer->render($projection->fromSnapshot($legacy)));
// Render hostile field content as literal text. No caller-provided HTML, CSS, SVG or URI reaches a markup/resource context.
$hostile = $make(2, 2);
$hostile['presentation']['school']['name'] = '<svg><script>alert(1)</script></svg> & "school"';
$hostile['presentation']['planning']['sessions'][0]['moduleLabel'] = '<img src="file:///private/secret"> https://example.invalid/';
$hostile['presentation']['school']['contactName'] = '<svg onload="alert(1)"> & contact';
$hostile['presentation']['school']['contactEmail'] = 'file:///private/secret';
file_put_contents($output . '/escaped-text.pdf', $writer->render($projection->fromSnapshot($hostile)));
$hostile['presentation']['planning']['sessions'][0]['color'] = 'url(https://example.invalid/)';
try { $projection->fromSnapshot($hostile); throw new LogicException('Unsafe color accepted'); } catch (RuntimeException $e) { $assert(!$e instanceof LogicException, $e->getMessage()); }
file_put_contents($output . '/results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'PASS: school-v2, ' . count($results) . " real PDF layouts; groups 1–10, 2–5 modules, >=10pt, official logo, timetable first, frozen information last, escaped text and allowlist.\n";
