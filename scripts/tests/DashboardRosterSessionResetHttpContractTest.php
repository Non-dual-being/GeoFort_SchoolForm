<?php

declare(strict_types=1);
require __DIR__ . '/Support/RosterSessionResetFixtures.php';

use GeoFort\Security\{AuthMiddleware, SessionGuard};
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\DashboardBootstrapService;
use GeoFort\Services\Sql\AdminUsersSqlService;
use GeoFort\Services\Http\Api\Admin\DashboardRosterResetSessionsAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;

if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') { echo "SKIP disposable consent required\n"; exit(0); }
$db = DisposableBookingMariaDb::create('reset_http');
$pdo = RosterSessionResetFixtures::connection($db->database);
$assert = RosterLifecycleFixtures::assert(...);
try {
    RosterLifecycleFixtures::schema($pdo);
    $s = RosterSessionResetFixtures::services($pdo);
    $plan = RosterLifecycleFixtures::validPlan($pdo, $s);
    $auth = new AuthMiddleware('round_d_reset_test', 'Strict', false);
    $guard = new SessionGuard(new AdminUsersSqlService($pdo, 5), 600, 60);
    $csrf = new CsrfTokenService();
    $action = new DashboardRosterResetSessionsAction($auth, $guard, $csrf, $s['reset'], new JsonResponse(new EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test')));
    $http = static function (string $body = '{}', ?string $token = null, string $method = 'POST', string $type = 'application/json') use ($action): array {
        http_response_code(200); ob_start();
        try {
            $action->send($method, $type, $token, $body, 'Round D test');
            return [http_response_code(), json_decode(ob_get_contents(), true, 32, JSON_THROW_ON_ERROR)];
        } finally { ob_end_clean(); }
    };
    $body = json_encode(['planId' => $plan['id'], 'expectedRevision' => $plan['revision']], JSON_THROW_ON_ERROR);
    $before = RosterSessionResetFixtures::state($pdo);
    $assert($http($body)[0] === 401, 'Private auth required');
    $auth->establishAuthenticatedSession(['id' => 1, 'email' => 'admin@example.test', 'name' => 'Admin', 'role' => 'admin'], null, 'Round D test');
    $_SESSION['last_revalidation_time'] = 0;
    $bootstrap = (new DashboardBootstrapService($csrf, 'development'))->build()->toArray();
    $token = $bootstrap['rosterResetSessionsCsrfToken'];
    $assert($csrf->validate(DashboardRosterResetSessionsAction::CSRF_SCOPE, $token), 'Own bootstrap token');
    foreach (['manage-roster-session', 'generate-roster-plan', 'finalize-roster-plan', 'reopen-roster-plan', 'logout'] as $scope) {
        $assert($http($body, $csrf->getOrCreate($scope))[0] === 403, 'Reject another CSRF scope: ' . $scope);
    }
    $assert($http($body)[0] === 403, 'Missing CSRF rejected');
    foreach (['GET', 'DELETE', 'PUT'] as $method) $assert($http($body, $token, $method)[0] === 405, 'POST only');
    foreach (['text/plain', 'application/json-invalid', ''] as $type) $assert($http($body, $token, 'POST', $type)[0] === 422, 'Strict media type');
    foreach (['null', '[]', 'true', '{}', '{', '{"planId":"1","expectedRevision":1}', '{"planId":1,"expectedRevision":0}', '{"planId":1.0,"expectedRevision":1}', '{"planId":1,"expectedRevision":true}', '{"planId":1,"expectedRevision":1,"force":true}', str_repeat(' ', 4097)] as $invalid) {
        $assert($http($invalid, $token)[0] === 422, 'Strict reset DTO');
    }
    $assert($http(json_encode(['planId' => 999999, 'expectedRevision' => 1]), $token)[0] === 404, 'Missing plan');
    $stale = $http(json_encode(['planId' => $plan['id'], 'expectedRevision' => 9]), $token);
    $assert($stale[0] === 409 && $stale[1]['code'] === 'ROSTER_REVISION_CONFLICT', 'Stale HTTP 409');
    $assert(RosterSessionResetFixtures::state($pdo) === $before, 'Every rejected request leaves all database values intact');

    [$status, $reply] = $http($body, $token, 'POST', 'application/json; charset=utf-8');
    $assert($status === 200 && $reply['ok'] && $reply['data']['deletedSessionCount'] === count($plan['planning']['sessions'])
        && $reply['data']['revision'] === $plan['revision'] + 1 && $reply['data']['alreadyEmpty'] === false, 'HTTP reset succeeds');
    $assert(array_keys($reply['data']) === ['planId', 'revision', 'deletedSessionCount', 'alreadyEmpty'], 'Safe response metadata only');
    $emptyBefore = RosterSessionResetFixtures::state($pdo); $pdo->statements = [];
    [$status, $empty] = $http(json_encode(['planId' => $plan['id'], 'expectedRevision' => $reply['data']['revision']]), $token);
    $writes = $pdo->writes();
    $assert($status === 200 && $empty['data']['alreadyEmpty'] && $empty['data']['deletedSessionCount'] === 0
        && $empty['data']['revision'] === $reply['data']['revision'], 'HTTP no-op response');
    $assert($writes === [] && RosterSessionResetFixtures::state($pdo) === $emptyBefore, 'HTTP no-op has no write SQL and unchanged every field');
    $assert($http($body, $token)[0] === 409, 'Stale revision stays invalid even when empty');

    $finalPlan = RosterLifecycleFixtures::validPlan($pdo, $s, 28, 2, 2, '2028-10-12');
    $published = $s['lifecycle']->finalize($finalPlan['id'], $finalPlan['revision'], 1);
    $before = RosterSessionResetFixtures::state($pdo);
    $result = $http(json_encode(['planId' => $published['id'], 'expectedRevision' => $published['revision']]), $token);
    $assert($result[0] === 409 && $result[1]['code'] === 'PLAN_NOT_EDITABLE' && RosterSessionResetFixtures::state($pdo) === $before, 'Published HTTP rejected without writes');

    $failurePlan = RosterLifecycleFixtures::validPlan($pdo, $s, 28, 2, 2, '2028-10-13');
    $before = RosterSessionResetFixtures::state($pdo);
    $pdo->exec("CREATE TRIGGER reset_http_failure BEFORE UPDATE ON roster_plans FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Private fixture failure'");
    try {
        $failure = $http(json_encode(['planId' => $failurePlan['id'], 'expectedRevision' => $failurePlan['revision']]), $token);
        $assert($failure[0] === 500 && !str_contains(json_encode($failure[1]), 'Private fixture'), 'Safe server error without SQL/exception details');
    } finally { $pdo->exec('DROP TRIGGER reset_http_failure'); }
    $assert(RosterSessionResetFixtures::state($pdo) === $before, 'HTTP failure rolls back all session and metadata writes');
    session_write_close();
    echo "PASS reset private auth/POST/own CSRF/strict JSON/404/409/real reset/no-op without writes/transactional 500\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); $db->drop(); }

