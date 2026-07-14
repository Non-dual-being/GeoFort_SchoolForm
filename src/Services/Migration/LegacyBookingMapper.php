<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;

final class LegacyBookingMapper
{
    /** @var array<int, string> Handmatig controleren en vóór execute aanvullen. */
    private const LEGACY_LAND_BY_ID = [
        // legacy id => 'België',
    ];

    private const SCHOOL_TYPES = [
        'Primair Onderwijs' => 'primairOnderwijs',
        'Voortgezet Onderwijs onderbouw' => 'voortgezetOnderbouw',
        'Voortgezet Onderwijs bovenbouw' => 'voortgezetBovenbouw',
    ];

    /**
     * @param array<string, mixed> $legacy
     * @return array{booking: array<string, mixed>, selections: list<array<string, mixed>>, errors: list<string>, warnings: list<string>, has_html_entities: bool, possible_belgian: bool}
     */
    public function map(array $legacy): array
    {
        $id = (int) ($legacy['id'] ?? 0);
        $errors = [];
        $warnings = [];
        if ($id <= 0 || filter_var($legacy['id'] ?? null, FILTER_VALIDATE_INT) === false) {
            $errors[] = 'Ongeldig legacy-ID.';
        }
        $hasHtmlEntities = false;

        $text = function (string $field, bool $multiline = false) use ($legacy, &$hasHtmlEntities): ?string {
            if ($legacy[$field] === null || trim((string) $legacy[$field]) === '') {
                return null;
            }
            $value = (string) $legacy[$field];
            if (preg_match('/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i', $value) === 1) {
                $hasHtmlEntities = true;
            }
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($multiline) {
                $value = str_replace(["\r\n", "\r"], "\n", $value);
            }
            return trim($value);
        };

        $sector = self::SCHOOL_TYPES[trim((string) ($legacy['schooltype'] ?? ''))] ?? null;
        if ($sector === null) {
            $errors[] = 'Onbekend schooltype: ' . self::display($legacy['schooltype'] ?? null);
        }
        $program = trim((string) ($legacy['programma_duur'] ?? ''));
        if (!BookingProgramConfig::programExists($program)) {
            $errors[] = 'Onbekend programma: ' . self::display($program);
        } elseif ($sector !== null && !BookingProgramConfig::isProgramAllowedForSchoolSector($program, $sector)) {
            $errors[] = "Programma {$program} is niet geldig voor sector {$sector}";
        }

        $choice = trim((string) ($legacy['keuze_module'] ?? ''));
        if ($program === 'ochtend' && $choice === 'Standaard-Ochtend-Programma-PO') {
            $choice = '';
        } elseif ($choice !== '' && ($sector === null || !BookingProgramConfig::isChoiceModuleConfiguredForSelection($choice, $sector, $program))) {
            $errors[] = 'Onbekende of niet-passende keuzemodule: ' . self::display($choice);
        }

        $possibleBelgian = $this->isPossiblyBelgian($legacy, $text('schoolnaam'), $text('plaats'));
        if ($possibleBelgian && !isset(self::LEGACY_LAND_BY_ID[$id])) {
            $warnings[] = 'Mogelijk Belgisch; land blijft Nederland totdat LEGACY_LAND_BY_ID expliciet is aangevuld.';
        }

        $status = trim((string) ($legacy['status'] ?? ''));
        if (!BookingPolicy::isAllowedStatus($status)) {
            $errors[] = 'Ongeldige status: ' . self::display($status);
        }
        $comments = $text('opmerkingen', true);
        $discovery = $text('hoe_kent_u_geofort');
        if (mb_strlen($comments ?? '', 'UTF-8') > 600) {
            $warnings[] = 'Opmerkingen langer dan 600 tekens; historische waarde blijft volledig behouden.';
        }
        if (mb_strlen($discovery ?? '', 'UTF-8') > 120) {
            $warnings[] = 'Hoe-kent-u-GeoFortwaarde langer dan 120 tekens; historische waarde blijft volledig behouden.';
        }
        $students = self::integer($legacy['aantal_leerlingen'] ?? null);
        $supervisors = self::integer($legacy['aantal_begeleiders'] ?? null);
        foreach (['aantal_leerlingen', 'aantal_begeleiders', 'remise_break', 'kazerne_break', 'fortgracht_break', 'glas_limonade', 'waterijsje', 'remise_lunch', 'eigen_picknick'] as $numericField) {
            if (self::integer($legacy[$numericField] ?? null) === null) {
                $errors[] = "Ongeldige numerieke waarde voor {$numericField}.";
            }
        }
        if (!in_array(self::integer($legacy['cjp_korting'] ?? null), [0, 1], true)) {
            $errors[] = 'cjp_korting moet exact 0 of 1 zijn.';
        }
        if ($students === null || $students <= 0) {
            $warnings[] = 'Aanvraag zonder positief leerlingenaantal.';
        }
        if ($supervisors === null || $supervisors <= 0) {
            $warnings[] = 'Aanvraag zonder positief begeleidersaantal.';
        }

        $selections = $sector === null ? [] : $this->mapSelections($id, $sector, $legacy, $errors, $warnings);
        if ($selections === []) {
            $errors[] = 'Geen onderwijsselectierecord voor aanvraag.';
        }

        return [
            'booking' => [
                'id' => $id,
                'status' => $status,
                'schoolnaam' => $text('schoolnaam'),
                'land' => self::LEGACY_LAND_BY_ID[$id] ?? 'Nederland',
                'adres' => $text('adres'),
                'postcode' => trim((string) ($legacy['postcode'] ?? '')),
                'plaats' => $text('plaats'),
                'school_telefoonnummer' => trim((string) ($legacy['school_telefoon'] ?? '')),
                'contactpersoon_telefoonnummer' => trim((string) ($legacy['contact_telefoon'] ?? '')),
                'contactpersoon_voornaam' => $text('voornaam_contactpersoon'),
                'contactpersoon_achternaam' => $text('achternaam_contactpersoon'),
                'email' => trim((string) ($legacy['email'] ?? '')),
                'bezoekdatum' => $legacy['bezoekdatum'] ?? null,
                'hoe_kent_u_geofort' => $discovery,
                'opmerkingen' => $comments,
                'cjpPasGebruik' => ((int) ($legacy['cjp_korting'] ?? 0)) === 1 ? 'ja' : 'nee',
                'cjpContactpersoonNaam' => null,
                'cjpPasnummer' => null,
                'onderwijs_sector' => $sector,
                'programma' => $program,
                'keuzemodule_key' => $choice === '' ? null : $choice,
                'aantal_leerlingen' => $students,
                'aantal_begeleiders' => $supervisors,
                'remise_break' => self::integer($legacy['remise_break'] ?? null) ?? 0,
                'kazerne_break' => self::integer($legacy['kazerne_break'] ?? null) ?? 0,
                'fortgracht_break' => self::integer($legacy['fortgracht_break'] ?? null) ?? 0,
                'glas_limonade' => self::integer($legacy['glas_limonade'] ?? null) ?? 0,
                'waterijsje' => self::integer($legacy['waterijsje'] ?? null) ?? 0,
                'remise_lunch' => self::integer($legacy['remise_lunch'] ?? null) ?? 0,
                'eigen_picknick' => self::integer($legacy['eigen_picknick'] ?? null) ?? 0,
                'voorwaarden_akkoord' => 0,
                'voorwaarden_akkoord_op' => null,
            ],
            'selections' => $selections,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'has_html_entities' => $hasHtmlEntities,
            'possible_belgian' => $possibleBelgian,
        ];
    }

