<?php 
declare (strict_types=1);
use Dotenv\Dotenv; /**vlucas/phpdotenv libaray */
require __DIR__ . '/vendor/autoload.php';
use GeoFort\Services\Http\GlobalBaseUrlProvider;
use GeoFort\Services\Http\HeaderRedictor;

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

    /**
     * saveLoad does not throw exception, use load here
     */

    $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '';

    if ($env === '' || (!in_array($env, ['development', 'production']))) 
        exit($defaultError);

    if ($env === 'development'){
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
    }

} catch (\Dotenv\Exception\InvalidPathException){
    error_log("Could not find env file: " . $e->getMessage());
    die($defaultError);
}

/**================================================================ */

try {
    $globalBaseUrlProvider = new GlobalBaseUrlProvider($env);
    $HeaderRedirector = new HeaderRedictor($globalBaseUrlProvider);

    $container['config'] = [
        'app_env' => $env
    ];

    $container['http'] = [
        'baseUrlProviderService'    => $globalBaseUrlProvider,
        'headerRedirectorService'   => $HeaderRedirector,
    ];

    return $container;
    
} catch (\Throwable $e){
    $error = $e->getMessage() ?? "unkown error";
    error_log("Bootstrap error: $error");
    die($defaultError);
}

?>