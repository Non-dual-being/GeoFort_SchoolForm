<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking;

use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Sql\DashboardBookingDetailSqlService;
use GeoFort\Services\Sql\StoredBookingSqlRepository;
use GeoFort\Services\Booking\Pricing\StoredBookingPricingInputFactory;
use GeoFort\Services\Booking\Pricing\BookingPriceCalculator;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;
use Throwable;

final readonly class DashboardBookingDetailService
{
    public function __construct(private DashboardBookingDetailSqlService $sql, private ?StoredBookingSqlRepository $storedBookings = null, private ?StoredBookingPricingInputFactory $pricingInputs = null, private ?BookingPriceCalculator $priceCalculator = null) {}

    /** @return array<string, mixed>|null */
    public function getBooking(int $id): ?array
    {
        $row = $this->sql->findBooking($id);

        if ($row === null) {
            return null;
        }

        $sector = $this->string($row, 'onderwijs_sector');
        $program = $this->string($row, 'programma');
        $module = $this->nullableString($row, 'keuzemodule_key');
        $sourceSystem = $this->nullableString($row, 'source_system');

        $priceQuote = null;
        $food = FoodAndDrinkSelectionData::fromStoredValues(
            $this->integer($row, 'remise_break'),
            $this->integer($row, 'kazerne_break'),
            $this->integer($row, 'fortgracht_break'),
            $this->integer($row, 'waterijsje'),
            $this->integer($row, 'glas_limonade'),
            $this->integer($row, 'remise_lunch'),
            $this->boolean($row, 'eigen_picknick'),
        );
        try {
            $stored = $this->storedBookings?->findById($id);
            if ($stored !== null && $this->pricingInputs !== null && $this->priceCalculator !== null) $priceQuote = $this->pricingInputs->calculate($this->pricingInputs->fromStoredBooking($stored), $this->priceCalculator)->toArray();
        } catch (Throwable) {
            // Legacy details remain readable when their current configuration cannot be priced.
        }

        return [
            'id' => $this->integer($row, 'id'),
            'status' => $this->string($row, 'status'),
            'visitDate' => $this->string($row, 'bezoekdatum'),
            'school' => [
                'name' => $this->string($row, 'schoolnaam'),
                'country' => $this->string($row, 'land'),
                'address' => $this->string($row, 'adres'),
                'postalCode' => $this->string($row, 'postcode'),
                'city' => $this->string($row, 'plaats'),
                'phone' => $this->string($row, 'school_telefoonnummer'),
            ],
            'contact' => [
                'firstName' => $this->string($row, 'contactpersoon_voornaam'),
                'lastName' => $this->string($row, 'contactpersoon_achternaam'),
                'email' => $this->string($row, 'email'),
                'phone' => $this->string($row, 'contactpersoon_telefoonnummer'),
            ],
            'education' => [
                'sector' => $sector,
                'sectorLabel' => isset(BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label'])
                    ? (string) BookingProgramConfig::SCHOOL_TYPES_BY_KEY[$sector]['label']
                    : $this->unknownLabel($sector),
                'program' => $program,
                'programLabel' => isset(BookingProgramConfig::PROGRAMS[$program]['label'])
                    ? (string) BookingProgramConfig::PROGRAMS[$program]['label']
                    : $this->unknownLabel($program),
                'programOptions' => array_map(
                    static fn(string $key,array $config):array=>[
                        'key'=>$key,
                        'label'=>(string)$config['label'],
                        'description'=>array_values($config['description']),
                        'allowedSchoolTypes'=>array_values($config['allowedSchoolTypes']),
                        'allowedWeekdays'=>array_values($config['allowedWeekdays']),
                    ],
                    array_keys(BookingProgramConfig::PROGRAMS),
                    array_values(BookingProgramConfig::PROGRAMS),
                ),
                'module' => $module,
                'moduleLabel' => $module === null
                    ? null
                    : (BookingProgramConfig::MODULE_LABELS[$module] ?? $this->unknownLabel($module)),
                'studentCount' => $this->nullableInteger($row, 'aantal_leerlingen'),
                'supervisorCount' => $this->nullableInteger($row, 'aantal_begeleiders'),
                'selections' => $this->groupSelections($this->sql->findEducationSelections($id), $sector),
                'configuration' => [
                    'schoolLevels' => BookingProgramConfig::SCHOOL_LEVELS[$sector] ?? [],
                    'selectionRules' => BookingProgramConfig::SCHOOL_LEVEL_SELECTION_RULES[$sector] ?? [],
                    'studentLimitsByProgram' => array_reduce(
                        array_keys(BookingProgramConfig::PROGRAMS),
                        static function (array $limits, string $program) use ($sector): array {
                            if (BookingProgramConfig::isProgramAllowedForSchoolSector($program, $sector)) {
                                $limits[$program] = [
                                    'minimum' => BookingProgramConfig::getMinStudentsForSelection($sector, $program),
                                    'maximum' => (int) BookingProgramConfig::STUDENT_LIMITS['max'][$program],
                                ];
                            }
                            return $limits;
                        },
                        [],
                    ),
                    'modules' => BookingProgramConfig::MODULES,
                    'moduleLabels' => BookingProgramConfig::MODULE_LABELS,
                    'moduleFilters' => BookingProgramConfig::MODULE_FILTERS,
                ],
            ],
            'foodAndDrink' => [
                'remiseBreak' => $food->remiseBreak,
                'kazerneBreak' => $food->kazerneBreak,
                'fortgrachtBreak' => $food->fortgrachtBreak,
                'waterIce' => $food->waterijsje,
                'lemonade' => $food->glasLimonade,
                'remiseLunch' => $food->remiseLunch,
                'ownPicnic' => $food->eigenPicknick,
                'lunchChoice' => $food->lunchChoice,
                'options' => BookingProgramConfig::getFoodAndDrinkOptionsForFrontend(),
                'info' => BookingProgramConfig::FOOD_AND_DRINK_INFO,
            ],
            'additional' => [
                'cjpDiscount' => strtolower(trim($this->string($row, 'cjpPasGebruik'))) === 'ja',
                'cjpContactName' => $this->nullableString($row, 'cjpContactpersoonNaam'),
                'cjpCardNumber' => $this->nullableString($row, 'cjpPasnummer'),
                'referralSource' => $this->nullableString($row, 'hoe_kent_u_geofort'),
                'remarks' => $this->nullableString($row, 'opmerkingen'),
            ],
            'metadata' => [
                'legacyImported' => $sourceSystem !== null,
                'legacySourceSystem' => $sourceSystem,
                'legacySourceId' => $this->nullableInteger($row, 'source_record_id'),
            ],
            'priceQuote' => $priceQuote,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function groupSelections(array $rows, string $sector): array
    {
        $levels = [];

        foreach ($rows as $row) {
            $levelKey = $this->string($row, 'level_key');
            $groupKey = $this->string($row, 'group_key');

            if (!isset($levels[$levelKey])) {
                $configuredLevel = BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey]['label'] ?? null;
                $levels[$levelKey] = [
                    'levelKey' => $levelKey,
                    'levelLabel' => is_string($configuredLevel) ? $configuredLevel : $this->string($row, 'level_label'),
                    'groups' => [],
                ];
            }

            $configuredGroup = BookingProgramConfig::SCHOOL_LEVELS[$sector][$levelKey]['groups'][$groupKey] ?? null;
            $levels[$levelKey]['groups'][] = [
                'groupKey' => $groupKey,
                'groupLabel' => is_string($configuredGroup) ? $configuredGroup : $this->string($row, 'group_label'),
            ];
        }

        return array_values($levels);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $column): string
    {
        return isset($row[$column]) && is_string($row[$column]) ? $row[$column] : '';
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $this->string($row, $column);
        return trim($value) === '' ? null : $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $column): int
    {
        $value = $row[$column] ?? 0;
        return is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $row */
    private function nullableInteger(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : $this->integer($row, $column);
    }

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $column): bool
    {
        return $this->integer($row, $column) === 1;
    }

    private function unknownLabel(string $value): string
    {
        $label = trim(str_replace(['-', '_'], ' ', $value));
        return ($label !== '' ? $label : 'Onbekend') . ' (onbekend)';
    }
}