    /**
     * Formulierlimieten zijn hier bewust niet opgenomen. Alleen de werkelijke
     * karaktercapaciteit van de doelkolom mag historische data blokkeren.
     *
     * @param array<string, mixed> $booking
     * @param array<string, int|null> $targetCharacterCapacities
     * @return list<string>
     */
    public static function validateTargetCharacterCapacities(array $booking, array $targetCharacterCapacities): array
    {
        $errors = [];
        foreach ($booking as $column => $value) {
            $capacity = $targetCharacterCapacities[$column] ?? null;
            if ($capacity !== null && is_string($value) && mb_strlen($value, 'UTF-8') > $capacity) {
                $errors[] = "Waarde voor {$column} is langer dan doelkolomcapaciteit {$capacity}.";
            }
        }
        return $errors;
    }

    /** @param array<string, mixed> $legacy @param list<string> $errors @param list<string> $warnings @return list<array<string, mixed>> */
    private function mapSelections(int $id, string $sector, array $legacy, array &$errors, array &$warnings): array
    {
        $pairs = [];
        if ($sector === 'primairOnderwijs') {
            $level = trim((string) ($legacy['niveau1'] ?? ''));
            if (!in_array($level, ['regulier', 'speciaal'], true)) {
                $errors[] = $level === '' ? 'Ontbrekend niveau.' : 'Onbekend level: ' . $level;
                return [];
            }
            $groups = [];
            foreach (range(1, 5) as $position) {
                $raw = trim((string) ($legacy['leeftijdsgroep' . $position] ?? ''));
                if ($raw !== '') {
                    $groups[] = $raw;
                }
            }
            $pairs[] = [$level, $groups];
        } else {
            foreach (range(1, 3) as $position) {
                $rawLevel = trim((string) ($legacy['niveau' . $position] ?? ''));
                $rawGroups = trim((string) ($legacy['leeftijdsgroep' . $position] ?? ''));
                if ($rawLevel === '') {
                    if ($rawGroups !== '') {
                        $errors[] = "Ontbrekend niveau bij leeftijdsgroep{$position}.";
                    }
                    continue;
                }
                $level = $this->mapVoLevel($sector, $rawLevel);
                if ($level === null) {
                    $message = "Onbekend level op positie {$position}: {$rawLevel}";
                    if ($sector === 'voortgezetOnderbouw' && $rawLevel === 'VMBO') {
                        $message .= ' (generiek VMBO wordt niet gegokt)';
                    }
                    $errors[] = $message;
                    continue;
                }
                $groups = $rawGroups === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $rawGroups)), static fn (string $v): bool => $v !== ''));
                $pairs[] = [$level, $groups];
            }
        }

        $rows = [];
        $seen = [];
        foreach ($pairs as $levelIndex => [$level, $groups]) {
            if ($groups === []) {
                $errors[] = "Level {$level} zonder groepen.";
                continue;
            }
            foreach ($groups as $groupIndex => $rawGroup) {
                $group = $this->mapGroup($rawGroup);
                $configured = BookingProgramConfig::SCHOOL_LEVELS[$sector][$level]['groups'][$group ?? ''] ?? null;
                if ($group === null || !is_string($configured)) {
                    $errors[] = "Onbekende groep voor {$level}: {$rawGroup}";
                    continue;
                }
                $key = $id . '|' . $level . '|' . $group;
                if (isset($seen[$key])) {
                    $errors[] = "Dubbele level/groupcombinatie na normalisatie: {$level}/{$group}";
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'aanvraag_id' => $id,
                    'sector_key' => $sector,
                    'sector_label' => BookingProgramConfig::getSchoolSectorLabel($sector),
                    'level_key' => $level,
                    'level_label' => BookingProgramConfig::SCHOOL_LEVELS[$sector][$level]['label'],
                    'level_position' => $levelIndex + 1,
                    'group_key' => $group,
                    'group_label' => $configured,
                    'group_position' => $groupIndex + 1,
                ];
            }
        }
        return $rows;
    }

    private function mapVoLevel(string $sector, string $value): ?string
    {
        if ($sector === 'voortgezetBovenbouw') {
            return match ($value) {
                'VMBO', 'VMBO_BB_KB', 'VMBO_GL_TL', 'VMBO Basis Kader', 'VMBO Gemengd Theoretisch', 'MAVO' => 'vmbo',
                'HAVO' => 'havo', 'VWO' => 'vwo', 'Praktijk Onderwijs', 'PraktijkOnderwijs' => 'praktijkOnderwijs', default => null,
            };
        }
        return match ($value) {
            'VMBO_BB_KB', 'VMBO Basis Kader' => 'vmboBasisKader',
            'VMBO_GL_TL', 'VMBO Gemengd Theoretisch', 'MAVO' => 'vmboGemengdTheoretisch',
            'HAVO' => 'havo', 'VWO' => 'vwo', 'Praktijk Onderwijs', 'PraktijkOnderwijs' => 'praktijkOnderwijs', default => null,
        };
    }

    private function mapGroup(string $value): ?string
    {
        if (preg_match('/^Groep ([5-8])$/u', $value, $m) === 1) return 'groep' . $m[1];
        if (preg_match('/^(VMBO|HAVO|Atheneum|Gymnasium) ([1-6])$/u', $value, $m) === 1) return strtolower($m[1]) . $m[2];
        if (preg_match('/^(?:Praktijkonderwijs|Praktijk Onderwijs|Praktijk) ([1-5])$/u', $value, $m) === 1) return 'praktijk' . $m[1];
        return null;
    }

    /** @param array<string, mixed> $legacy */
    private function isPossiblyBelgian(array $legacy, ?string $school, ?string $place): bool
    {
        foreach (['school_telefoon', 'contact_telefoon'] as $field) {
            $phone = preg_replace('/[\s().-]+/', '', (string) ($legacy[$field] ?? ''));
            if (str_starts_with((string) $phone, '+32') || str_starts_with((string) $phone, '0032')) return true;
        }
        if (preg_match('/^\d{4}$/', trim((string) ($legacy['postcode'] ?? ''))) === 1) return true;
        return preg_match('/\b(Belgi(?:ë|e)|Belgisch|Vlaanderen|Antwerpen|Brussel|Gent|Leuven|Hasselt)\b/iu', ($school ?? '') . ' ' . ($place ?? '')) === 1;
    }

    private static function integer(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) === false ? null : (int) $value;
    }

    private static function display(mixed $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '[leeg]' : $value;
    }
}
