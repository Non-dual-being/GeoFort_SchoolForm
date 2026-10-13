<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Booking\Roster\{FourModuleExampleRoster, BookingRosterResolver, RosterGroupCountResolver, RosterAttachmentResolver};
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Sql\RosterSqlService;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;

$public = dirname(__DIR__, 2) . '/public';
$resolver = new BookingRosterResolver(new RosterSqlService(new PDO('sqlite::memory:')), new RosterGroupCountResolver());
$attachment = new RosterAttachmentResolver($public);
$config = new RosterPlanningConfig(); $checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
foreach (['primairOnderwijs', 'voortgezetOnderbouw', 'voortgezetBovenbouw'] as $sector) {
    $selection = new EducationSelectionData($sector, ['test'], ['test' => ['groep7', 'groep8']]);
    foreach (FourModuleProgram::choiceModules($sector, ['groep7', 'groep8']) as $choice) for ($groups = 1; $groups <= 8; $groups++) {
        $roster = FourModuleExampleRoster::schedule($sector, $choice, $groups);
        $seen = array_fill(0, $groups, []);
        foreach ($roster['rows'] as $row) if ($row['kind'] === 'lesson') {
            $pools = [];
            foreach ($row['cells'] as $g => $cell) {
                $seen[$g][] = $cell['key'];
                foreach ($config->capacityPools($cell['key']) as $pool => $max) {
                    $pools[$pool] = ($pools[$pool] ?? 0) + 1;
                    $assert($pools[$pool] <= $max, 'Example exceeds shared capacity');
                }
            }
        }
        foreach ($seen as $keys) $assert(count(array_unique($keys)) === 4, 'Group must visit four distinct modules');
        $result = $resolver->resolve($sector, 'dag', $selection, $choice, $groups === 8 ? 160 : $groups * 19, 10, FourModuleProgram::VERSION);
        $assert($result->available && $result->groupCount === $groups, 'Public example unavailable');
        $resolved = $attachment->resolve($result->pdfUrl);
        $assert($resolved->found, 'Mail attachment missing: ' . $result->pdfUrl . ': ' . $resolved->reason);
        $image = getimagesize($public . $result->imageUrl);
        $assert($image !== false && $image[0] > 1200 && $image[2] === IMAGETYPE_PNG, 'Missing readable PNG');
    }
}
try {
    $resolver->resolve('primairOnderwijs', 'dag', new EducationSelectionData('primairOnderwijs', ['regulier'], ['regulier'=>['groep6','groep7']]), 'Earth-Watch', 38, 4, FourModuleProgram::VERSION);
    throw new RuntimeException('Ineligible mixed primary choice accepted');
} catch (InvalidArgumentException) { $checks++; }
echo "PASS: {$checks} example coverage, capacity, version, assets and attachment checks\n";
