<?php
declare(strict_types=1);
// Test server only; all HTTP APIs and rendering below are the actual production entrypoints.
if (PHP_SAPI !== 'cli-server' || getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE') throw new RuntimeException('Test router only');
$settings = json_decode(file_get_contents((string) getenv('FOUR_PUBLIC_ENV')), true, 512, JSON_THROW_ON_ERROR);
ini_set('error_log', dirname((string) getenv('FOUR_PUBLIC_ENV')) . '/php-errors.log');
if (!preg_match('/^geofort_four_public_disposable_test_[a-f0-9]{8}$/', $settings['DB_NAME'] ?? '')
    || $settings['MAIL_HOST'] !== '127.0.0.1') throw new RuntimeException('Unsafe test configuration');
foreach ($settings as $key => $value) { $_ENV[$key] = (string) $value; $_SERVER[$key] = (string) $value; putenv($key.'='.$value); }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = dirname(__DIR__, 3) . '/public';
$target = realpath($public . ($path === '/' ? '/index.php' : $path));
if (!$target || !str_starts_with(strtolower(str_replace('\\','/',$target)), strtolower(str_replace('\\','/',realpath($public))).'/')) { http_response_code(404); echo 'Test route not found: ' . $path; return; }
if (pathinfo($target, PATHINFO_EXTENSION) !== 'php') return false;
require $target;
