<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$container = require dirname(__DIR__) . '/bootstrap.php';
$service = $container['survey'][\GeoFort\Services\Survey\VisitSurveyService::class];
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam'));
$send = in_array('--send', $argv, true);
if ($send && $container['config']['app_env'] !== 'production') {
    fwrite(STDERR, "Verzending is uitsluitend beschikbaar in productie. Gebruik lokaal de tests met afgevangen mails.\n"); exit(2);
}
if (!$send) {
    $report = $service->report([], $now);
    $states = array_count_values(array_column($report['visits'], 'state'));
    echo json_encode(['dryRun' => true, 'enabled' => $report['settings']['enabled'], 'states' => $states], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
$result = $service->dispatch($container['survey']['mailer'], $container['survey']['layout'], $container['survey']['baseUrl'], $now);
echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
if ($result['failed'] > 0) exit(1);
