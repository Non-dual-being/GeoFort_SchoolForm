<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Security\{AuthMiddleware, SessionGuard};
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Sql\{AdminUsersSqlService, BookingProgramAccessSqlRepository};
use GeoFort\Services\Booking\Program\BookingProgramAccessService;
use GeoFort\Services\Http\Api\Admin\DashboardProgramAccessAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE booking_program_access(id INTEGER PRIMARY KEY, five_module_open INTEGER, revision INTEGER, updated_by INTEGER, updated_at TEXT)');
$pdo->exec('INSERT INTO booking_program_access VALUES(1,1,0,NULL,NULL)');
$auth = new AuthMiddleware('four_module_http_test', 'Lax', false);
$auth->startPublicSession();
$csrf = new CsrfTokenService();
$service = new BookingProgramAccessService(new BookingProgramAccessSqlRepository($pdo));
$action = new DashboardProgramAccessAction($auth, new SessionGuard(new AdminUsersSqlService($pdo, 10), 3600, 3600), $csrf, $service,
    new JsonResponse(new EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test')));
$checks = 0;
$assert = static function (bool $ok, string $why) use (&$checks): void { if (!$ok) throw new RuntimeException($why); $checks++; };
$call = static function (string $method, ?string $token = null, string $body = '', string $type = 'application/json') use ($action): array {
    ob_start(); $action->send($method, $type, $token, $body, 'Four module test');
    $body = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    return [http_response_code(), $body];
};
try {
    $assert($call('GET')[0] === 401, 'Anonymous access accepted');
    $auth->establishAuthenticatedSession(['id'=>1,'email'=>'test@example.test','name'=>'Test','role'=>'viewer'], null, 'Four module test');
    $assert($call('GET')[0] === 403, 'Viewer can read settings');
    $_SESSION['user_role'] = 'planner';
    [$status, $result] = $call('GET');
    $assert($status === 200 && $result['data']['fiveModuleOpen'], 'Planner cannot load setting');
    $token = $result['csrfToken'];
    $payload = json_encode(['fiveModuleOpen'=>false,'revision'=>0]);
    $assert($call('POST', null, $payload)[0] === 403, 'Missing CSRF accepted');
    $assert($call('POST', $csrf->getOrCreate('logout'), $payload)[0] === 403, 'Other action CSRF accepted');
    $assert($call('DELETE', $token, $payload)[0] === 405, 'Wrong method accepted');
    $assert($call('POST', $token, $payload, 'text/plain')[0] === 422, 'Wrong content type accepted');
    $assert($call('POST', $token, 'null')[0] === 422, 'Null body accepted');
    $assert($call('POST', $token, '{')[0] === 422, 'Malformed JSON accepted');
    $assert($service->get()['revision'] === 0, 'Rejected requests mutated settings');
    $assert($call('POST', $token, $payload)[0] === 200 && !$service->get()['fiveModuleOpen'], 'Valid close failed');
    $assert($call('POST', $token, $payload)[0] === 409, 'Stale revision accepted');
    $_SESSION['LAST_ACTIVITY'] = time() - 4000;
    $assert($call('POST', $token, $payload)[0] === 401, 'Expired session accepted');
    $assert($service->get()['revision'] === 1, 'Invalid repeat changed revision');
} finally { $_SESSION = []; session_destroy(); }
echo "PASS: {$checks} program-access HTTP auth, CSRF, validation and concurrency checks\n";
