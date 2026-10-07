<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterLifecycleFixtures.php';

use GeoFort\Security\{AuthMiddleware, SessionGuard};
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\Roster\{RosterStaffManagementService, RosterPlanStaffSelectionService};
use GeoFort\Services\Sql\{RosterStaffManagementSqlRepository, AdminUsersSqlService};
use GeoFort\Services\Http\Api\Admin\{DashboardRosterStaffListAction, DashboardRosterStaffShowAction, DashboardRosterStaffSaveAction, DashboardRosterStaffSelectionSaveAction, DashboardRosterStaffDeactivateAction};
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable consent required\n"; exit(0); }
$db = DisposableBookingMariaDb::create('staff_http'); $pdo = $db->pdo;
$assert = RosterLifecycleFixtures::assert(...);
try {
    RosterLifecycleFixtures::schema($pdo); $s = RosterLifecycleFixtures::services($pdo);
    $service = new RosterStaffManagementService($pdo, $s['staff'], new RosterStaffManagementSqlRepository($pdo));
    $auth = new AuthMiddleware('round_c_test', 'Strict', false);
    $guard = new SessionGuard(new AdminUsersSqlService($pdo, 5), 600, 60);
    $csrf = new CsrfTokenService(); $response = new JsonResponse(new EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test'));
    $list = new DashboardRosterStaffListAction($auth, $guard, $service, $response);
    $show = new DashboardRosterStaffShowAction($auth, $guard, $service, $response);
    $mutate = new DashboardRosterStaffSaveAction($auth, $guard, $csrf, $service, $response);
    $deactivate = new DashboardRosterStaffDeactivateAction($auth, $guard, $csrf, $service, $response);
    $selection = new DashboardRosterStaffSelectionSaveAction($auth, $guard, $csrf, new RosterPlanStaffSelectionService($pdo, $s['plans'], $s['staff']), $response);
    $http = static function (callable $call): array {
        http_response_code(200); ob_start();
        try { $call(); return [http_response_code(), json_decode(ob_get_contents(), true, 32, JSON_THROW_ON_ERROR)]; }
        finally { ob_end_clean(); }
    };
    $post = static fn (string $op, string $body = '{}', ?string $token = null, string $method = 'POST', string $type = 'application/json') =>
        $http(fn () => $mutate->send($op, $method, $type, $token, $body, 'Round C test'));
    $deactivatePost = static fn (string $body = '{}', ?string $token = null, string $method = 'POST', string $type = 'application/json') =>
        $http(fn () => $deactivate->send($method, $type, $token, $body, 'Round C test'));
    $assert($deactivatePost()[0] === 401, 'Deactivation private');
    $assert($http(fn () => $list->send('GET', 'Round C test'))[0] === 401, 'List private');
    $assert($http(fn () => $show->send('GET', ['staffId' => '20'], 'Round C test'))[0] === 401, 'Show private');
    foreach (array_keys(DashboardRosterStaffSaveAction::CSRF_SCOPES) as $op) $assert($post($op)[0] === 401, 'Mutation private');
    $auth->establishAuthenticatedSession(['id' => 1, 'email' => 'admin@example.test', 'name' => 'Admin', 'role' => 'admin'], null, 'Round C test');
    $_SESSION['last_revalidation_time'] = 0;
    $bootstrap = (new GeoFort\Services\Dashboard\DashboardBootstrapService($csrf, 'development'))->build()->toArray();
    $tokens = [];
    foreach (DashboardRosterStaffSaveAction::CSRF_SCOPES as $op => $scope) {
        $tokens[$op] = $csrf->getOrCreate($scope);
        $assert($post($op)[0] === 403 && $post($op, '{}', $csrf->getOrCreate('logout'))[0] === 403, 'Missing/wrong scope CSRF');
        $assert($post($op, '{}', $tokens[$op], 'GET')[0] === 405, 'Mutation rejects GET');
        $assert($post($op, '{}', $tokens[$op], 'POST', 'application/json-invalid')[0] === 422, 'Strict media type');
        foreach (['null', '[]', '{}', 'true', '{'] as $body) $assert($post($op, $body, $tokens[$op])[0] === 422, 'Strict JSON DTO');
    }
    $assert(count(array_unique($tokens)) === 3 && in_array($bootstrap['rosterStaffCreateCsrfToken'], $tokens, true), 'Distinct bootstrap tokens');
    $deactivateToken = $bootstrap['rosterStaffDeactivateCsrfToken'];
    $assert($deactivateToken !== '' && !in_array($deactivateToken, $tokens, true), 'Dedicated deactivation bootstrap CSRF');
    foreach ([null, ...array_values($tokens), $csrf->getOrCreate('logout')] as $wrong) $assert($deactivatePost('{}', $wrong)[0] === 403, 'Deactivation rejects every other action scope');
    foreach (['GET', 'DELETE'] as $method) $assert($deactivatePost('{}', $deactivateToken, $method)[0] === 405, 'No GET mutation or DELETE endpoint');
    $assert($deactivatePost('{}', $deactivateToken, 'POST', 'text/plain')[0] === 422, 'Deactivation strict JSON media');
    $assert($deactivatePost(json_encode(['staffId' => 20, 'expectedVersion' => 1, 'expectedCatalogRevision' => 1, 'impactToken' => 'untrusted']), $deactivateToken)[1]['code'] === 'STALE_STAFF_IMPACT', 'No confirmation without private session signing secret');
    foreach (['null', '[]', '{}', '{', '{"staffId":"21","expectedVersion":1,"expectedCatalogRevision":1,"impactToken":"x"}'] as $invalid) $assert($deactivatePost($invalid, $deactivateToken)[0] === 422, 'Deactivation strict DTO');
    $before = $pdo->query('SELECT * FROM roster_staff_members ORDER BY id')->fetchAll();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $assert($http(fn () => $list->send('GET', 'Round C test'))[0] === 200, 'List works in read-only DB session');
    $assert($http(fn () => $show->send('GET', ['staffId' => '20'], 'Round C test'))[0] === 200, 'Show works in read-only DB session');
    $pdo->exec('SET SESSION TRANSACTION READ WRITE');
    $assert($pdo->query('SELECT * FROM roster_staff_members ORDER BY id')->fetchAll() === $before, 'GET unchanged data');
    foreach (['01', '0', '-1', '1e0', ['1']] as $id) $assert($http(fn () => $show->send('GET', ['staffId' => $id], 'Round C test'))[0] === 422, 'Strict ID');
    $body = ['name' => '<b>Gewone tekst</b>', 'isActive' => true, 'employmentType' => 'volunteer', 'canGuide' => false, 'canCook' => true, 'preferences' => [], 'expectedCatalogRevision' => 1];
    [$status, $created] = $post('create', json_encode($body), $tokens['create']);
    $assert($status === 201 && $created['data']['member']['name'] === '<b>Gewone tekst</b>', 'Real create HTTP stores plain text');
    $id = $created['data']['member']['id'];
    $body = [...$body, 'name' => 'Andere naam', 'staffId' => $id, 'expectedVersion' => 1, 'expectedCatalogRevision' => 2];
    [$status, $preview] = $post('preview-impact', json_encode($body), $tokens['preview-impact']);
    $assert($status === 200 && $preview['data']['impact'] === [], 'Real preview HTTP');
    $assert($post('update', json_encode($body), $tokens['update'])[0] === 409, 'Update requires server confirmation token');
    $body['impactToken'] = $preview['data']['impactToken'];
    [$status, $updated] = $post('update', json_encode($body), $tokens['update']);
    $assert($status === 200 && $updated['data']['member']['version'] === 2 && count($updated['data']['history']) === 2, 'Versioned update HTTP/audit');
    $assert($post('update', json_encode($body), $tokens['update'])[0] === 409, 'Replay conflict');
    $duplicate = ['preferences' => [['moduleKey' => 'Earth-Watch', 'rank' => 1], ['moduleKey' => 'Dynamische-Globe', 'rank' => 1]]];
    $changed = [...$body, 'expectedVersion' => 2, 'expectedCatalogRevision' => 3, ...$duplicate];
    $assert($post('preview-impact', json_encode($changed), $tokens['preview-impact'])[1]['code'] === 'DUPLICATE_STAFF_PREFERENCE_RANK', 'HTTP backend duplicate validation');
    $deactivatePreviewBody = [...$body, 'isActive' => false, 'expectedVersion' => 2, 'expectedCatalogRevision' => 3];
    [$status, $preview] = $post('preview-impact', json_encode($deactivatePreviewBody), $tokens['preview-impact']);
    $assert($status === 200, 'Deactivation impact checked before write');
    $minimal = ['staffId' => $id, 'expectedVersion' => 2, 'expectedCatalogRevision' => 3, 'impactToken' => $preview['data']['impactToken']];
    $assert($deactivatePost(json_encode([...$minimal, 'name' => 'Tampered']), $deactivateToken)[0] === 422, 'Deactivation cannot change other fields');
    [$status, $deactivated] = $deactivatePost(json_encode($minimal), $deactivateToken);
    $assert($status === 200 && !$deactivated['data']['member']['isActive'] && $deactivated['data']['member']['version'] === 3 && count($deactivated['data']['history']) === 3, 'Dedicated POST deactivates with version/audit');
    $oldMember = $updated['data']['member']; $newMember = $deactivated['data']['member'];
    unset($oldMember['isActive'], $oldMember['version'], $newMember['isActive'], $newMember['version']);
    $assert($oldMember === $newMember, 'Dedicated POST changes no other personnel values');
    $assert($deactivatePost(json_encode($minimal), $deactivateToken)[0] === 409, 'Deactivation replay conflict');
    $assert($http(fn () => $selection->send('POST', 'application/json', null, '{}', 'Round C test'))[0] === 403, 'Day selection own CSRF');
    $assert(!str_contains(json_encode($updated), '_roster_staff_impact_secret'), 'Private signing secret never returned');
    session_write_close();
    echo "PASS private personnel HTTP: read-only GETs, strict DTOs/media, own CSRF scopes, create/preview/update/conflict/audit\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); $db->drop(); }
