<?php

declare(strict_types=1);
$root = dirname(__DIR__, 2) . '/resources/js/admin/';
$detail = file_get_contents($root . 'views/DashboardRosterDetailView.vue');
$summary = file_get_contents($root . 'components/rosters/RosterValidationSummary.vue');
$manual = file_get_contents($root . 'components/rosters/RosterManualGrid.vue');
$assert = static function (bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); };
foreach (['validateDashboardRoster', 'validation.value.revision === plan.value.revision', 'validation.value.errors.length === 0', 'finalization', 'window.confirm', 'requestError.status === 409', 'Definitief', 'Heropenen'] as $needle) $assert(str_contains($detail, $needle), 'Missing lifecycle UI contract: ' . $needle);
foreach (['result.errors', 'result.warnings', 'result.revision'] as $needle) $assert(str_contains($summary, $needle), 'Server result not presented.');
$assert(!str_contains($summary, 'computed(') && !str_contains($summary, '.sessions') && !str_contains($summary, '.modules'), 'Control tab computes a competing validation truth.');
$assert(str_contains($manual, 'supervisionKind') && str_contains($manual, 'school') && str_contains($manual, 'OPEN'), 'School and OPEN are not explicit choices.');
echo "OK: revision-bound server control, reopen confirmation and explicit supervision UI.\n";
