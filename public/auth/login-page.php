<?php
declare(strict_types=1);

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Http\Redirect\HeaderRedirector;
use GeoFort\Services\ViteService;
use GeoFort\Support\FlashStore;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$container = require dirname(__DIR__, 2) . '/bootstrap.php';
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
$auth = $container['auth'][AuthMiddleware::class];
$auth->startPublicSession();
$guard = $container['auth'][SessionGuard::class];
if (($_SESSION['loggedin'] ?? false) === true && $guard->validate((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
    $container['http'][HeaderRedirector::class]->to('/dashboard/index.php');
}
$messages = FlashStore::pull('login');
$csrfToken = $container['auth'][CsrfTokenService::class]->getOrCreate('login');
$vite = $container['http'][ViteService::class];
require TEMPLATE_PATH . '/auth/login-page.php';
