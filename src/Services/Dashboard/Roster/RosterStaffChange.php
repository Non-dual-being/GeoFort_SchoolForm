<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

/** Allowlisted, normalized personnel input. Notes, costs and HR fields are not accepted. */
final readonly class RosterStaffChange
{
    private function __construct(
        public ?int $staffId,
        public ?int $expectedVersion,
        public int $expectedCatalogRevision,
        public array $values,
        public array $removeLegacyModuleKeys,
        public array $legacyPreferenceRanks,
        public ?string $impactToken,
    ) {}

    public static function fromArray(mixed $input, bool $create = false): self
    {
        $invalid = static fn (): RosterPlanException => new RosterPlanException('INVALID_STAFF', 422, 'Controleer naam, rollen, modulevaardigheden en versies.');
        if (!is_array($input) || array_is_list($input)) throw $invalid();
        $allowed = ['name', 'isActive', 'employmentType', 'canGuide', 'canCook', 'preferences', 'expectedCatalogRevision'];
        if (!$create) $allowed = [...$allowed, 'staffId', 'expectedVersion', 'removeLegacyModuleKeys', 'legacyPreferenceRanks', 'impactToken'];
        if (array_diff(array_keys($input), $allowed) !== []) throw $invalid();
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/u', $name)) throw $invalid();
        foreach (['isActive', 'canGuide', 'canCook'] as $field) if (!is_bool($input[$field] ?? null)) throw $invalid();
        if (!in_array($input['employmentType'] ?? null, ['paid', 'volunteer'], true)) throw $invalid();
        foreach ($create ? ['expectedCatalogRevision'] : ['staffId', 'expectedVersion', 'expectedCatalogRevision'] as $field) {
            if (!is_int($input[$field] ?? null) || $input[$field] < 1) throw $invalid();
        }
        if (!is_array($input['preferences'] ?? null) || !array_is_list($input['preferences'])) throw $invalid();
        $active = array_column(RosterStaffConfigService::moduleOptions(), null, 'moduleKey');
        $preferences = [];
        foreach ($input['preferences'] as $preference) {
            if (!is_array($preference) || array_diff(array_keys($preference), ['moduleKey', 'rank']) !== []
                || !is_string($preference['moduleKey'] ?? null) || !isset($active[$preference['moduleKey']])
                || isset($preferences[$preference['moduleKey']]) || !is_int($preference['rank'] ?? null)
                || $preference['rank'] < 1 || $preference['rank'] > 255) throw $invalid();
            $preferences[$preference['moduleKey']] = ['moduleKey' => $preference['moduleKey'], 'rank' => $preference['rank']];
        }
        ksort($preferences, SORT_STRING);
        $removed = $input['removeLegacyModuleKeys'] ?? [];
        if (!is_array($removed) || !array_is_list($removed)) throw $invalid();
        foreach ($removed as $key) if (!is_string($key) || $key === '' || mb_strlen($key) > 120 || isset($active[$key])) throw $invalid();
        if (count(array_unique($removed)) !== count($removed)) throw $invalid();
        sort($removed, SORT_STRING);
        $legacyRanks = $input['legacyPreferenceRanks'] ?? [];
        if (!is_array($legacyRanks) || !array_is_list($legacyRanks)) throw $invalid();
        $legacy = [];
        foreach ($legacyRanks as $preference) {
            if (!is_array($preference) || array_diff(array_keys($preference), ['moduleKey', 'rank']) !== []
                || !is_string($preference['moduleKey'] ?? null) || $preference['moduleKey'] === ''
                || mb_strlen($preference['moduleKey']) > 120 || isset($active[$preference['moduleKey']])
                || isset($legacy[$preference['moduleKey']]) || in_array($preference['moduleKey'], $removed, true)
                || !is_int($preference['rank'] ?? null) || $preference['rank'] < 1 || $preference['rank'] > 255) throw $invalid();
            $legacy[$preference['moduleKey']] = $preference;
        }
        ksort($legacy, SORT_STRING);
        $token = $input['impactToken'] ?? null;
        if ($token !== null && (!is_string($token) || strlen($token) > 100)) throw $invalid();
        return new self($create ? null : $input['staffId'], $create ? null : $input['expectedVersion'], $input['expectedCatalogRevision'], [
            'name' => $name, 'isActive' => $input['isActive'], 'employmentType' => $input['employmentType'],
            'canGuide' => $input['canGuide'], 'canCook' => $input['canCook'], 'preferences' => array_values($preferences),
        ], $removed, array_values($legacy), $token);
    }
}
