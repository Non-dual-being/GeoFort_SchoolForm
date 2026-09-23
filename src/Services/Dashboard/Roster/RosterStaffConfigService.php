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
                    'notes' => $member['notes'],
                    'preferences' => array_map(
                        static fn (array $preference): array => [
                            'moduleKey' => $preference['moduleKey'],
                            'moduleLabel' => BookingProgramConfig::MODULE_LABELS[$preference['moduleKey']]
                                ?? $preference['moduleKey'],
                            'rank' => $preference['rank'],
                        ],
                        $member['preferences'],
                    ),
                    'costRates' => $member['costRates'],
                ];
            },
            $this->repository->catalog(),
        );
    }
}
