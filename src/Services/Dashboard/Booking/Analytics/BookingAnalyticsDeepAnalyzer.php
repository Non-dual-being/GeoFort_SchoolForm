<?php
declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Booking\Analytics;

use DateTimeImmutable;
use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Booking\Data\FoodAndDrinkSelectionData;

final class BookingAnalyticsDeepAnalyzer
{
    private const STUDENT_BINS = [
        ['key' => '1-20', 'label' => '1–20', 'min' => 1, 'max' => 20],
        ['key' => '21-40', 'label' => '21–40', 'min' => 21, 'max' => 40],
        ['key' => '41-80', 'label' => '41–80', 'min' => 41, 'max' => 80],
        ['key' => '81-120', 'label' => '81–120', 'min' => 81, 'max' => 120],
        ['key' => '121-160', 'label' => '121–160', 'min' => 121, 'max' => 160],
    ];
    private const CAPACITY_BINS = [
        ['key' => '0-25', 'label' => '0–25%', 'min' => 0.0, 'max' => 0.25],
        ['key' => '26-50', 'label' => '26–50%', 'min' => 0.25, 'max' => 0.50],
        ['key' => '51-75', 'label' => '51–75%', 'min' => 0.50, 'max' => 0.75],
        ['key' => '76-100', 'label' => '76–100%', 'min' => 0.75, 'max' => 1.0],
        ['key' => 'over', 'label' => 'Boven capaciteit', 'min' => 1.0, 'max' => INF],
    ];
    private const MONTHS = [1 => 'Januari', 'Februari', 'Maart', 'April', 'Mei', 'Juni', 'Juli', 'Augustus', 'September', 'Oktober', 'November', 'December'];
    private const WEEKDAYS = [1 => 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'];
    private const WEEKDAY_KEYS = [1 => 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @param list<array<string, mixed>> $bookings */
    public function analyze(array $bookings, BookingAnalyticsCriteria $criteria): BookingAnalyticsDeepAnalysis
    {
        return new BookingAnalyticsDeepAnalysis(
            new StudentCountAnalysis(...$this->students($bookings)),
            new CateringAnalysis(...$this->catering($bookings)),
            new YearlyAnalysis(...$this->years($bookings, $criteria)),
            new SeasonalityAnalysis(...$this->seasonality($bookings)),
        );
    }

    /** @param list<array<string, mixed>> $bookings @return array<string, mixed> */
    private function students(array $bookings): array
    {
        $programs = [];
        $invalid = 0;
        foreach (BookingPolicy::PROGRAM_LABELS as $program => $label) {
            $rows = array_values(array_filter($bookings, static fn (array $row): bool => $row['program'] === $program));
            $valid = array_values(array_filter($rows, static fn (array $row): bool => is_int($row['students']) && $row['students'] > 0 && $row['students'] <= 160));
            $invalid += count($rows) - count($valid);
            $programs[] = [
                'key' => $program,
                'label' => $label,
                'maximumCapacity' => BookingPolicy::getMaxStudentsOfProgram($program),
                'denominator' => count($valid),
                'studentBins' => $this->studentBins($valid),
                'capacityBins' => $this->capacityBins($rows, BookingPolicy::getMaxStudentsOfProgram($program)),
            ];
        }
        $largest = ['count' => 0, 'label' => '', 'program' => ''];
        foreach ($programs as $program) foreach ($program['studentBins'] as $bin) {
            if ($bin['bookings'] > $largest['count']) $largest = ['count' => $bin['bookings'], 'label' => $bin['label'], 'program' => $program['label']];
        }
        return [
            'bins' => self::STUDENT_BINS,
            'capacityBins' => array_map(static fn (array $bin): array => ['key' => $bin['key'], 'label' => $bin['label']], self::CAPACITY_BINS),
            'programs' => $programs,
            'invalidRecordCount' => $invalid,
            'definitions' => [
                'studentBands' => 'Gesloten intervallen: 1–20, 21–40, 41–80, 81–120 en 121–160 leerlingen.',
                'capacity' => 'Leerlingenaantal gedeeld door de centrale programmacapaciteit.',
            ],
            'context' => $largest['count'] >= 3
                ? "De meeste {$largest['program']}-aanvragen vallen in {$largest['label']} leerlingen."
                : 'Nog onvoldoende aanvragen voor een betrouwbare hoofdconclusie.',
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function studentBins(array $rows): array
    {
        $result = [];
        foreach (self::STUDENT_BINS as $bin) {
            $selected = array_values(array_filter($rows, static fn (array $row): bool => $row['students'] >= $bin['min'] && $row['students'] <= $bin['max']));
            $students = $this->sumStudents($selected);
            $result[] = [
                'key' => $bin['key'], 'label' => $bin['label'], 'bookings' => count($selected),
                'percentage' => $this->ratio(count($selected), count($rows)), 'students' => $students,
                'averageStudents' => $this->average($students, count($selected)),
                'sectorDistribution' => $this->sectorCounts($selected),
            ];
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function capacityBins(array $rows, int $capacity): array
    {
        $valid = array_values(array_filter($rows, static fn (array $row): bool => is_int($row['students']) && $row['students'] > 0));
        $result = [];
        foreach (self::CAPACITY_BINS as $index => $bin) {
            $selected = array_values(array_filter($valid, static function (array $row) use ($bin, $capacity, $index): bool {
                $ratio = $row['students'] / $capacity;
                return $index === 0 ? $ratio <= $bin['max']
                    : ($ratio > $bin['min'] && $ratio <= $bin['max']);
            }));
            $result[] = [
                'key' => $bin['key'], 'label' => $bin['label'], 'bookings' => count($selected),
                'percentage' => $this->ratio(count($selected), count($valid)),
                'students' => $this->sumStudents($selected),
                'averageStudents' => $this->average($this->sumStudents($selected), count($selected)),
                'sectorDistribution' => $this->sectorCounts($selected),
            ];
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $bookings @return array<string, mixed> */
    private function catering(array $bookings): array
    {
        $profiled = array_map(fn (array $row): array => [...$row, 'catering_profile' => $this->cateringProfile($row), 'has_catering' => $this->hasCatering($row)], $bookings);
        $profiles = [];
        foreach ([
            'none' => 'Geen catering', 'snacks' => 'Alleen tussendoor', 'lunch' => 'Alleen lunch',
            'lunch_snacks' => 'Lunch en tussendoor', 'picnic' => 'Eigen picknick', 'other' => 'Andere geldige combinatie',
        ] as $key => $label) {
            $rows = array_values(array_filter($profiled, static fn (array $row): bool => $row['catering_profile'] === $key));
            $profiles[] = $this->cateringRow($key, $label, $rows, count($profiled));
        }
        $schools = [];
        foreach ($profiled as $row) {
            $key = $this->schoolKey($row);
            if ($key !== null) $schools[$key][] = $row;
        }
        $schoolProfiles = ['always' => 0, 'sometimes' => 0, 'never' => 0];
        foreach ($schools as $visits) {
            $with = count(array_filter($visits, static fn (array $row): bool => $row['has_catering']));
            $schoolProfiles[$with === count($visits) ? 'always' : ($with === 0 ? 'never' : 'sometimes')]++;
        }
        $with = array_values(array_filter($profiled, static fn (array $row): bool => $row['has_catering']));
        $without = array_values(array_filter($profiled, static fn (array $row): bool => !$row['has_catering']));
        return [
            'bookingProfiles' => $profiles,
            'schoolProfiles' => [
                ['key' => 'always', 'label' => 'Altijd catering', 'count' => $schoolProfiles['always'], 'percentage' => $this->ratio($schoolProfiles['always'], count($schools))],
                ['key' => 'sometimes', 'label' => 'Soms catering', 'count' => $schoolProfiles['sometimes'], 'percentage' => $this->ratio($schoolProfiles['sometimes'], count($schools))],
                ['key' => 'never', 'label' => 'Nooit catering', 'count' => $schoolProfiles['never'], 'percentage' => $this->ratio($schoolProfiles['never'], count($schools))],
            ],
            'programBreakdown' => $this->cateringBreakdown($profiled, BookingPolicy::PROGRAM_LABELS, 'program'),
            'sectorBreakdown' => $this->cateringBreakdown($profiled, array_map(static fn (array $row): string => $row['label'], BookingProgramConfig::SCHOOL_TYPES_BY_KEY), 'sector'),
            'sizeBandBreakdown' => $this->cateringSizeBreakdown($profiled),
            'denominators' => ['bookings' => count($profiled), 'schools' => count($schools)],
            'insights' => [
                'bookingPercentage' => $this->ratio(count($with), count($profiled)),
                'schoolAtLeastOncePercentage' => $this->ratio(count(array_filter($schools, static fn (array $rows): bool => count(array_filter($rows, static fn (array $row): bool => $row['has_catering'])) > 0)), count($schools)),
                'averageStudentsWithCatering' => $this->average($this->sumStudents($with), count($with)),
                'averageStudentsWithoutCatering' => $this->average($this->sumStudents($without), count($without)),
            ],
            'definitions' => [
                'catering' => 'GeoFort-catering is minstens één positief snack- of Remiselunchaantal. Eigen picknick telt niet als geleverde catering.',
                'schoolIdentity' => 'Schoolnaam + postcode + land, genormaliseerd met trim, lowercase, compacte spaties en postcode zonder spaties.',
            ],
            'context' => count($schools) >= 5
                ? sprintf('Binnen deze periode bestelt %.0f%% van de unieke scholen minstens één keer catering.', $this->ratio(count(array_filter($schools, static fn (array $rows): bool => count(array_filter($rows, static fn (array $row): bool => $row['has_catering'])) > 0)), count($schools)))
                : 'Nog onvoldoende unieke scholen voor een betrouwbare cateringconclusie.',
        ];
    }

    /** @param list<array<string, mixed>> $bookings @return array<string, mixed> */
    private function years(array $bookings, BookingAnalyticsCriteria $criteria): array
    {
        $groups = [];
        foreach ($bookings as $row) $groups[substr($row['visit_date'], 0, 4)][] = $row;
        ksort($groups);
        $today = new DateTimeImmutable('today');
        $years = [];
        foreach ($groups as $year => $rows) {
            $start = max("{$year}-01-01", (string) $criteria->effectiveStartDate);
            $end = min("{$year}-12-31", (string) $criteria->effectiveEndDate);
            $relation = (int) $year < (int) $today->format('Y') ? 'past' : ((int) $year === (int) $today->format('Y') ? 'current' : 'future');
            $full = $start === "{$year}-01-01" && $end === "{$year}-12-31";
            $status = !$full ? 'partialSelection' : ($relation === 'past' ? 'comparable' : ($relation === 'current' ? 'currentBookingStand' : 'futureBookingStand'));
            $catering = array_values(array_filter($rows, fn (array $row): bool => $this->hasCatering($row)));
            $years[] = [
                'year' => (int) $year, 'coverageStart' => $start, 'coverageEnd' => $end,
                'isFullCalendarYearWithinSelection' => $full, 'relationToCurrentYear' => $relation,
                'comparisonStatus' => $status,
                'metrics' => [
                    'plannedStudents' => $this->sumStudents($rows),
                    'activeBookings' => count($rows),
                    'confirmedBookings' => count(array_filter($rows, static fn (array $row): bool => $row['status'] === BookingPolicy::STATUS_CONFIRMED)),
                    'uniqueSchools' => count($this->schoolKeys($rows)),
                    'uniqueVisitDays' => count(array_unique(array_column($rows, 'visit_date'))),
                    'averageStudents' => $this->average($this->sumStudents($rows), count($rows)),
                    'cateringPercentage' => $this->ratio(count($catering), count($rows)),
                    'rejectionPercentage' => $this->ratio(count(array_filter($rows, static fn (array $row): bool => $row['status'] === BookingPolicy::STATUS_REJECTED)), count($rows)),
                ],
                'programMix' => $this->mix($rows, BookingPolicy::PROGRAM_LABELS, 'program'),
                'sectorMix' => $this->mix($rows, array_map(static fn (array $row): string => $row['label'], BookingProgramConfig::SCHOOL_TYPES_BY_KEY), 'sector'),
            ];
        }
        return [
            'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
            'analyticsAsOfDate' => $today->format('Y-m-d'),
            'years' => $years,
            'availableMetrics' => [
                'plannedStudents' => 'Geplande leerlingen', 'activeBookings' => 'Actieve aanvragen',
                'confirmedBookings' => 'Definitieve aanvragen', 'uniqueSchools' => 'Unieke scholen',
                'uniqueVisitDays' => 'Unieke bezoekdagen', 'averageStudents' => 'Gemiddelde leerlingen',
                'cateringPercentage' => 'Cateringpercentage', 'rejectionPercentage' => 'Afwijzingspercentage',
            ],
            'comparisonAvailability' => 'Jaar-op-jaar is alleen vergelijkbaar voor twee volledige, afgeronde kalenderjaren.',
            'context' => count($years) >= 2 ? 'Lopende en toekomstige jaren zijn een voorlopige boekingsstand, geen definitieve trend.' : 'Nog onvoldoende jaren voor een trendvergelijking.',
        ];
    }

    /** @param list<array<string, mixed>> $bookings @return array<string, mixed> */
    private function seasonality(array $bookings): array
    {
        $monthlyGroups = [];
        $visitDateGroups = [];
        foreach ($bookings as $row) {
            $monthlyGroups[substr($row['visit_date'], 0, 7)][] = $row;
            $visitDateGroups[$row['visit_date']][] = $row;
        }
        ksort($monthlyGroups);
        ksort($visitDateGroups);
        $monthlyBuckets = [];
        foreach ($monthlyGroups as $key => $rows) {
            $dates = array_unique(array_column($rows, 'visit_date'));
            $students = $this->sumStudents($rows);
            $year = (int) substr($key, 0, 4);
            $month = (int) substr($key, 5, 2);
            $monthlyBuckets[] = new MonthlyBucket(
                $year, $month, mb_strtolower(self::MONTHS[$month]) . ' ' . $year, count($rows), count($dates),
                count($this->schoolKeys($rows)),
                $students, $this->average($students, count($dates)),
            );
        }
        $visitDateBuckets = [];
        foreach ($visitDateGroups as $dateValue => $rows) {
            $date = new DateTimeImmutable($dateValue);
            $students = $this->sumStudents($rows);
            $programs = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['program'], $rows)));
            $visitDateBuckets[] = new VisitDateBucket(
                $dateValue, (int) $date->format('N'), self::WEEKDAYS[(int) $date->format('N')], count($rows),
                count(array_filter($rows, static fn (array $row): bool => $row['status'] === BookingPolicy::STATUS_CONFIRMED)),
                count($this->schoolKeys($rows)), $students, $this->average($students, count($rows)),
                count(array_filter($rows, static fn (array $row): bool => $row['program'] === BookingPolicy::PROGRAM_DAY)),
                count(array_filter($rows, static fn (array $row): bool => $row['program'] === BookingPolicy::PROGRAM_MORNING)), $programs,
            );
        }
        $weekdayBuckets = $this->weekdayBuckets($bookings, $visitDateBuckets);
        $ranked = $visitDateBuckets;
        usort($ranked, static fn (VisitDateBucket $a, VisitDateBucket $b): int => $b->students <=> $a->students ?: $b->bookings <=> $a->bookings ?: $a->date <=> $b->date);
        $topDays = array_map(static fn (VisitDateBucket $row, int $index): TopDay => new TopDay($index + 1, $row->date, $row->weekdayLabel, $row->bookings, $row->uniqueSchools, $row->students), array_slice($ranked, 0, 10), array_keys(array_slice($ranked, 0, 10)));
        return [
            'monthlyBuckets' => $monthlyBuckets,
            'weekdayBuckets' => $weekdayBuckets,
            'visitDateBuckets' => $visitDateBuckets,
            'availableMetricsByView' => [
                'monthly' => ['students' => 'Leerlingen', 'bookings' => 'Aanvragen', 'visitDays' => 'Unieke bezoekdagen'],
                'weekday' => ['students' => 'Totaal leerlingen', 'averageStudentsPerVisitDate' => 'Gemiddeld leerlingen per bezoekdag', 'bookings' => 'Aanvragen', 'averageBookingsPerVisitDate' => 'Gemiddeld aanvragen per bezoekdag', 'averageSchoolsPerVisitDate' => 'Gemiddeld scholen per bezoekdag'],
            ],
            'summary' => ['bookings' => count($bookings), 'students' => $this->sumStudents($bookings), 'uniqueVisitDates' => count($visitDateBuckets)],
            'topDays' => $topDays,
            'schoolOccupancy' => $this->schoolOccupancy($visitDateBuckets),
        ];
    }

    /** @param list<array<string, mixed>> $bookings @param list<VisitDateBucket> $visitDates @return list<WeekdayBucket> */
    private function weekdayBuckets(array $bookings, array $visitDates): array
    {
        $result = [];
        foreach (self::WEEKDAYS as $number => $label) {
            $rows = array_values(array_filter($bookings, static fn (array $row): bool => (int) (new DateTimeImmutable($row['visit_date']))->format('N') === $number));
            if ($rows === []) continue;
            $dates = array_values(array_filter($visitDates, static fn (VisitDateBucket $bucket): bool => $bucket->weekdayNumber === $number));
            $students = $this->sumStudents($rows);
            $day = count(array_filter($rows, static fn (array $row): bool => $row['program'] === BookingPolicy::PROGRAM_DAY));
            $morning = count(array_filter($rows, static fn (array $row): bool => $row['program'] === BookingPolicy::PROGRAM_MORNING));
            $result[] = new WeekdayBucket($number, self::WEEKDAY_KEYS[$number], $label, count($rows), count(array_filter($rows, static fn (array $row): bool => $row['status'] === BookingPolicy::STATUS_CONFIRMED)), count($dates), count($this->schoolKeys($rows)), $students, $this->average($students, count($dates)), $this->average($students, count($rows)), $this->average(count($rows), count($dates)), count($dates) > 0 ? array_sum(array_map(static fn (VisitDateBucket $bucket): int => $bucket->uniqueSchools, $dates)) / count($dates) : 0.0, $day, $morning, $day > 0 && $morning > 0 ? 'Dag- en ochtendprogramma' : ($day > 0 ? 'Dagprogramma' : 'Ochtendprogramma'));
        }
        return $result;
    }

    /** @param list<VisitDateBucket> $visitDates */
    private function schoolOccupancy(array $visitDates): SchoolOccupancyAnalysis
    {
        $definitions = [
            ['key' => 'one', 'label' => '1 school', 'min' => 1, 'max' => 1, 'quality' => false],
            ['key' => 'two', 'label' => '2 scholen', 'min' => 2, 'max' => 2, 'quality' => false],
            ['key' => 'threePlus', 'label' => '3 of meer scholen', 'min' => 3, 'max' => null, 'quality' => true],
            ['key' => 'unknown', 'label' => 'Onbekend', 'min' => null, 'max' => null, 'quality' => true],
        ];
        $categories = [];
        foreach ($definitions as $definition) {
            $selected = array_values(array_filter($visitDates, static fn (VisitDateBucket $bucket): bool => $definition['key'] === 'unknown' ? $bucket->uniqueSchools === 0 : ($bucket->uniqueSchools >= $definition['min'] && ($definition['max'] === null || $bucket->uniqueSchools <= $definition['max']))));
            $bookings = array_sum(array_map(static fn (VisitDateBucket $bucket): int => $bucket->bookings, $selected));
            $students = array_sum(array_map(static fn (VisitDateBucket $bucket): int => $bucket->students, $selected));
            $categories[] = new SchoolOccupancyCategory($definition['key'], $definition['label'], $definition['min'], $definition['max'], count($selected), $this->ratio(count($selected), count($visitDates)), $bookings, $students, $this->average($students, count($selected)), $this->average($bookings, count($selected)), $definition['quality']);
        }
        $schoolSum = array_sum(array_map(static fn (VisitDateBucket $bucket): int => $bucket->uniqueSchools, $visitDates));
        return new SchoolOccupancyAnalysis(count($visitDates), count($visitDates) > 0 ? $schoolSum / count($visitDates) : 0.0, $categories, ['definition' => 'Een geboekte bezoekdag is een concrete datum met minstens één aanvraag binnen de actuele globale selectie.', 'schoolIdentity' => 'Een unieke school is schoolnaam + postcode + land, genormaliseerd zoals in de cateringanalyse.'], count(array_filter($visitDates, static fn (VisitDateBucket $bucket): bool => $bucket->uniqueSchools >= 3)));
    }

    /** @param array<string, mixed> $row */
    private function hasCatering(array $row): bool
    {
        return $row['remise_break'] > 0 || $row['kazerne_break'] > 0 || $row['fortgracht_break'] > 0
            || $row['lemonade'] > 0 || $row['water_ice'] > 0 || $row['remise_lunch'] > 0;
    }

    /** @param array<string, mixed> $row */
    private function cateringProfile(array $row): string
    {
        $snacks = $row['remise_break'] > 0 || $row['kazerne_break'] > 0 || $row['fortgracht_break'] > 0 || $row['lemonade'] > 0 || $row['water_ice'] > 0;
        $lunch = $row['remise_lunch'] > 0;
        if ($lunch && $snacks) return 'lunch_snacks';
        if ($lunch) return 'lunch';
        if ($snacks) return 'snacks';
        if ($row['own_picnic']) return 'picnic';
        return 'none';
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function cateringRow(string $key, string $label, array $rows, int $denominator): array
    {
        return ['key' => $key, 'label' => $label, 'count' => count($rows), 'percentage' => $this->ratio(count($rows), $denominator), 'students' => $this->sumStudents($rows), 'denominator' => $denominator];
    }

    /** @param list<array<string, mixed>> $rows @param array<string, string> $labels @return list<array<string, mixed>> */
    private function cateringBreakdown(array $rows, array $labels, string $field): array
    {
        $result = [];
        foreach ($labels as $key => $label) {
            $selected = array_values(array_filter($rows, static fn (array $row): bool => $row[$field] === $key));
            $with = count(array_filter($selected, static fn (array $row): bool => $row['has_catering']));
            $result[] = ['label' => $label, 'bookings' => count($selected), 'withCatering' => $with, 'percentage' => $this->ratio($with, count($selected))];
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function cateringSizeBreakdown(array $rows): array
    {
        $result = [];
        foreach (self::STUDENT_BINS as $bin) {
            $selected = array_values(array_filter($rows, static fn (array $row): bool => is_int($row['students']) && $row['students'] >= $bin['min'] && $row['students'] <= $bin['max']));
            $with = count(array_filter($selected, static fn (array $row): bool => $row['has_catering']));
            $result[] = ['label' => $bin['label'], 'bookings' => count($selected), 'withCatering' => $with, 'percentage' => $this->ratio($with, count($selected))];
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $rows @param array<string, string> $labels @return list<array<string, mixed>> */
    private function mix(array $rows, array $labels, string $field): array
    {
        return array_map(fn (string $key, string $label): array => [
            'label' => $label,
            'count' => count(array_filter($rows, static fn (array $row): bool => $row[$field] === $key)),
            'percentage' => $this->ratio(count(array_filter($rows, static fn (array $row): bool => $row[$field] === $key)), count($rows)),
        ], array_keys($labels), array_values($labels));
    }

    /** @param list<array<string, mixed>> $rows @return list<array{label:string,count:int}> */
    private function sectorCounts(array $rows): array
    {
        return array_map(static fn (string $key, array $config): array => [
            'label' => $config['label'], 'count' => count(array_filter($rows, static fn (array $row): bool => $row['sector'] === $key)),
        ], array_keys(BookingProgramConfig::SCHOOL_TYPES_BY_KEY), array_values(BookingProgramConfig::SCHOOL_TYPES_BY_KEY));
    }

    /** @param array<string, mixed> $row */
    private function schoolKey(array $row): ?string
    {
        $normalize = static fn (string $value): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
        $school = $normalize((string) $row['school']);
        $postalCode = preg_replace('/\s+/', '', $normalize((string) $row['postal_code'])) ?? '';
        $country = $normalize((string) $row['country']);
        return $school !== '' && $postalCode !== '' && $country !== '' ? $school . '|' . $postalCode . '|' . $country : null;
    }

    /** @param list<array<string, mixed>> $rows @return list<string> */
    private function schoolKeys(array $rows): array
    {
        return array_values(array_unique(array_filter(array_map(fn (array $row): ?string => $this->schoolKey($row), $rows))));
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? ($numerator / $denominator) * 100 : 0.0;
    }

    private function average(int $total, int $count): float
    {
        return $count > 0 ? $total / $count : 0.0;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumStudents(array $rows): int
    {
        return array_sum(array_map(static fn (array $row): int => is_int($row['students']) ? max(0, $row['students']) : 0, $rows));
    }
}
