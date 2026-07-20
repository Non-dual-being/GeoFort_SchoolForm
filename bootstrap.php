<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GeoFort\Database\Connector;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
use GeoFort\Services\Http\Redirect\HeaderRedirector;
use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Auth\LoginService;
use GeoFort\Services\Auth\LoginSecurityService;
use GeoFort\Services\Http\PrivatePageBootstrapper;
use GeoFort\Services\Sql\AdminUsersSqlService;
use GeoFort\Services\Sql\LoginAttemptsSqlService;
use GeoFort\Services\ViteService;
use GeoFort\Controllers\Dashboard\DashboardAppController;
use GeoFort\Services\Dashboard\DashboardBootstrapService;
use GeoFort\Services\Dashboard\Booking\DashboardBookingFilterParser;
use GeoFort\Services\Dashboard\Booking\DashboardBookingDetailService;
use GeoFort\Services\Dashboard\Booking\DashboardBookingListService;
use GeoFort\Services\Http\Api\Admin\DashboardBookingDetailAction;
use GeoFort\Services\Http\Api\Admin\DashboardBookingListAction;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Sql\DashboardBookingSqlService;
use GeoFort\Services\Sql\DashboardBookingDetailSqlService;
use GeoFort\Services\Booking\Status\BookingStatusChangeServiceFactory;
use GeoFort\Services\Http\Api\Admin\DashboardBookingStatusUpdateAction;
use GeoFort\Services\Http\Api\Admin\BookingStatusUpdateResponseMapper;


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
    $getPositiveInt = static function (string $key, string $default) use ($getEnvValue): int {
        $value = $getEnvValue($key, $default);
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException("$key must be a positive integer");
        }
        return (int) $value;
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

    $base_url = $getEnvValueOrFail('BASE_URL');
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

    $auth_session_timeout_production = $getPositiveInt('AUTH_SESSION_TIMEOUT_PRODUCTION', '1800');
    $auth_session_timeout_development = $getPositiveInt('AUTH_SESSION_TIMEOUT_DEVELOPMENT', '3600');
    $auth_revalidation_seconds = $getPositiveInt('AUTH_REVALIDATION_SECONDS', '900');
    $auth_max_admins = $getPositiveInt('AUTH_MAX_ADMINS', '5');
    $auth_session_cookie_name = trim($getEnvValue('AUTH_SESSION_COOKIE_NAME', 'geofort_admin_session'));
    $auth_session_cookie_samesite = trim($getEnvValue('AUTH_SESSION_COOKIE_SAMESITE', 'Lax'));
    $login_lockout_attempts = $getPositiveInt('LOGIN_LOCKOUT_ATTEMPTS', '5');
    $login_attempt_window_seconds = $getPositiveInt('LOGIN_ATTEMPT_WINDOW_SECONDS', '900');
    $login_lockout_base_seconds = $getPositiveInt('LOGIN_LOCKOUT_BASE_SECONDS', '120');
    $login_lockout_max_seconds = $getPositiveInt('LOGIN_LOCKOUT_MAX_SECONDS', '1800');

    if ($auth_session_cookie_name === '' || preg_match('/^[A-Za-z0-9_-]+$/', $auth_session_cookie_name) !== 1) {
        throw new RuntimeException('AUTH_SESSION_COOKIE_NAME is invalid');
    }
    if ($auth_session_cookie_samesite !== 'Lax') {
        throw new RuntimeException('AUTH_SESSION_COOKIE_SAMESITE must be Lax');
    }
    if ($login_lockout_max_seconds < $login_lockout_base_seconds) {
        throw new RuntimeException('LOGIN_LOCKOUT_MAX_SECONDS must not be lower than LOGIN_LOCKOUT_BASE_SECONDS');
    }

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
    $environmentBaseUrlProvider = new EnvironmentBaseUrlProvider(
        environment: $app_env,
        baseUrl: $base_url,
    );
    $headerRedirector = new HeaderRedirector($environmentBaseUrlProvider);

    $pdo = Connector::getConnection(
        host: $db_host,
        dbname: $db_name,
        user: $db_user,
        pass: $db_pass,
        port: $db_port
    );
    $adminUsersSqlService = new AdminUsersSqlService($pdo, $auth_max_admins);
    $loginAttemptsSqlService = new LoginAttemptsSqlService($pdo);
    $loginSecurityService = new LoginSecurityService(
        $loginAttemptsSqlService,
        $login_lockout_attempts,
        $login_attempt_window_seconds,
        $login_lockout_base_seconds,
        $login_lockout_max_seconds,
    );
    $loginService = new LoginService($adminUsersSqlService, $loginSecurityService);
    $csrfTokenService = new CsrfTokenService();
    $authMiddleware = new AuthMiddleware(
        $auth_session_cookie_name,
        $auth_session_cookie_samesite,
        str_starts_with(
            $environmentBaseUrlProvider->getBaseUrl(),
            'https://',
        ),
    );
    $sessionGuard = new SessionGuard(
        $adminUsersSqlService,
        $app_env === 'production' ? $auth_session_timeout_production : $auth_session_timeout_development,
        $auth_revalidation_seconds,
    );
    $privatePageBootstrapper = new PrivatePageBootstrapper($pdo, $authMiddleware, $sessionGuard, $headerRedirector);
    $viteService = new ViteService($app_env, $vite_build_path, $vite_dev_server_url);
    $dashboardBootstrapService = new DashboardBootstrapService($csrfTokenService, $app_env);
    $dashboardBookingSqlService = new DashboardBookingSqlService($pdo);
    $dashboardBookingDetailSqlService = new DashboardBookingDetailSqlService($pdo);
    $dashboardBookingFilterParser = new DashboardBookingFilterParser();
    $dashboardBookingListService = new DashboardBookingListService($dashboardBookingSqlService);
    $dashboardBookingDetailService = new DashboardBookingDetailService($dashboardBookingDetailSqlService);
    $dashboardBookingListAction = new DashboardBookingListAction(
        $privatePageBootstrapper,
        $dashboardBookingFilterParser,
        $dashboardBookingListService,
        new JsonResponse($environmentBaseUrlProvider),
    );
    $dashboardBookingDetailAction = new DashboardBookingDetailAction(
        $privatePageBootstrapper,
        $dashboardBookingDetailService,
        new JsonResponse($environmentBaseUrlProvider),
    );
    $bookingStatusChangeService = (new BookingStatusChangeServiceFactory($pdo))->create();
    $dashboardBookingStatusUpdateAction = new DashboardBookingStatusUpdateAction(
        $authMiddleware,
        $sessionGuard,
        $csrfTokenService,
        $bookingStatusChangeService,
        new BookingStatusUpdateResponseMapper(),
        new JsonResponse($environmentBaseUrlProvider),
    );
    $dashboardAppController = new DashboardAppController(
        $privatePageBootstrapper,
        $dashboardBootstrapService,
        $viteService,
    );

    $container['db'] = [
        Connector::class => $pdo,
    ];

    $container['config'] = [
        'app_env' => $app_env,
        'app_debug' => $app_debug,
        'app_cooldown' => $app_cooldown,
        'base_url' => $environmentBaseUrlProvider->getBaseUrl(),
        'vite_dev_server_url' => $vite_dev_server_url,
        'vite_build_path' => $vite_build_path,
        'database' => [
            'host' => $db_host,
            'name' => $db_name,
            'user' => $db_user,
            'pass' => $db_pass,
            'port' => $db_port,
        ],
        'auth' => [
            'session_timeout_production' => $auth_session_timeout_production,
            'session_timeout_development' => $auth_session_timeout_development,
            'revalidation_seconds' => $auth_revalidation_seconds,
            'max_admins' => $auth_max_admins,
            'session_cookie_name' => $auth_session_cookie_name,
            'session_cookie_samesite' => $auth_session_cookie_samesite,
            'lockout_attempts' => $login_lockout_attempts,
            'attempt_window_seconds' => $login_attempt_window_seconds,
            'lockout_base_seconds' => $login_lockout_base_seconds,
            'lockout_max_seconds' => $login_lockout_max_seconds,
        ],
    ];

    $container['http'] = [
        EnvironmentBaseUrlProvider::class => $environmentBaseUrlProvider,
        HeaderRedirector::class => $headerRedirector,
        PrivatePageBootstrapper::class => $privatePageBootstrapper,
        ViteService::class => $viteService,
    ];

    $container['sql'] = [
        AdminUsersSqlService::class => $adminUsersSqlService,
        LoginAttemptsSqlService::class => $loginAttemptsSqlService,
        DashboardBookingSqlService::class => $dashboardBookingSqlService,
        DashboardBookingDetailSqlService::class => $dashboardBookingDetailSqlService,
    ];

    $container['auth'] = [
        AuthMiddleware::class => $authMiddleware,
        SessionGuard::class => $sessionGuard,
        CsrfTokenService::class => $csrfTokenService,
        LoginSecurityService::class => $loginSecurityService,
        LoginService::class => $loginService,
    ];

    $container['dashboard'] = [
        DashboardBootstrapService::class => $dashboardBootstrapService,
        DashboardBookingFilterParser::class => $dashboardBookingFilterParser,
        DashboardBookingListService::class => $dashboardBookingListService,
        DashboardBookingDetailService::class => $dashboardBookingDetailService,
    ];

    $container['controllers'] = [
        DashboardAppController::class => $dashboardAppController,
        DashboardBookingListAction::class => $dashboardBookingListAction,
        DashboardBookingDetailAction::class => $dashboardBookingDetailAction,
        DashboardBookingStatusUpdateAction::class => $dashboardBookingStatusUpdateAction,
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
