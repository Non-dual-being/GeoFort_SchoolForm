<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Booking\FourModuleProgram;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Pricing\BookingPricingInput;

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $action) use ($assert): void {
    try {
        $action();
    } catch (InvalidArgumentException) {
        $assert(true, 'Invalid input rejected');
        return;
    }
    $assert(false, 'Invalid input was accepted');
};

$assert(!FourModuleProgram::availableOn('2027-01-03'), 'Before boundary');
$assert(FourModuleProgram::availableOn('2027-01-04'), 'Boundary inclusive');
$assert(FourModuleProgram::availableOn('2027-01-05'), 'After boundary');
$reject(fn () => FourModuleProgram::availableOn('2027-02-30'));
foreach ([19 => 1, 20 => 2, 38 => 2, 39 => 3, 152 => 8, 153 => 8, 160 => 8] as $students => $groups) {
    $assert(FourModuleProgram::suggestedGroupCount($students) === $groups, 'Group boundary ' . $students);
}
$reject(fn () => FourModuleProgram::suggestedGroupCount(161));
$reject(fn () => FourModuleProgram::suggestedGroupCount(0));
$assert(count(FourModuleProgram::modules('primairOnderwijs', ['groep7', 'groep8'], 'Earth-Watch')) === 4, 'PO senior choice');
$reject(fn () => FourModuleProgram::modules('primairOnderwijs', ['groep6', 'groep7'], 'Earth-Watch'));
$reject(fn () => FourModuleProgram::modules('primairOnderwijs', [], 'Stop-de-Klimaat-Klok'));
foreach (['voortgezetOnderbouw', 'voortgezetBovenbouw'] as $sector) {
    foreach (FourModuleProgram::choiceModules($sector, []) as $choice) {
        $modules = FourModuleProgram::modules($sector, [], $choice);
        $assert(count(array_unique($modules)) === 4, 'Four distinct modules for ' . $sector);
        $assert(in_array('Klimaat-Experience', $modules, true), 'VO includes KE');
    }
}
$reject(fn () => FourModuleProgram::standardModules('unknown'));
$slots = FourModuleProgram::timetable();
$lessons = array_values(array_filter($slots, static fn (array $slot): bool => $slot['kind'] === 'lesson'));
$assert(count($lessons) === 4 && end($slots)['end'] === '14:00', 'Four lessons finish at 14:00');
foreach ($lessons as $slot) {
    $assert(strtotime($slot['end']) - strtotime($slot['start']) === 45 * 60, '45 minute lessons');
}
for ($i = 1; $i < count($slots); $i++) {
    $assert($slots[$i - 1]['end'] === $slots[$i]['start'], 'Continuous timetable');
}
$assert(FourModuleProgram::requiredTeachers(2) === 2, 'Two groups need two teachers');

$food = FoodAndDrinkSelectionData::fromStoredValues(2, 0, 0, 0, 0, 0, false);
$calculator = new BookingPriceCalculator();
foreach (['primairOnderwijs', 'voortgezetOnderbouw', 'voortgezetBovenbouw'] as $sector) {
    $old = new BookingPricingInput($sector, 'dag', 40, 10, $food);
    $new = new BookingPricingInput($sector, 'dag', 40, 10, $food, FourModuleProgram::VERSION);
    $oldQuote = $calculator->calculateInput($old);
    $newQuote = $calculator->calculateInput($new);
    $assert($oldQuote->paidSupervisors === 5 && $newQuote->paidSupervisors === 0, 'Only four modules waive supervisor admission');
    $assert($oldQuote->pricePerVisitorInclVatCents === $newQuote->pricePerVisitorInclVatCents, 'Same student rate');
    $assert($oldQuote->cateringAmountInclVatCents === $newQuote->cateringAmountInclVatCents, 'Catering unchanged');
    $assert($oldQuote->totalAmountInclVatCents - $newQuote->totalAmountInclVatCents === 5 * $oldQuote->pricePerVisitorInclVatCents, 'Exact supervisor price difference');
    $assert(!array_key_exists('programVersion', $old->canonicalData()), 'Historical checksum shape retained');
    $assert($new->canonicalData()['programVersion'] === FourModuleProgram::VERSION, 'Version participates in new checksum');
}
$reject(fn () => new BookingPricingInput('primairOnderwijs', 'ochtend', 40, 10, $food, FourModuleProgram::VERSION));
$reject(fn () => new BookingPricingInput('primairOnderwijs', 'dag', 40, 10, $food, 'unknown'));
fwrite(STDOUT, "PASS: {$checks} four-module domain and pricing checks\n");
