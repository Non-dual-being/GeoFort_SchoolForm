<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Dashboard\Roster\{RosterAutoGenerator, RosterPlanningConfig, RosterGenerationTemplateProvider, RosterPairedLessonSearch};
use GeoFort\Services\Sql\{StoredBookingSqlRepository, RosterPlanSqlRepository, RosterSessionSqlRepository};

$assert = static function (bool $value, string $why): void { if (!$value) throw new RuntimeException($why); };
$pdo = new PDO('sqlite::memory:');
$config = new RosterPlanningConfig();
$templates = new RosterGenerationTemplateProvider();
$generator = new RosterAutoGenerator($pdo, new StoredBookingSqlRepository($pdo, new StoredBookingAssembler()), new RosterPlanSqlRepository($pdo), new RosterSessionSqlRepository($pdo), $config, $templates);
$search = new ReflectionMethod($generator, 'standardLessonCandidates');
$score = new ReflectionMethod($generator, 'lessonResourceScore');
$checks = 0;
$fixtures = [
    [['Voedsel-Innovatie','Dynamische-Globe','Klimaat-Experience','Earth-Watch','Minecraft-Klimaatspeurtocht'], 5, 'dag', null],
    [['Voedsel-Innovatie','Dynamische-Globe','Klimaat-Experience','Stop-de-Klimaat-Klok'], 4, 'dag', 'day-four-2027'],
    [['Zandtafel','Rising-Risk','Expedition-Earth','Dynamische-Globe','Dynamische-Globe-Bios'], 5, 'ochtend', null],
    [['Voedsel-Innovatie','Dynamische-Globe','Klimaat-Experience'], 6, 'dag', null],
];
foreach ($fixtures as [$keys, $maximum, $program, $version]) {
    foreach (range(1, $maximum + 1) as $count) {
        $groups = array_map(static fn(int $id): array => ['id'=>$id,'label'=>'Groep '.$id], range(1, $count));
        $rounds = $templates->defaultRounds($program, count($keys));
        $modules = array_map(static fn(string $key): array => [...$config->moduleOption($key, $version), 'programModuleCount'=>count($keys)], $keys);
        $candidates = $search->invoke($generator, $groups, $rounds, $modules, []);
        $assert(($candidates !== []) === ($count <= $maximum), "Wrong feasibility for $program/$count/".count($keys));
        $previous = null;
        foreach ($candidates as $candidate) {
            $assert((new RosterPairedLessonSearch($config, true))->conflicts($candidate, []) === [], 'Capacity exceeded');
            $ranking = $score->invoke($generator, $candidate, $rounds);
            $assert($previous === null || $previous <= $ranking, 'Candidates not ranked economically');
            $previous = $ranking;
            foreach ($groups as $group) {
                $coverage = [];
                foreach ($candidate as $session) if (in_array($group['id'], $session['groupIds'], true)) $coverage[] = $session['moduleKey'];
                sort($coverage); $expected = $keys; sort($expected);
                $assert($coverage === $expected, 'Missing or duplicate module');
            }
            $checks++;
        }
    }
}
$keys = ['Earth-Watch','Stop-de-Klimaat-Klok'];
$modules = array_map($config->moduleOption(...), $keys);
$rounds = $templates->defaultRounds('dag', 2);
$fixed = [['moduleKey'=>'Earth-Watch','groupCount'=>1,'startTime'=>$rounds[0]['startTime'],'endTime'=>$rounds[0]['endTime']]];
$p = $search->invoke($generator, [['id'=>1,'label'=>'Groep 1']], $rounds, $modules, $fixed);
$assert($p !== [] && (new RosterPairedLessonSearch($config, true))->conflicts($p[0], $fixed) === [], 'Fixed school capacity ignored');
// Normal double VI needs exactly two guide/cook rounds for four groups.
$keys = ['Voedsel-Innovatie','Dynamische-Globe','Klimaat-Experience'];
$p = $search->invoke($generator, array_map(static fn(int $id): array => ['id'=>$id,'label'=>"Groep $id"], range(1,4)), $templates->defaultRounds('dag',3), array_map($config->moduleOption(...), $keys), []);
$food = array_filter($p[0], static fn(array $s): bool => $s['moduleKey']==='Voedsel-Innovatie');
$assert(count($food)===2, 'VI unnecessarily spread into more rounds');
$assert($config->maxParallel('Earth-Watch')===2 && $config->normalMaxParallel('Earth-Watch')===1, 'Manual exception changed');
echo "PASS: $checks standard candidates, complete coverage, morning shared resources, fixed occupancy and economical ranking\n";
