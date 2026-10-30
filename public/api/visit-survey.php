<?php
declare(strict_types=1);
use GeoFort\Services\Http\Api\PublicVisitSurveyAction;
$container = require dirname(__DIR__, 2) . '/bootstrap.php';
$container['controllers'][PublicVisitSurveyAction::class]->send(
    (string) ($_SERVER['REQUEST_METHOD'] ?? ''), (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string) $_SERVER['HTTP_X_CSRF_TOKEN'] : null, file_get_contents('php://input') ?: '',
);
