<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterManualTimeline;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Api\Admin\DashboardRosterManualProgramSaveRequest;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $operation, string $code) use ($assert): void {
    try {
        $operation();
        throw new RuntimeException('Ongeldige dagindeling werd geaccepteerd: ' . $code);
    } catch (RosterPlanException $exception) {
        $assert($exception->publicCode === $code && $exception->httpStatus === 422, 'Onverwachte validatiefout: ' . $exception->publicCode);
    }
};

$round = ['type' => 'round', 'label' => 'Ronde 1', 'startTime' => '10:15', 'endTime' => '11:00'];
$valid = [
    ['type' => 'welcome', 'label' => 'Ontvangst', 'startTime' => '09:45', 'endTime' => '10:00'],
    $round,
    ['type' => 'break', 'label' => 'Wisselen', 'startTime' => '11:00', 'endTime' => '11:05'],
    ['type' => 'round', 'label' => 'Vrije ronde', 'startTime' => '11:05', 'endTime' => '11:50'],
    ['type' => 'lunch', 'label' => 'Lunch & verplaatsing', 'startTime' => '11:50', 'endTime' => '12:20'],
    ['type' => 'departure', 'label' => 'Afronding', 'startTime' => '12:30', 'endTime' => '12:35'],
];
$assert(RosterManualTimeline::fromArray($valid)->blocks === $valid, 'Gaten, aansluitingen en vrije tijdvakken moeten behouden blijven.');
$assert(RosterManualTimeline::fromArray([$round])->blocks === [$round], 'Eén ronde moet geldig zijn.');
$assert(
    RosterManualTimeline::fromArray([array_replace($round, ['label' => '  Café & welkom  '])])->blocks[0]['label'] === 'Café & welkom',
    'Labels moeten als tekst worden getrimd zonder inhoud te verliezen.',
);

foreach ([null, '[]', 1, false, (object) [0 => $round], ['round' => $round]] as $invalid) {
    $reject(static fn () => RosterManualTimeline::fromArray($invalid), 'INVALID_MANUAL_ROUNDS');
}
foreach ([null, 'round', [], ['unexpected' => true]] as $invalid) {
    $reject(static fn () => RosterManualTimeline::fromArray([$invalid]), 'INVALID_MANUAL_BLOCK');
}
foreach (['unknown', '', 1, null] as $invalid) {
    $reject(static fn () => RosterManualTimeline::fromArray([array_replace($round, ['type' => $invalid])]), 'INVALID_MANUAL_BLOCK_TYPE');
}
foreach (['', '   ', "\u{00A0}", "Regel\nTwee", str_repeat('é', 161), null, 7] as $invalid) {
    $reject(static fn () => RosterManualTimeline::fromArray([array_replace($round, ['label' => $invalid])]), 'INVALID_MANUAL_BLOCK_LABEL');
}
foreach (['9:00', '24:00', '10:60', '10:15:00', ' 10:15', "10:15\n", '', null, 1015] as $invalid) {
    foreach (['startTime', 'endTime'] as $field) {
        $reject(static fn () => RosterManualTimeline::fromArray([array_replace($round, [$field => $invalid])]), 'INVALID_TIME');
    }
}
foreach (['10:00', '10:15'] as $end) {
    $reject(static fn () => RosterManualTimeline::fromArray([array_replace($round, ['endTime' => $end])]), 'INVALID_TIME_RANGE');
}
foreach ([
    [$round, array_replace($round, ['startTime' => '10:55', 'endTime' => '11:30'])],
    [$round, array_replace($round, ['startTime' => '09:00', 'endTime' => '10:00'])],
] as $invalid) {
    $reject(static fn () => RosterManualTimeline::fromArray($invalid), 'INVALID_MANUAL_BLOCK_ORDER');
}
foreach ([[], [array_replace($round, ['type' => 'welcome'])]] as $invalid) {
    $reject(static fn () => RosterManualTimeline::fromArray($invalid), 'MANUAL_ROUND_REQUIRED');
}

$base = ['planId' => 1, 'expectedRevision' => 2];
$request = static fn (array $payload): DashboardRosterManualProgramSaveRequest =>
    DashboardRosterManualProgramSaveRequest::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
