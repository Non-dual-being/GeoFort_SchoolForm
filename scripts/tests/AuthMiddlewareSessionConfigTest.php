<?php

declare(strict_types=1);

use GeoFort\Security\AuthMiddleware;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$sessionPath = getenv('TEMP');
if (!is_string($sessionPath) || $sessionPath === '') {
    throw new RuntimeException('Writable TEMP directory is required for this test.');
}
ini_set('session.save_path', $sessionPath);
$auth = new AuthMiddleware('geofort_test_session', 'Lax', true);
$auth->startPublicSession();
$params = session_get_cookie_params();

$expected = [
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
];
foreach ($expected as $key => $value) {
    if (($params[$key] ?? null) !== $value) {
        throw new RuntimeException("Unexpected session cookie setting for {$key}.");
    }
}
if (ini_get('session.use_only_cookies') !== '1' || ini_get('session.use_strict_mode') !== '1') {
    throw new RuntimeException('Strict cookie-only sessions are not enabled.');
}

session_destroy();
echo "AuthMiddlewareSessionConfigTest OK\n";
