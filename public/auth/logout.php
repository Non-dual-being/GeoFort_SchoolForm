<?php
declare(strict_types=1);

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Http\Redirect\HeaderRedirector;
use GeoFort\Support\FlashStore;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$container = require dirname(__DIR__, 2) . '/bootstrap.php';
$auth = $container['auth'][AuthMiddleware::class];
$auth->startPublicSession();
$csrf = $container['auth'][CsrfTokenService::class];
$redirector = $container['http'][HeaderRedirector::class];
$token = is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null;
if (!$csrf->validate('logout', $token)) {
    error_log('[Logout] Invalid CSRF token');
    $valid = $container['auth'][SessionGuard::class]->validate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    FlashStore::add($valid ? 'dashboard' : 'login', 'De uitlogactie kon niet worden bevestigd. Probeer het opnieuw.', 'error');
    $redirector->to($valid ? '/dashboard/index.php' : '/auth/login-page.php');
}
$auth->destroyAndRestartAnonymousSession();
FlashStore::add('login', 'U bent uitgelogd.', 'success');
$redirector->to('/auth/login-page.php');
