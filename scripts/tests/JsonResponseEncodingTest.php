<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Http\Url\EnvironmentBaseUrlProvider;
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$response = new JsonResponse(new EnvironmentBaseUrlProvider('development', 'https://onderwijsformulier.test'));
$send = static function(array $payload, int $status = 200) use ($response): array {
    ob_start();
    try { $response->json($payload, $status)->send(); $text = ob_get_contents(); }
    finally { ob_end_clean(); }
    return [http_response_code(), json_decode($text, true, 512, JSON_THROW_ON_ERROR), $text];
};
$payload = ['ok'=>true, 'data'=>['message'=>'Eén begeleider / twee groepen', 'canFinalize'=>false]];
[$status, $body, $text] = $send($payload);
$assert($status === 200 && $body === $payload && str_contains($text, 'Eén begeleider /'), 'Valid Unicode response changed');
[$status, $body] = $send(['ok'=>false, 'code'=>'INVALID_CSRF'], 403);
$assert($status === 403 && $body['code'] === 'INVALID_CSRF', 'HTTP error changed');
$log = tempnam(sys_get_temp_dir(), 'geofort-json-');
$oldLog = ini_get('error_log'); ini_set('error_log', $log);
try {
    foreach (["bad\xE9", INF, NAN] as $invalid) {
        [$status, $body] = $send(['ok'=>true, 'data'=>['secret'=>'DO_NOT_LOG_PAYLOAD', 'invalid'=>$invalid]]);
        $assert($status === 500 && $body === ['ok'=>false, 'type'=>'server', 'code'=>500], 'Encoding failure must return valid JSON and HTTP 500');
    }
    $logText = file_get_contents($log);
    $assert(substr_count($logText, '[JsonResponse]') === 3 && !str_contains($logText, 'DO_NOT_LOG_PAYLOAD'), 'Encoding errors not safely logged');
} finally { ini_set('error_log', $oldLog); unlink($log); }
echo "PASS valid Unicode, HTTP errors, encoding failure response and payload-free diagnostic logging\n";
