<?php 
declare (strict_types=1);
use Dotenv\Dotenv; /**vlucas/phpdotenv libaray */
require __DIR__ . '/vendor/autoload.php';

/** load the env */
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '';

if ($env === '' || (!in_array($env, ['development', 'production']))) exit("critical error, contact onderwijs@geofort.nl");

if ($env === 'development'){
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

ini_set('log_errors', '1');
date_default_timezone_set('Europe/Amsterdam');

/** PADEN DEFINIEREN */
if (!defined('TEMPLATE_PATH')) define('TEMPLATE_PATH', __DIR__ . '/templates');
if (!defined('PUBLIC_PATH')) define('PUBLIC_PATH', __DIR__ . '/public');


$container = [];
$container['config'] = [
    'app_env' => $env
];

return $container;

/**
 * return grants other files access to container
 */
?>