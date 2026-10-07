<?php

declare(strict_types=1);

namespace GeoFort\Services\Http\Api\Admin;

use GeoFort\Services\Dashboard\Roster\RosterPlanException;
use JsonException;

final readonly class DashboardRosterPrepareDocumentRequest
{
    private function __construct(public int $bookingId, public int $snapshotId) {}

    public static function fromJson(string $body): self
    {
        try {
            $input = strlen($body) <= 2048 ? json_decode($body, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $input = null;
        }
        if (!is_array($input) || count($input) !== 2 || array_diff(array_keys($input), ['bookingId', 'snapshotId']) !== []
            || !is_int($input['bookingId'] ?? null) || $input['bookingId'] < 1
            || !is_int($input['snapshotId'] ?? null) || $input['snapshotId'] < 1) {
            throw new RosterPlanException('INVALID_ROSTER_ATTACHMENT', 422, 'Ongeldige roosterkeuze.');
        }
        return new self($input['bookingId'], $input['snapshotId']);
    }
}
