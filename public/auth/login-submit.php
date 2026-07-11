<?php
declare(strict_types=1);

use GeoFort\Security\AuthMiddleware;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Auth\LoginService;
use GeoFort\Services\Auth\LoginStatus;
use GeoFort\Services\Http\ClientIp\ClientIpResolver;
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
$submittedToken = is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null;
if (!$csrf->validate('login', $submittedToken)) {
    FlashStore::add('login', 'Uw formulier is verlopen. Probeer het opnieuw.', 'error');
    $csrf->rotate('login');
    $redirector->to('/auth/login-page.php');
}

$email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
if ($email === '' || $password === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    FlashStore::add('login', 'Inloggen is niet gelukt. Controleer uw gegevens en probeer het opnieuw.', 'error');
    $csrf->rotate('login');
    $redirector->to('/auth/login-page.php');
}

$ipResult = ClientIpResolver::getClientIp($_SERVER);
$ip = $ipResult->ip ?? '0.0.0.0';
if ($ipResult->hasError) error_log('[Login] Client IP unavailable: ' . ($ipResult->error ?? 'unknown'));
$userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
try {
    $result = $container['auth'][LoginService::class]->login($email, $password, $ip, $userAgent);
} catch (Throwable $e) {
    error_log('[Login] Authentication failed technically: ' . $e->getMessage());
    FlashStore::add('login', 'Inloggen is tijdelijk niet mogelijk. Probeer het later opnieuw.', 'error');
    $csrf->rotate('login');
    $redirector->to('/auth/login-page.php');
}
if ($result->status === LoginStatus::LockedOut) {
    FlashStore::add('login', 'Te veel mislukte pogingen. Probeer het later opnieuw.', 'error');
    $redirector->to('/auth/login-page.php');
}
if (!$result->isSuccessful() || $result->user === null) {
    FlashStore::add('login', 'Inloggen is niet gelukt. Controleer uw gegevens en probeer het opnieuw.', 'error');
    $csrf->rotate('login');
    $redirector->to('/auth/login-page.php');
}
$auth->establishAuthenticatedSession($result->user, $result->previousLoginAt, $userAgent);
$csrf->remove('login');
FlashStore::add('dashboard', 'U bent veilig ingelogd.', 'success');
$redirector->to('/dashboard/index.php');
