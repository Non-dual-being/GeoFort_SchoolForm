<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GeoFort\Database\Connector;
use GeoFort\Services\Http\GlobalBaseUrlProvider;
use GeoFort\Services\Http\HeaderRedirector;


error_reporting(E_ALL);
ini_set('log_errors', '1');
date_default_timezone_set('Europe/Amsterdam');

$defaultError = 'Kritische fout, neem voor support contact op met onderwijs@geofort.nl';
$container = [];

if (!defined('TEMPLATE_PATH')) {
    define('TEMPLATE_PATH', __DIR__ . '/templates');
}

if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', __DIR__ . '/public');
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $getEnvValueOrFail = static fn(string $key): string =>
        $_ENV[$key]
        ?? $_SERVER[$key]
        ?? (
            (false !== ($v = getenv($key)))
                ? (string) $v
                : throw new RuntimeException("$key missing in env")
        );
    /**
     * runtime zit in globale namespace and bootstrap has no namespace
     */

    $app_env = $getEnvValueOrFail('APP_ENV');
    $app_cooldown = (int) $getEnvValueOrFail('APP_COOLDOWN');

    $base_url = rtrim($_ENV['BASE_URL'] ?? $_SERVER['BASE_URL'] ?? '', '/');

    $db_host = $getEnvValueOrFail('DB_HOST');
    $db_name = $getEnvValueOrFail('DB_NAME');
    $db_user = $getEnvValueOrFail('DB_USER');
    $db_pass = $getEnvValueOrFail('DB_PASS');
    $db_port = $getEnvValueOrFail('DB_PORT'); //no int, needs to be string

    $mail_host = $getEnvValueOrFail('MAIL_HOST');
    $mail_port = (int) $getEnvValueOrFail('MAIL_PORT');
    $mail_smtp_debug = (int) $getEnvValueOrFail('MAIL_SMTP_DEBUG');
    $mail_planner_email_pwd = $getEnvValueOrFail('MAIL_PLANNER_EMAIL_PWD');
    $mail_planner_email_user = $getEnvValueOrFail('MAIL_PLANNER_EMAIL_USER');
    $mail_receiver_email_user = $getEnvValueOrFail(
        'MAIL_RECEIVER_DEVELOPMENT_EMAIL'
    );

    if (!in_array($app_env, ['development', 'production'], true)) {
        throw new RuntimeException('Invalid APP_ENV');
    }

    if ($app_env === 'development') {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
    } else {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }
} catch (Dotenv\Exception\InvalidPathException $e) {
    error_log('Could not find env file: ' . $e->getMessage());
    die($defaultError);
} catch (RuntimeException $e) {
    error_log('Missing or invalid env value: ' . $e->getMessage());
    die($defaultError);
}

try {
    $globalBaseUrlProvider = new GlobalBaseUrlProvider($app_env);
    $headerRedirector = new HeaderRedirector($globalBaseUrlProvider);

    $pdo = Connector::getConnection(
        host: $db_host,
        dbname: $db_name,
        user: $db_user,
        pass: $db_pass,
        port: $db_port
    );

    $container['db'] = [
        Connector::class => $pdo,
    ];

    $container['config'] = [
        'app_env' => $app_env,
        'app_cooldown' => $app_cooldown,
        'base_url' => $base_url,
    ];

    $container['http'] = [
        GlobalBaseUrlProvider::class => $globalBaseUrlProvider,
        HeaderRedirector::class => $headerRedirector,
    ];

    $container['mail'] = [
        'mail_host' => $mail_host,
        'mail_port' => $mail_port,
        'mail_smtp_debug' => $mail_smtp_debug,
        'mail_planner_email_pwd' => $mail_planner_email_pwd,
        'mail_planner_email_user' => $mail_planner_email_user,
        'mail_receiver_email_user' => $mail_receiver_email_user,
    ];

    return $container;
} catch (Throwable $e) {
    error_log('Bootstrap error: ' . $e->getMessage());
    die($defaultError);
}