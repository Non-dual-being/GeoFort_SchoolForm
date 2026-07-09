<?php

declare(strict_types=1);

namespace GeoFort\Services\Booking\Roster;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\EducationSelectionData;
use GeoFort\Services\Sql\RosterSqlService;

final class BookingRosterResolver
{
    private const UNAVAILABLE_MESSAGE = 'Voor deze combinatie is nog geen voorbeeldrooster beschikbaar.';
    private const PRIMARY_MORNING_ROSTER_KEY = 'Standaard-Ochtend-Programma-PO';

    public function __construct(
        private readonly RosterSqlService $rosterSqlService,
        private readonly RosterGroupCountResolver $groupCountResolver,
    ) {}

    public function resolve(
        string $schoolSector,
        string $program,
        EducationSelectionData $educationSelection,
        ?string $choiceModuleKey,
        int $studentCount,
        int $supervisorCount,
    ): BookingRosterResult {
        unset($educationSelection, $supervisorCount);

        $groupCount = $this->groupCountResolver->resolve(
            schoolSector: $schoolSector,
            program: $program,
            studentCount: $studentCount,
        );

        $standardModules = $this->formatModules(
            BookingProgramConfig::getStandardModulesForSelection(
                schoolSector: $schoolSector,
                program: $program,
            ),
        );

        $displayChoiceModule = $this->resolveDisplayChoiceModule(
            schoolSector: $schoolSector,
            program: $program,
            choiceModuleKey: $choiceModuleKey,
        );

        if ($groupCount === null) {
            return $this->unavailable(
                groupCount: null,
                standardModules: $standardModules,
                choiceModule: $displayChoiceModule,
            );
        }

        $schoolType = $this->resolveSchoolType($schoolSector);
        $rosterModuleKey = $this->resolveRosterModuleKey(
            schoolSector: $schoolSector,
            program: $program,
            choiceModuleKey: $choiceModuleKey,
        );

        $roster = $this->rosterSqlService->findRoster(
            schoolType: $schoolType,
            moduleKey: $rosterModuleKey,
            programDuration: $program,
            studentCount: $studentCount,
        );

        if ($roster === null) {
            return $this->unavailable(
                groupCount: $groupCount,
                standardModules: $standardModules,
                choiceModule: $displayChoiceModule,
            );
        }

        $imageUrl = $this->normalizeAssetPath($roster['afbeelding'] ?? null, 'images');
        $pdfUrl = $this->normalizeAssetPath($roster['pdf'] ?? null, 'pdf');

        if ($imageUrl === null || $pdfUrl === null) {
            return $this->unavailable(
                groupCount: $groupCount,
                standardModules: $standardModules,
                choiceModule: $displayChoiceModule,
            );
        }

        return new BookingRosterResult(
            available: true,
            groupCount: $groupCount,
            standardModules: $standardModules,
            choiceModule: $displayChoiceModule,
            imageUrl: $imageUrl,
            pdfUrl: $pdfUrl,
            message: null,
        );
    }

    private function resolveSchoolType(string $schoolSector): string
    {
        $config = BookingProgramConfig::getSchoolSectorConfig($schoolSector);

        return (string) $config['roosterType'];
    }

    private function resolveRosterModuleKey(
        string $schoolSector,
        string $program,
        ?string $choiceModuleKey,
    ): string {
        if ($schoolSector === 'primairOnderwijs' && $program === 'ochtend') {
            return self::PRIMARY_MORNING_ROSTER_KEY;
        }

        return (string) $choiceModuleKey;
    }

    /**
     * @return array{key: string, label: string}|null
     */
    private function resolveDisplayChoiceModule(
        string $schoolSector,
        string $program,
        ?string $choiceModuleKey,
    ): ?array {
        if ($schoolSector === 'primairOnderwijs' && $program === 'ochtend') {
            return [
                'key' => 'ochtendprogramma',
                'label' => 'Standaard ochtendprogramma',
            ];
        }

        if ($choiceModuleKey === null || $choiceModuleKey === '') {
            return null;
        }

        return [
            'key' => $choiceModuleKey,
            'label' => BookingProgramConfig::getModuleLabel($choiceModuleKey),
        ];
    }

    /**
     * @param string[] $moduleKeys
     * @return array<int, array{key: string, label: string}>
     */
    private function formatModules(array $moduleKeys): array
    {
        return array_map(
            static fn (string $moduleKey): array => [
                'key' => $moduleKey,
                'label' => BookingProgramConfig::getModuleLabel($moduleKey),
            ],
            $moduleKeys,
        );
    }

    private function normalizeAssetPath(?string $value, string $assetType): ?string
    {
        if ($value === null) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $value));

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, '/assets/')) {
            return $path;
        }

        $path = ltrim($path, '/');

        if ($assetType === 'images' && str_starts_with($path, 'images/')) {
            return '/assets/booking/roosters/' . $path;
        }

        if ($assetType === 'pdf' && str_starts_with($path, 'pdf/')) {
            return '/assets/booking/roosters/' . $path;
        }

        if (str_starts_with($path, 'assets/')) {
            return '/' . $path;
        }

        return null;
    }

    /**
     * @param array<int, array{key: string, label: string}> $standardModules
     * @param array{key: string, label: string}|null $choiceModule
     */
    private function unavailable(
        ?int $groupCount,
        array $standardModules,
        ?array $choiceModule,
    ): BookingRosterResult {
        return new BookingRosterResult(
            available: false,
            groupCount: $groupCount,
            standardModules: $standardModules,
            choiceModule: $choiceModule,
            imageUrl: null,
            pdfUrl: null,
            message: self::UNAVAILABLE_MESSAGE,
        );
    }
}
