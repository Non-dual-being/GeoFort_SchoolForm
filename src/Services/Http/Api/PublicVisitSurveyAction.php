<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Survey\VisitSurveyService;

final readonly class PublicVisitSurveyAction
{
    private const SCOPE = 'visit-survey';
    public function __construct(private AuthMiddleware $auth, private CsrfTokenService $csrf, private VisitSurveyService $service, private JsonResponse $response) {}

    public function send(string $method, string $contentType, ?string $csrfToken, string $body): void
    {
        $this->auth->startPublicSession();
        try {
            if ($method === 'GET') { $this->reply(['ok' => true, 'csrfToken' => $this->csrf->getOrCreate(self::SCOPE)]); return; }
            if ($method !== 'POST') { $this->reply(['ok' => false], 405); return; }
            if (!$this->csrf->validate(self::SCOPE, $csrfToken)) { $this->reply(['ok' => false, 'message' => 'Vernieuw de pagina om verder te gaan.'], 403); return; }
            if (strtolower(trim(explode(';', $contentType)[0])) !== 'application/json' || strlen($body) > 8192) throw new \InvalidArgumentException('Ongeldig verzoek.');
            $input = json_decode($body, true, 10, JSON_THROW_ON_ERROR);
            if (!is_array($input) || !is_string($input['token'] ?? null) || !is_string($input['action'] ?? null)) throw new \InvalidArgumentException('Deze evaluatielink is niet geldig.');
            $result = $this->service->publicAction($input['token'], $input['action'], $input, new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam')));
            $this->reply(['ok' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) { $this->reply(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\JsonException) { $this->reply(['ok' => false, 'message' => 'Ongeldig verzoek.'], 422);
        } catch (\Throwable $e) { error_log('[PublicVisitSurveyAction] ' . $e::class); $this->reply(['ok' => false, 'message' => 'Opslaan is niet gelukt. Probeer het straks opnieuw.'], 500); }
    }

    private function reply(array $data, int $status = 200): void
    {
        $this->response->json($data, $status)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->send();
    }
}
