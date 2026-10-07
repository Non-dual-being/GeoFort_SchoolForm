<?php

declare(strict_types=1);

use GeoFort\Services\Dashboard\Roster\RosterManualProgramPolicy;

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(!RosterManualProgramPolicy::supportsModuleCount(1), 'EÃ©n module moet buiten scope vallen.');
$assert(RosterManualProgramPolicy::supportsModuleCount(2), 'Twee modules moeten ondersteund zijn.');
$assert(RosterManualProgramPolicy::supportsModuleCount(3), 'Drie modules moeten ondersteund zijn.');
$assert(RosterManualProgramPolicy::supportsModuleCount(4), 'Vier modules moeten ondersteund zijn.');
$assert(RosterManualProgramPolicy::supportsModuleCount(5), 'Vijf modules moeten ondersteund zijn.');
$assert(!RosterManualProgramPolicy::supportsModuleCount(6), 'Zes modules moeten buiten scope vallen.');

$assert(RosterManualProgramPolicy::maxGroupsForModuleCount(2) === 4, 'Twee modules = maximaal vier groepen.');
$assert(RosterManualProgramPolicy::maxGroupsForModuleCount(3) === 6, 'Drie modules = maximaal zes groepen.');
$assert(RosterManualProgramPolicy::maxGroupsForModuleCount(4) === 8, 'Vier modules = maximaal acht groepen.');
$assert(RosterManualProgramPolicy::maxGroupsForModuleCount(5) === 10, 'Vijf modules = maximaal tien groepen.');

$assert(RosterManualProgramPolicy::minimumModulesForGroupCount(4) === 2, 'Vier groepen vragen twee modules.');
$assert(RosterManualProgramPolicy::minimumModulesForGroupCount(5) === 3, 'Vijf groepen vragen drie modules.');
$assert(RosterManualProgramPolicy::minimumModulesForGroupCount(6) === 3, 'Zes groepen vragen drie modules.');
$assert(RosterManualProgramPolicy::minimumModulesForGroupCount(7) === 4, 'Zeven groepen vragen vier modules.');
$assert(RosterManualProgramPolicy::minimumModulesForGroupCount(8) === 4, 'Acht groepen vragen vier modules.');
$assert(RosterManualProgramPolicy::minimumModulesForGroupCount(9) === 5, 'Negen groepen vragen vijf modules.');
$assert(RosterManualProgramPolicy::minimumModulesForGroupCount(10) === 5, 'Tien groepen vragen vijf modules.');

fwrite(STDOUT, "OK: manual roster V8B2C3 module/group policy domain contract passed.\n");