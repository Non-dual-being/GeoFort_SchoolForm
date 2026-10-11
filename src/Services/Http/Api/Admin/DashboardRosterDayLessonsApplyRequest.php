<?php
declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use JsonException;

final readonly class DashboardRosterDayLessonsApplyRequest
{
    public function __construct(public array $expectedRevisions) {}

    public static function fromBody(string $body): self
    {
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $plans = is_array($payload) ? ($payload['plans'] ?? null) : null;
        if (!is_array($plans) || !array_is_list($plans) || count($plans) < 1 || count($plans) > 2) throw new JsonException();
        $result = [];
        foreach ($plans as $plan) {
            if (!is_array($plan) || !is_int($plan['id'] ?? null) || $plan['id'] <= 0
                || !is_int($plan['revision'] ?? null) || $plan['revision'] <= 0 || isset($result[$plan['id']])) {
                throw new RosterPlanException('INVALID_DAY_PLANS', 422, 'Kies verschillende schoolroosters met hun huidige revisies.');
            }
            $result[$plan['id']] = $plan['revision'];
        }
        return new self($result);
    }
}