$modulesOnly = $request($base + ['moduleKeys' => ['Klimaat-Experience']]);
$assert($modulesOnly->moduleKeys === ['Klimaat-Experience'] && $modulesOnly->timeline === null, 'Bestaand modules-only contract is gewijzigd.');
$withGroups = $request($base + ['moduleKeys' => ['Klimaat-Experience'], 'groupCount' => 2]);
$assert($withGroups->groupCount === 2 && $modulesOnly->groupCount === null, 'Optioneel groepsaantal ontbreekt.');
foreach ([0, 11, 2.5, '2', true, null] as $invalidGroups) {
    $reject(static fn () => $request($base + ['moduleKeys' => ['Klimaat-Experience'], 'groupCount' => $invalidGroups]), 'INVALID_GROUP_COUNT');
}
$roundsOnly = $request($base + ['rounds' => $valid]);
$assert($roundsOnly->moduleKeys === null && $roundsOnly->timeline?->blocks === $valid, 'Rounds-only contract ontbreekt.');
$combined = $request($base + ['moduleKeys' => ['Klimaat-Experience'], 'rounds' => $valid]);
$assert($combined->moduleKeys === ['Klimaat-Experience'] && $combined->timeline?->blocks === $valid, 'Gecombineerd contract ontbreekt.');
foreach (['{', '[]', 'null', '{}', '{"planId":"1","expectedRevision":1,"rounds":[]}', '{"planId":1,"expectedRevision":0,"rounds":[]}'] as $invalid) {
    $reject(static fn () => DashboardRosterManualProgramSaveRequest::fromJson($invalid), 'INVALID_REQUEST');
}
$reject(static fn () => $request($base), 'INVALID_REQUEST');
$reject(static fn () => $request($base + ['moduleKeys' => null, 'rounds' => $valid]), 'INVALID_REQUEST');
$reject(static fn () => $request($base + ['rounds' => null]), 'INVALID_MANUAL_ROUNDS');
$reject(static fn () => $request($base + ['rounds' => (object) [0 => $round]]), 'INVALID_MANUAL_ROUNDS');
$reject(static fn () => $request($base + ['rounds' => []]), 'MANUAL_ROUND_REQUIRED');

$templates = new RosterGenerationTemplateProvider();
foreach (['ochtend', 'dag'] as $program) {
    $timeline = $templates->defaultTimeline($program);
    $assert(RosterManualTimeline::fromArray($timeline)->blocks === $timeline, 'Default-tijdlijn is ongeldig.');
    $lessons = array_values(array_map(
        static fn (array $block): array => array_diff_key($block, ['type' => true]),
        array_filter($timeline, static fn (array $block): bool => $block['type'] === 'round'),
    ));
    $assert($lessons === $templates->defaultRounds($program), 'Default-lessen wijken af van de bestaande templateprovider.');
    $assert($timeline[0]['type'] === 'welcome' && $timeline[0]['startTime'] === BookingProgramConfig::PROGRAMS[$program]['beginTijd'], 'Aankomst gebruikt niet de programmaconfiguratie.');
    $last = $timeline[count($timeline) - 1];
    $assert($last['type'] === 'departure' && $last['endTime'] === BookingProgramConfig::PROGRAMS[$program]['eindTijd'], 'Vertrek gebruikt niet de programmaconfiguratie.');
    $assert(count(array_filter($timeline, static fn (array $block): bool => $block['type'] === 'lunch')) === ($program === 'dag' ? 1 : 0), 'Lunch past niet bij het bestaande programma.');
    foreach ($timeline as $index => $block) {
        if ($index > 0) $assert($timeline[$index - 1]['endTime'] === $block['startTime'], 'Default-gaten worden niet uit de rondetijden afgeleid.');
    }
}
$assert($templates->defaultTimeline('onbekend') === [], 'Onbekend programma mag geen verzonnen default krijgen.');

$dayFive = $templates->defaultTimeline('dag', 5);
$dayFour = $templates->defaultTimeline('dag', 4);
$dayThree = $templates->defaultTimeline('dag', 3);
$dayTwo = $templates->defaultTimeline('dag', 2);

$roundCount = static fn (array $timeline): int => count(array_filter(
    $timeline,
    static fn (array $block): bool => $block['type'] === 'round',
));
$breaks = static fn (array $timeline): array => array_values(array_filter(
    $timeline,
    static fn (array $block): bool => in_array($block['type'], ['break', 'lunch'], true),
));

$assert($roundCount($dayFive) === 5, 'Vijf modules moeten vijf standaardlesrondes houden.');
$assert($roundCount($dayFour) === 4, 'Vier modules moeten vier lesrondes opleveren.');
$assert($roundCount($dayThree) === 3, 'Drie modules moeten drie lesrondes opleveren.');
$assert($roundCount($dayTwo) === 2, 'Twee modules moeten twee lesrondes opleveren.');

$fourBreaks = $breaks($dayFour);
$threeBreaks = $breaks($dayThree);
$twoBreaks = $breaks($dayTwo);

$assert(count($fourBreaks) === 1, 'Vier modules moeten Ã©Ã©n pauze hebben.');
$assert(
    $fourBreaks[0]['startTime'] === '11:45' && $fourBreaks[0]['endTime'] === '12:15',
    'Vier modules moeten na twee lesrondes een pauze van 30 minuten hebben.',
);
$assert(count($threeBreaks) === 1, 'Drie modules moeten Ã©Ã©n pauze hebben.');
$assert(
    $threeBreaks[0]['startTime'] === '11:00' && $threeBreaks[0]['endTime'] === '11:15',
    'Drie modules moeten na Ã©Ã©n lesronde een pauze van 15 minuten hebben.',
);
$assert($twoBreaks === [], 'Twee modules mogen geen tussenpauze hebben.');

fwrite(STDOUT, "OK: manual timeline domain + request contract ({$checks} checks).\n");
