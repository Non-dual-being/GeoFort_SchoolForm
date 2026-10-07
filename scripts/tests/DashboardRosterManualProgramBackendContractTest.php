<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/src/Services/Dashboard/Roster/RosterPlanService.php');
$repository = (string) file_get_contents($root . '/src/Services/Sql/RosterPlanSqlRepository.php');
$action = (string) file_get_contents($root . '/src/Services/Http/Api/Admin/DashboardRosterManualProgramSaveAction.php');
$endpoint = (string) file_get_contents($root . '/public/api/admin/rosters/manual-program-save.php');
$migration = (string) file_get_contents($root . '/database/sql/2026-09-26_add_roster_manual_program_modules.sql');

$assertContains = static function (string $haystack, string $needle, string $message): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Ontbreekt: {$needle}");
    }
};

foreach ([
    'public function saveManualProgram(',
    'BookingProgramConfig::getStandardModulesForSelection',
    'BookingProgramConfig::getChoiceModulesForSelection',
    "'REVISION_CONFLICT'",
    "'MANUAL_PROGRAM_ONLY'",
    'saveManualModuleKeys($planId, $normalized)',
    'RosterManualProgramPolicy::supportsModuleCount',
    'RosterManualProgramPolicy::maxGroupsForModuleCount',
    "'MANUAL_GROUP_CAPACITY_EXCEEDED'",
    'clearManualRounds($planId)',
    'RosterManualProgramPolicy::matchesTimelineStructure',
    "'MANUAL_TIMELINE_STRUCTURE_MISMATCH'",
] as $needle) {
    $assertContains($service, $needle, 'V8B2B servicecontract mist programmavalidatie.');
}

foreach ([
    'public function saveManualModuleKeys(',
    'module_keys_json = :module_keys_json',
    'NULL AS module_keys_json',
] as $needle) {
    $assertContains($repository, $needle, 'V8B2B repositorycontract mist moduleopslag.');
}

$assertContains($action, 'DashboardRosterCreateAction::CSRF_SCOPE', 'Programma-save moet bestaand roosterplan-CSRF hergebruiken.');
$assertContains($action, '$this->service->saveManualProgram(', 'HTTP-action moet aan de planservice delegeren.');
$assertContains($endpoint, 'DashboardRosterManualProgramSaveAction::class', 'Programma-save endpoint ontbreekt.');
$assertContains($migration, 'ADD COLUMN module_keys_json JSON NULL', 'V8B2B migratie mist module_keys_json.');

fwrite(STDOUT, "OK: manual roster V8B2B Programma backend contract passed.\n");
