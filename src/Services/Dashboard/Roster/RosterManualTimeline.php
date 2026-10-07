<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use stdClass;

final readonly class RosterManualTimeline
{
    /** @param list<array{type:string,label:string,startTime:string,endTime:string}> $blocks */
    private function __construct(public array $blocks) {}

    public static function fromArray(mixed $blocks): self
    {
        if (!is_array($blocks) || !array_is_list($blocks)) {
            throw new RosterPlanException('INVALID_MANUAL_ROUNDS', 422, 'Gebruik een geordende lijst met tijdblokken.');
        }

        $normalized = [];
        $previousEnd = null;
        $hasRound = false;

        foreach ($blocks as $block) {
            $block = $block instanceof stdClass ? get_object_vars($block) : $block;
            if (
                !is_array($block)
                || array_is_list($block)
                || array_diff(array_keys($block), ['type', 'label', 'startTime', 'endTime']) !== []
            ) {
                throw new RosterPlanException('INVALID_MANUAL_BLOCK', 422, 'Een tijdblok bevat ongeldige velden.');
            }

            $type = $block['type'] ?? null;
            if (!in_array($type, ['welcome', 'round', 'break', 'lunch', 'departure'], true)) {
                throw new RosterPlanException('INVALID_MANUAL_BLOCK_TYPE', 422, 'Kies een geldig type tijdblok.');
            }

            $label = $block['label'] ?? null;
            if (
                !is_string($label)
                || preg_match('/\S/u', $label) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
                || mb_strlen(trim($label)) > 160
            ) {
                throw new RosterPlanException('INVALID_MANUAL_BLOCK_LABEL', 422, 'Geef ieder tijdblok een label van maximaal 160 tekens.');
            }

            $start = $block['startTime'] ?? null;
            $end = $block['endTime'] ?? null;
            foreach ([$start, $end] as $time) {
                if (!is_string($time) || preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time) !== 1) {
                    throw new RosterPlanException('INVALID_TIME', 422, 'Gebruik een geldige tijd in HH:MM.');
                }
            }

            if ($start >= $end) {
                throw new RosterPlanException('INVALID_TIME_RANGE', 422, 'De eindtijd moet na de starttijd liggen.');
            }

            // HH:MM sorteert chronologisch. Gaten en aansluitende blokken zijn toegestaan.
            if ($previousEnd !== null && $start < $previousEnd) {
                throw new RosterPlanException('INVALID_MANUAL_BLOCK_ORDER', 422, 'Zet tijdblokken op volgorde zonder overlap.');
            }

            $normalized[] = [
                'type' => $type,
                'label' => trim($label),
                'startTime' => $start,
                'endTime' => $end,
            ];
            $previousEnd = $end;
            $hasRound = $hasRound || $type === 'round';
        }

        if (!$hasRound) {
            throw new RosterPlanException('MANUAL_ROUND_REQUIRED', 422, 'Voeg minimaal één lesronde toe.');
        }

        return new self($normalized);
    }
}
