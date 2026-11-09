<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Security\{AuthMiddleware, SessionGuard};
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Dashboard\DashboardBootstrapService;
use GeoFort\Services\Http\Api\Admin\DashboardSessionAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Sql\AdminUsersSqlService;

$db = DisposableBookingMariaDb::create('session_idle');
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
ini_set('session.save_path', sys_get_temp_dir());
$auth = new AuthMiddleware('geofort_idle_test_' . bin2hex(random_bytes(4)), 'Strict', false);
$guard = new SessionGuard(new AdminUsersSqlService($db->pdo, 5), 1800, 60);
$csrf = new CsrfTokenService();
$action = new DashboardSessionAction($auth, $guard, $csrf, new JsonResponse(new EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test')));
$login = static function () use ($auth): void {
    $auth->startPublicSession();
    $auth->establishAuthenticatedSession(['id'=>1,'name'=>'Test','email'=>'admin@example.test','role'=>'admin'], null, 'Idle test');
};
$http = static function (string $method = 'GET', ?string $token = null, string $agent = 'Idle test') use ($action): array {
    http_response_code(200); ob_start();
    try {
        $action->send($method, $token, $agent);
        return [http_response_code(), json_decode(ob_get_contents(), true, 32, JSON_THROW_ON_ERROR)];
    } finally { ob_end_clean(); }
};
try {
    $assert($http()[0] === 401, 'Anonymous request accepted');
    $login();
    $_SESSION['LAST_ACTIVITY'] = time() - 1200;
    $last = $_SESSION['LAST_ACTIVITY'];
    [$status, $data] = $http();
    $assert($status === 200 && $data['remainingSeconds'] <= 600 && $data['remainingSeconds'] > 590, 'Server deadline missing');
    $assert($_SESSION['LAST_ACTIVITY'] === $last, 'Background GET renewed idle session');
    $assert($csrf->validate(DashboardSessionAction::CSRF_SCOPE, $data['csrfToken']), 'Separate activity CSRF scope missing');
    $token = $data['csrfToken'];
    foreach ([null, 'bad', $csrf->getOrCreate('logout'), $csrf->getOrCreate('manage-roster-session')] as $wrong) {
        $assert($http('POST', $wrong)[0] === 403, 'Wrong CSRF accepted');
        $assert($_SESSION['LAST_ACTIVITY'] === $last, 'Rejected POST renewed session');
    }
    $assert($http('PUT', $token)[0] === 405 && $_SESSION['LAST_ACTIVITY'] === $last, 'Unsupported method changed session');
    [$status, $data] = $http('POST', $token);
    $assert($status === 200 && $data['remainingSeconds'] === 1800 && $_SESSION['LAST_ACTIVITY'] > $last, 'Actual activity did not renew');
    $last = $_SESSION['LAST_ACTIVITY'];
    $assert($http()[0] === 200 && $_SESSION['LAST_ACTIVITY'] === $last, 'Second tab status check changed deadline');
    $bootstrap = (new DashboardBootstrapService($csrf, 'development', 3600))->build()->toArray();
    $assert($bootstrap['sessionTimeoutSeconds'] === 3600, 'Configured development timeout not exposed');
    foreach (['GET', 'POST'] as $method) {
        $login(); $token = $csrf->getOrCreate(DashboardSessionAction::CSRF_SCOPE);
        $_SESSION['LAST_ACTIVITY'] = time() - 1800;
        $assert($http($method, $token)[0] === 401, 'Expired session revived at exact boundary');
        $assert(!isset($_SESSION['loggedin'], $_SESSION['user_id'], $_SESSION['LAST_ACTIVITY']), 'Authentication not cleared');
    }
    $login();
    $assert($http('GET', null, 'Changed agent')[0] === 401, 'Agent binding lost');
    $login(); $_SESSION['last_revalidation_time'] = 0;
    $db->pdo->exec('UPDATE admin_users SET is_active=0 WHERE id=1');
    $assert($http()[0] === 401 && !isset($_SESSION['loggedin']), 'Inactive account kept signed in');
    $db->pdo->exec('UPDATE admin_users SET is_active=1 WHERE id=1');
    $login(); $_SESSION['LAST_ACTIVITY'] = time() - 100;
    $assert($guard->validate('Idle test') && $_SESSION['LAST_ACTIVITY'] >= time() - 1, 'Regular authenticated requests no longer work');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    $db->drop();
}
echo "PASS: {$checks} session deadline, passive checks, CSRF, multi-tab, expiry, agent and account checks; disposable database removed\n";
