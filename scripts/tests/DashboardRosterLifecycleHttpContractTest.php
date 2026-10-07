<?php

declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use GeoFort\Services\Http\Api\Admin\{DashboardRosterFinalizeAction, DashboardRosterReopenAction, DashboardRosterRevisionRequest, DashboardRosterSessionSaveRequest};

$assert = static function (bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); };
foreach ([null, [], true, 12.5, '1', ['planId' => 1], ['planId' => '1', 'expectedRevision' => 1], ['planId' => 1, 'expectedRevision' => 0], ['planId' => 1, 'expectedRevision' => 1.5]] as $payload) {
    try { DashboardRosterRevisionRequest::fromArray($payload); throw new RuntimeException('Invalid revision payload accepted.'); }
    catch (RosterPlanException $e) { $assert($e->httpStatus === 422, 'Wrong DTO failure.'); }
}
$assert(DashboardRosterRevisionRequest::fromArray(['planId' => 9, 'expectedRevision' => 7])->expectedRevision === 7, 'Valid revision rejected.');
$assert(DashboardRosterRevisionRequest::fromQuery(['planId' => '9', 'expectedRevision' => '7'])->planId === 9, 'Valid query rejected.');
foreach (['01', '0', '-1', '1.5', '1e2', '999999999999999999999999999', ['1']] as $value) {
    try { DashboardRosterRevisionRequest::fromQuery(['planId' => '1', 'expectedRevision' => $value]); throw new RuntimeException('Invalid query accepted.'); }
    catch (RosterPlanException) {}
}
foreach ([['supervisionKind' => 'school'], ['sessionId' => 1, 'staffGroupId' => 1, 'supervisionKind' => 'school', 'staffIds' => [2]], ['sessionId' => 1, 'staffGroupId' => 1, 'supervisionKind' => null]] as $payload) {
    try { DashboardRosterSessionSaveRequest::fromArray($payload); throw new RuntimeException('Ambiguous supervision accepted.'); }
    catch (RosterPlanException) {}
}
foreach (['staff', 'school', 'open'] as $kind) $assert(DashboardRosterSessionSaveRequest::fromArray(['sessionId' => 1, 'staffGroupId' => 1, 'supervisionKind' => $kind, 'staffIds' => []])->supervisionKind === $kind, 'Valid supervision rejected.');
$_SESSION = []; $csrf = new CsrfTokenService();
$final = $csrf->getOrCreate(DashboardRosterFinalizeAction::CSRF_SCOPE);
$reopen = $csrf->getOrCreate(DashboardRosterReopenAction::CSRF_SCOPE);
$assert($final !== $reopen && !$csrf->validate(DashboardRosterFinalizeAction::CSRF_SCOPE, $reopen) && !$csrf->validate(DashboardRosterReopenAction::CSRF_SCOPE, $final), 'Lifecycle CSRF scopes are interchangeable.');
$root = dirname(__DIR__, 2);
foreach (['Finalize' => 'finalize', 'Reopen' => 'reopen', 'Validate' => 'validate'] as $action => $endpoint) {
    $source = file_get_contents($root . "/src/Services/Http/Api/Admin/DashboardRoster{$action}Action.php");
    $guard = strpos($source, '$this->guard->validate'); $invoke = strpos($source, '$this->service->' . $endpoint);
    $assert($guard !== false && $invoke !== false && $guard < $invoke && str_contains($source, 'UNAUTHENTICATED') && str_contains($source, 'METHOD_NOT_ALLOWED'), 'Missing private/method guard.');
    if ($action !== 'Validate') $assert(strpos($source, '$this->csrf->validate') < $invoke && str_contains($source, "\$method !== 'POST'"), 'Mutation missing CSRF/POST.');
    $thin = file_get_contents($root . "/public/api/admin/rosters/{$endpoint}.php");
    $assert(str_contains($thin, "'/bootstrap.php'") && str_contains($thin, "['controllers']") && !preg_match('/SELECT |UPDATE |INSERT |DELETE FROM /', $thin), 'Endpoint contains SQL or bypasses injected controller/bootstrap.');
}
$lifecycle = file_get_contents($root . '/src/Services/Dashboard/Roster/RosterLifecycleService.php');
$assert(!str_contains($lifecycle, 'MailSender') && !str_contains($lifecycle, 'BookingStatusChange'), 'Roster lifecycle is coupled to booking status/mail.');
echo "OK: strict revision/supervision DTOs, isolated CSRF scopes, private thin endpoints and lifecycle boundaries.\n";
