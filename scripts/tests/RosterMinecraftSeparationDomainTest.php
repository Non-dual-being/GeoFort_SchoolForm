<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Dashboard\Roster\{RosterPlanningConfig, RosterSchoolProjection};

$assert = static function (bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); };
$keys = ['Minecraft-Klimaatspeurtocht', 'Minecraft-Programmeren'];
$config = new RosterPlanningConfig();
$options = array_map($config->moduleOption(...), $keys);
$assert(array_column($options, 'key') === $keys, 'Minecraft module identity changed');
$assert(count(array_unique(array_column($options, 'label'))) === 2 && count(array_unique(array_column($options, 'color'))) === 2, 'Minecraft presentation is merged');
foreach ($keys as $key) $assert(isset(BookingProgramConfig::MODULE_LABELS[$key]), 'Canonical key absent');
$snapshot = ['formatVersion' => 1, 'stored' => [], 'presentation' => [
    'school' => ['name' => 'Minecraft test'], 'visitDate' => '2028-10-10', 'education' => ['programLabel' => 'Dagprogramma'],
    'groups' => [['id' => 1, 'label' => 'Groep 1'], ['id' => 2, 'label' => 'Groep 2']],
    'planning' => ['rounds' => [], 'sessions' => array_map(static fn (array $module, int $i): array => [
        'moduleKey' => $module['key'], 'moduleLabel' => $module['label'], 'color' => $module['color'],
        'startTime' => '10:15', 'endTime' => '11:00', 'groupIds' => [$i + 1],
    ], $options, [0, 1])],
]];
$before = serialize($snapshot);
$cells = (new RosterSchoolProjection())->fromSnapshot($snapshot)['rows'][0]['cells'];
$assert(array_column($cells, 'label') === array_column($options, 'label'), 'PDF projection merges Minecraft names');
$assert(array_column($cells, 'color') === array_column($options, 'color'), 'PDF projection merges Minecraft colors');
$assert(serialize($snapshot) === $before, 'Frozen snapshot mutated');
echo "PASS: independent Minecraft keys, labels, colors and immutable school projection.\n";
