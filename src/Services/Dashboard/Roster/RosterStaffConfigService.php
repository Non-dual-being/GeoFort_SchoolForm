<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Sql\RosterStaffSqlRepository;

final readonly class RosterStaffConfigService
{
    public function __construct(private RosterStaffSqlRepository $repository) {}

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        return array_map(
            function (array $member): array {
                return [
                    'id' => $member['id'],
                    'name' => $member['name'],
                    'isActive' => $member['isActive'],
                    'employmentType' => $member['employmentType'],
                    'canGuide' => $member['canGuide'],
                    'canCook' => $member['canCook'],
                    'version' => $member['version'],
                    'notes' => $member['notes'],
                    'preferences' => array_map(
                        static fn (array $preference): array => [
                            'moduleKey' => $preference['moduleKey'],
                            'moduleLabel' => BookingProgramConfig::MODULE_LABELS[$preference['moduleKey']]
                                ?? $preference['moduleKey'],
                            'rank' => $preference['rank'],
                            'isLegacy' => !in_array($preference['moduleKey'], array_column(self::moduleOptions(), 'moduleKey'), true),
                        ],
                        $member['preferences'],
                    ),
                    'costRates' => $member['costRates'],
                ];
            },
            $this->repository->catalog(),
        );
    }

    public static function present(array $member): array
    {
        $active = array_column(self::moduleOptions(), null, 'moduleKey');
        $member['preferences'] = array_map(static fn (array $preference): array => [
            'moduleKey' => $preference['moduleKey'],
            'moduleLabel' => BookingProgramConfig::MODULE_LABELS[$preference['moduleKey']] ?? $preference['moduleKey'],
            'rank' => $preference['rank'], 'isLegacy' => !isset($active[$preference['moduleKey']]),
        ], $member['preferences']);
        return $member;
    }

    /** Current selectable modules, not every historical label. */
    public static function moduleOptions(): array
    {
        $keys = [];
        foreach (BookingProgramConfig::MODULES as $programs) foreach ($programs as $groups) {
            foreach ($groups as $modules) foreach ($modules as $key) $keys[$key] = true;
        }
        ksort($keys, SORT_STRING);
        return array_map(static fn (string $key): array => ['moduleKey' => $key, 'moduleLabel' => BookingProgramConfig::MODULE_LABELS[$key] ?? $key], array_keys($keys));
    }
}
