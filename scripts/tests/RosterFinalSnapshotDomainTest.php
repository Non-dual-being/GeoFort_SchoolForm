<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GeoFort\Services\Dashboard\Roster\RosterFinalSnapshot;
$a = ['name' => 'Één school', 'groups' => [['id' => 2, 'label' => 'A'], ['id' => 3, 'label' => 'B']], 'revision' => 4];
$b = ['revision' => 4, 'groups' => [['label' => 'A', 'id' => 2], ['label' => 'B', 'id' => 3]], 'name' => 'Één school'];
if (RosterFinalSnapshot::hash($a) !== RosterFinalSnapshot::hash($b)) throw new RuntimeException('Object key order affects hash.');
$b['groups'] = array_reverse($b['groups']);
if (RosterFinalSnapshot::hash($a) === RosterFinalSnapshot::hash($b)) throw new RuntimeException('Operational list order not hashed.');
foreach ([1.5, new stdClass(), INF] as $value) {
    try { RosterFinalSnapshot::hash(['value' => $value]); throw new RuntimeException('Unsupported snapshot type accepted.'); } catch (InvalidArgumentException) {}
}
echo "OK: canonical snapshot hashing, Unicode, deterministic key order and type guards.\n";
