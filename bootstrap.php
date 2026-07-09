<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GeoFort\Database\Connector;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Http\Redirect\HeaderRedirector;


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

    $getEnvValue = static fn(string $key, ?string $default = null): string =>
        $_ENV[$key]
        ?? $_SERVER[$key]
        ?? (
            (false !== ($v = getenv($key)))
                ? (string) $v
                : (string) $default
        );

    $getEnvValueOrFail = static function (string $key) use ($getEnvValue): string {
        $value = $getEnvValue($key, null);

        if ($value === '') {
            throw new RuntimeException("$key missing in env");
        }

        return $value;
    };

    $parseEmailList = static function (string $value): array {
        $emails = [];

        foreach (explode(',', $value) as $email) {
            $email = trim($email);

            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return $emails;
    };
    /**
     * runtime zit in globale namespace and bootstrap has no namespace
     */

    $app_env = $getEnvValue('APP_ENV', 'production');
    $app_debug = filter_var(
        $getEnvValue('APP_DEBUG', 'false'),
        FILTER_VALIDATE_BOOLEAN
    );
    $app_cooldown = (int) $getEnvValue('APP_COOLDOWN', '60');

    $base_url = rtrim($getEnvValue('BASE_URL', ''), '/');
    $vite_dev_server_url = rtrim(
        $getEnvValue('VITE_DEV_SERVER_URL', 'https://onderwijsformulier.test:5241'),
        '/'
    );
    $vite_build_path = $getEnvValue('VITE_BUILD_PATH', 'public/build');

    if (!str_starts_with($vite_build_path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\/\\\\]/', $vite_build_path)) {
        $vite_build_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $vite_build_path);
    }

    $db_host = $getEnvValueOrFail('DB_HOST');
    $db_name = $getEnvValueOrFail('DB_NAME');
    $db_user = $getEnvValueOrFail('DB_USER');
    $db_pass = $getEnvValue('DB_PASS', '');
    $db_port = $getEnvValue('DB_PORT', '3306'); //no int, needs to be string

    $mail_host = $getEnvValueOrFail('MAIL_HOST');
    $mail_port = (int) $getEnvValue('MAIL_PORT', '587');
    $mail_encryption = $getEnvValue('MAIL_ENCRYPTION', 'tls');
    $mail_smtp_debug = (int) $getEnvValue('MAIL_SMTP_DEBUG', '0');
    $mail_planner_email_pwd = $getEnvValue('MAIL_PLANNER_EMAIL_PWD', '');
    $mail_planner_email_user = $getEnvValueOrFail('MAIL_PLANNER_EMAIL_USER');
    $mail_from_email = $getEnvValue('MAIL_FROM_EMAIL', $mail_planner_email_user);
    $mail_from_name = $getEnvValue('MAIL_FROM_NAME', 'GeoFort Onderwijs');
    $mail_receiver_development_email = $getEnvValue('MAIL_RECEIVER_DEVELOPMENT_EMAIL', '');
    $mail_cc_emails = $parseEmailList($getEnvValue('MAIL_CC_EMAILS', ''));

    if (!in_array($app_env, ['development', 'production'], true)) {
        throw new RuntimeException('Invalid APP_ENV');
    }

    if ($app_env === 'development' && trim($mail_receiver_development_email) === '') {
        throw new RuntimeException('MAIL_RECEIVER_DEVELOPMENT_EMAIL missing in development');
    }

    if ($app_debug) {
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
    $EnvironmentBaseUrlProvider = new EnvironmentBaseUrlProvider(
        environment: $app_env,
        baseUrl: $base_url
    );
    $headerRedirector = new HeaderRedirector($EnvironmentBaseUrlProvider);

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
        'app_debug' => $app_debug,
        'app_cooldown' => $app_cooldown,
        'base_url' => $base_url,
        'vite_dev_server_url' => $vite_dev_server_url,
        'vite_build_path' => $vite_build_path,
        'database' => [
            'host' => $db_host,
            'name' => $db_name,
            'user' => $db_user,
            'pass' => $db_pass,
            'port' => $db_port,
        ],
    ];

    $container['http'] = [
        EnvironmentBaseUrlProvider::class => $EnvironmentBaseUrlProvider,
        HeaderRedirector::class => $headerRedirector,
    ];

    $container['mail'] = [
        'mail_host' => $mail_host,
        'mail_port' => $mail_port,
        'mail_encryption' => $mail_encryption,
        'mail_smtp_debug' => $mail_smtp_debug,
        'mail_planner_email_pwd' => $mail_planner_email_pwd,
        'mail_planner_email_user' => $mail_planner_email_user,
        'mail_from_email' => $mail_from_email,
        'mail_from_name' => $mail_from_name,
        'mail_receiver_development_email' => $mail_receiver_development_email,
        'mail_receiver_email_user' => $mail_receiver_development_email,
        'mail_cc_emails' => $mail_cc_emails,
    ];

    return $container;
} catch (Throwable $e) {
    error_log('Bootstrap error: ' . $e->getMessage());
    die($defaultError);
}
