<?php

declare(strict_types=1);

use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;

// Same payload shape as RosterPlanService::getPlan(), without bootstrap or a database.
return (static function (): array {
    $config = new RosterPlanningConfig();
    $templates = new RosterGenerationTemplateProvider();
    $moduleKeys = ['Dynamische-Globe', 'Earth-Watch', 'Stop-de-Klimaat-Klok', 'Klimaat-Experience', 'Voedsel-Innovatie'];
    $plans = [];
    foreach ([2, 3, 4, 5] as $moduleCount) {
        $groupCount = $moduleCount === 3 ? 6 : $moduleCount;
        $staffCount = $moduleCount === 4 ? 9 : ($moduleCount === 3 ? 4 : $moduleCount + 1);
        $groups = [];
        for ($index = 1; $index <= $groupCount; $index++) {
            $groups[] = ['id' => $index, 'label' => 'Groep ' . $index, 'position' => $index, 'studentCount' => 15];
        }
        $staff = [];
        for ($index = 1; $index <= $staffCount; $index++) {
            $staff[] = [
                'id' => 100 + $index,
                'name' => 'Testdocent ' . chr(64 + $index),
                'isActive' => true, 'employmentType' => 'employee', 'canGuide' => true,
                'canCook' => false, 'preferences' => [],
            ];
        }
        $modules = array_map($config->moduleOption(...), array_slice($moduleKeys, 0, $moduleCount));
        $rounds = $templates->defaultTimeline('dag', $moduleCount);
        $sessions = [];
        $lessonIndex = 0;
        foreach ($rounds as $round) {
            if ($round['type'] !== 'round') continue;
            foreach ($groups as $index => $group) {
                $module = $modules[($index + $lessonIndex) % $moduleCount];
                $teacher = $staff[$index % $staffCount];
                $sessions[] = [
                    'id' => count($sessions) + 1,
                    'moduleKey' => $module['key'], 'moduleLabel' => $module['label'], 'color' => $module['color'],
                    'startTime' => $round['startTime'], 'endTime' => $round['endTime'],
                    'location' => $module['defaultLocation'],
                    'groupIds' => [$group['id']], 'groupLabels' => [$group['label']],
                    'minimumGeoFortStaff' => $config->requiredGeoFortGuidesForSession($module['key'], 1, $config->schoolSupervisionAllowedForModuleCount($module['key'], $moduleCount)),
                    'schoolSupervisionAllowed' => $config->schoolSupervisionAllowedForModuleCount($module['key'], $moduleCount),
                    'staffAssignments' => [['id' => $teacher['id'], 'name' => $teacher['name']]],
                    'groupStaffAssignments' => [],
                ];
            }
            $lessonIndex++;
        }
        if ($moduleCount === 3) {
            $shared = [];
            foreach ($sessions as $session) {
                $key = $session['startTime'] . '|' . $session['moduleKey'];
                if (!isset($shared[$key])) {
                    $moduleIndex = array_search($session['moduleKey'], $moduleKeys, true);
                    $session['staffAssignments'] = [['id' => $staff[$moduleIndex]['id'], 'name' => $staff[$moduleIndex]['name']]];
                    $shared[$key] = $session;
                } else {
                    $shared[$key]['groupIds'] = array_merge($shared[$key]['groupIds'], $session['groupIds']);
                    $shared[$key]['groupLabels'] = array_merge($shared[$key]['groupLabels'], $session['groupLabels']);
                    $shared[$key]['minimumGeoFortStaff'] = $config->requiredGeoFortGuidesForSession($session['moduleKey'], 2, false);
                    if ($session['moduleKey'] === 'Dynamische-Globe') {
                        $shared[$key]['staffAssignments'][] = ['id' => $staff[3]['id'], 'name' => $staff[3]['name']];
                    }
                }
            }
            $sessions = array_values($shared);
        }
        $plans['modules-' . $moduleCount] = [
            'id' => 800 + $moduleCount, 'bookingId' => null, 'planningMode' => 'custom',
            'visitDate' => '2028-03-16', 'status' => 'concept', 'revision' => 12,
            'school' => ['name' => 'Exporttest Academie ' . $moduleCount, 'city' => 'Testplaats',
                'address' => 'Voorbeeldlaan 12', 'postalCode' => '1234 AB', 'country' => 'Nederland',
                'contactName' => 'Sam Contact', 'contactEmail' => 'contact@example.test',
                'schoolPhone' => '0301234567', 'contactPhone' => '0612345678'],
            'education' => ['program' => 'dag', 'programLabel' => 'Dagprogramma', 'studentCount' => $groupCount * 15,
                'sector' => 'primairOnderwijs', 'sectorLabel' => 'Primair onderwijs',
                'selectionLabels' => ['Groep 7', 'Groep 8'], 'choiceModuleLabel' => 'Klimaat Experience'],
            'groups' => $groups,
            'planning' => [
                'modules' => $modules, 'rounds' => $rounds, 'roundsSource' => 'saved', 'sessions' => $sessions,
                'staffCatalog' => $staff, 'selectedStaffIds' => array_column($staff, 'id'),
                'staffingSettings' => ['staffingMode' => 'with_staff', 'preferGeoFortKe' => true, 'cookStaffId' => null],
            ],
        ];
    }

    $complex = $plans['modules-4'];
    $complex['school']['name'] = 'Exporttest met lange schoolnaam, ampersand & accenten: Één academie';
    $complex['planning']['staffCatalog'][0]['name'] = 'Testdocent Alexandra van de uitgestrekte rivierdelta';
    $complex['groups'][0]['label'] = 'Groep met een lange naam en extra toelichting';
    $teacherA = array_intersect_key($complex['planning']['staffCatalog'][0], ['id' => true, 'name' => true]);
    $teacherB = array_intersect_key($complex['planning']['staffCatalog'][1], ['id' => true, 'name' => true]);
    foreach ($complex['planning']['sessions'] as &$session) {
        if ($session['groupIds'] === [1]) {
            $session['groupLabels'] = [$complex['groups'][0]['label']];
            $session['staffAssignments'] = [$teacherA];
        }
    }
    unset($session);
    // A shared session, with one teacher for both groups and a second for group 2.
    $complex['planning']['sessions'][0]['groupIds'] = [1, 2];
    $complex['planning']['sessions'][0]['groupLabels'] = [$complex['groups'][0]['label'], 'Groep 2'];
    $complex['planning']['sessions'][0]['staffAssignments'] = [$teacherA];
    $complex['planning']['sessions'][0]['minimumGeoFortStaff'] = 2;
    $complex['planning']['sessions'][0]['groupStaffAssignments'] = [
        ['groupId' => 1, 'staffAssignments' => [$teacherA]],
        ['groupId' => 2, 'staffAssignments' => [$teacherA, $teacherB]],
    ];
    unset($complex['planning']['sessions'][1]);
    // Keep a real empty round, and use semantic types with nonstandard labels.
    $lastLesson = array_values(array_filter($complex['planning']['rounds'], static fn (array $row): bool => $row['type'] === 'round'))[3];
    $complex['planning']['sessions'] = array_values(array_filter(
        $complex['planning']['sessions'],
        static fn (array $session): bool => $session['startTime'] !== $lastLesson['startTime'],
    ));
    foreach ($complex['planning']['rounds'] as &$round) {
        if ($round['type'] === 'welcome') $round['label'] = 'Ontvangst bij de poort';
        if ($round['type'] === 'break') $round['label'] = 'Even uitrusten';
        if ($round['type'] === 'departure') $round['label'] = 'Afronden';
    }
    unset($round);
    $plans['long-multiple-free'] = $complex;

    $literal = $plans['modules-5'];
    $literal['planning']['rounds'] = array_values(array_filter($literal['planning']['rounds'], static fn (array $row): bool => $row['type'] === 'round'));
    $literal['planning']['rounds'][0]['label'] = 'Welkom in de les';
    $literal['planning']['sessions'][0]['moduleLabel'] = 'Module B: Gebouw, medewerker & pauze — volledige naam';
    $literal['planning']['sessions'][0]['location'] = 'Deze locatie mag niet in de lescel';
    $literal['school']['name'] = '=1+1';
    $literal['planning']['sessions'][3]['staffAssignments'] = [];
    $literal['planning']['sessions'][3]['minimumGeoFortStaff'] = 0;
    $literal['planning']['sessions'][3]['schoolSupervisionAllowed'] = true;
    $literal['planning']['sessions'][3]['groupSupervision'] = array_map(static fn (int $id): array => ['groupId' => $id, 'kind' => 'school'], $literal['planning']['sessions'][3]['groupIds']);
    $literal['planning']['sessions'][2]['staffAssignments'] = [];
    $plans['literal-labels-school-open'] = $literal;

    $wide = $plans['modules-5'];
    for ($index = 6; $index <= 10; $index++) {
        $wide['groups'][] = ['id' => $index, 'label' => 'Groep ' . $index, 'position' => $index, 'studentCount' => 15];
    }
    for ($index = 7; $index <= 14; $index++) {
        $wide['planning']['staffCatalog'][] = ['id' => 100 + $index, 'name' => 'Testdocent ' . chr(64 + $index),
            'isActive' => true, 'employmentType' => 'employee', 'canGuide' => true, 'canCook' => false, 'preferences' => []];
    }
    foreach ($plans['modules-5']['planning']['sessions'] as $session) {
        $session['id'] += 100;
        $session['groupIds'][0] += 5;
        $session['groupLabels'][0] = 'Groep ' . $session['groupIds'][0];
        $wide['planning']['sessions'][] = $session;
    }
    $wide['education']['studentCount'] = 150;
    $wide['planning']['selectedStaffIds'] = array_column($wide['planning']['staffCatalog'], 'id');
    $plans['ten-groups-fourteen-staff'] = $wide;

    $empty = $plans['modules-2'];
    $empty['planning']['sessions'] = [];
    $empty['planning']['staffCatalog'] = [];
    $empty['planning']['selectedStaffIds'] = [];
    $plans['no-staff'] = $empty;
    return $plans;
})();
