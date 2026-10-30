<?php
declare(strict_types=1);
namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Security\AuthMiddleware;
use GeoFort\Security\SessionGuard;
use GeoFort\Services\Auth\CsrfTokenService;
use GeoFort\Services\Booking\Program\BookingProgramAccessService;
use GeoFort\Services\Http\Response\JsonResponse;
use GeoFort\Services\Survey\VisitSurveyService;

final readonly class DashboardVisitSurveyAction
{
    private const SCOPE = 'manage-visit-surveys';
    public function __construct(private AuthMiddleware $auth, private SessionGuard $guard, private CsrfTokenService $csrf,
        private VisitSurveyService $service, private JsonResponse $response, private string $environment) {}

    public function send(string $method, string $contentType, ?string $token, string $body, string $userAgent, array $filters): void
    {
        $this->auth->startPublicSession();
        if (!$this->guard->validate($userAgent)) { $this->auth->invalidateAuthentication(); $this->reply(['ok' => false, 'message' => 'Log opnieuw in.'], 401); return; }
        if (!BookingProgramAccessService::canManage((string) ($_SESSION['user_role'] ?? ''))) { $this->reply(['ok' => false], 403); return; }
        try {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Amsterdam'));
            if ($method === 'POST') {
                if (!$this->csrf->validate(self::SCOPE, $token)) { $this->reply(['ok' => false, 'message' => 'Vernieuw de pagina en probeer opnieuw.'], 403); return; }
                if (strtolower(trim(explode(';', $contentType)[0])) !== 'application/json' || strlen($body) > 4096) throw new \InvalidArgumentException('Ongeldig verzoek.');
                $input = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
                if (!is_array($input)) throw new \InvalidArgumentException('Ongeldig verzoek.');
                $actor = (int) ($_SESSION['user_id'] ?? 0);
                if (($input['action'] ?? '') === 'settings') $this->service->saveSettings($input, $actor, $now);
                elseif (($input['action'] ?? '') === 'exclude' && is_int($input['bookingId'] ?? null)) $this->service->exclude($input['bookingId'], $actor);
                else throw new \InvalidArgumentException('Onbekende actie.');
            } elseif ($method !== 'GET') { $this->reply(['ok' => false], 405); return; }
            $this->reply(['ok' => true, 'data' => $this->service->report($filters, $now), 'csrfToken' => $this->csrf->getOrCreate(self::SCOPE), 'local' => $this->environment !== 'production']);
        } catch (\InvalidArgumentException $e) { $this->reply(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\JsonException) { $this->reply(['ok' => false, 'message' => 'Ongeldig verzoek.'], 422);
        } catch (\Throwable $e) { error_log('[DashboardVisitSurveyAction] ' . $e::class); $this->reply(['ok' => false, 'message' => 'De evaluaties kunnen niet worden geladen. Probeer het later opnieuw.'], 500); }
    }

    private function reply(array $data, int $status = 200): void
    {
        $this->response->json($data, $status)->header('Cache-Control', 'private, no-store')->send();
    }
}
