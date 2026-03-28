<?php 
declare (strict_types=1);
use Dotenv\Dotenv; /**vlucas/phpdotenv libaray */
require __DIR__ . '/vendor/autoload.php';
use GeoFort\Services\Http\GlobalBaseUrlProvider;
use GeoFort\Services\Http\HeaderRedirector;
use GeoFort\Database\Connector;



/** DEFAULT SETTINGS */
error_reporting(E_ALL);
ini_set('log_errors', '1');
date_default_timezone_set('Europe/Amsterdam');
$defaultError = "Kritische fout, neem voor support contact op met onderwijs@geofort.nl";
$container = [];

/** PADEN DEFINIEREN */
if (!defined('TEMPLATE_PATH')) define('TEMPLATE_PATH', __DIR__ . '/templates');
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', __DIR__ . '/public');



/**=================================ENV LOAD ============================ */
try {
    /** load the env */
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $getEnvValueOrFail = static fn (string $key): string => 
        $_ENV[$key] ??
        $_SERVER[$key] ??
        (
            (false !== ($v = getenv($key)))
                ? (string) $v
                : throw new \RuntimeException("$key missing in env")
        );

    /**
     * saveLoad does not throw exception, use load here
     */

    $env    = $getEnvValueOrFail('APP_ENV');
    $host   = $getEnvValueOrFail('DB_HOST');
    $dbname = $getEnvValueOrFail('DB_NAME');
    $dbuser = $getEnvValueOrFail('DB_USER');
    $pass   = $getEnvValueOrFail('DB_PASS');
    $port   = $getEnvValueOrFail('DB_PORT');

    if ($env === '' || (!in_array($env, ['development', 'production'], true))) 
        exit($defaultError);

    if ($env === 'development'){
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }

} catch (\Dotenv\Exception\InvalidPathException $e){
    error_log("Could not find env file: " . $e->getMessage());
    die($defaultError);
} catch (\RuntimeException $e){
    error_log("Missing env value: " . $e->getMessage());
    die ($defaultError);
}

/**================================================================ */

try {
    $globalBaseUrlProvider = new GlobalBaseUrlProvider($env);
    $headerRedirector = new HeaderRedirector($globalBaseUrlProvider);
    $pdo = Connector::getConnection(
        host:   $host,
        dbname: $dbname,
        user:   $dbuser,
        pass:   $pass,
        port:   $port
    );


    $container['db'] = [
        Connector::class => $pdo
    ];


    $container['config'] = [
        'app_env' => $env
    ];

    $container['http'] = [
        GlobalBaseUrlProvider::class => $globalBaseUrlProvider,
        HeaderRedirector::class => $headerRedirector,
    ];



    return $container;

} catch (\Throwable $e){
    $error = $e->getMessage();
    error_log("Bootstrap error: $error");
    die($defaultError);
}
