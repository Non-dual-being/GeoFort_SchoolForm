<?php
declare(strict_types=1);

namespace GeoFort\Services\Sql;

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportCriteria;
use GeoFort\Services\Dashboard\Booking\Export\BookingExportDateBounds;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final readonly class BookingExportSqlRepository
{
    private const PERIOD_WHERE = 'bezoekdatum >= :startDate AND bezoekdatum <= :endDate';
    private const ALIASED_PERIOD_WHERE = 'a.bezoekdatum >= :startDate AND a.bezoekdatum <= :endDate';

    public function __construct(private PDO $pdo) {}

    public function findDateBounds(): BookingExportDateBounds
    {
        try {
            $statement = $this->pdo->query(
                'SELECT MIN(bezoekdatum) AS min_date, MAX(bezoekdatum) AS max_date '
                . "FROM aanvragen WHERE bezoekdatum IS NOT NULL AND bezoekdatum >= '1000-01-01'",
            );
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return new BookingExportDateBounds(
                is_array($row) && is_string($row['min_date'] ?? null) ? $row['min_date'] : null,
                is_array($row) && is_string($row['max_date'] ?? null) ? $row['max_date'] : null,
            );
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingExportSqlRepository::findDateBounds]: ' . $exception->getMessage());
            throw new RuntimeException('Exportdatums konden niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return array<string, int|float> */
    public function summarize(BookingExportCriteria $criteria): array
    {
        if (!$criteria->hasOverlap()) return $this->emptySummary();

        $sql = <<<'SQL'
            WITH selected AS (
                SELECT id, status, schoolnaam, bezoekdatum, onderwijs_sector,
                       programma, keuzemodule_key, aantal_leerlingen AS students,
                       CASE WHEN status IN (%s) THEN 1 ELSE 0 END AS is_active
                FROM aanvragen
                WHERE %s
            ),
            daily AS (
                SELECT bezoekdatum, SUM(students) AS day_students
                FROM selected
                WHERE is_active = 1
                GROUP BY bezoekdatum
            )
            SELECT COUNT(*) AS total_bookings,
                   SUM(CASE WHEN status = :confirmedStatus THEN 1 ELSE 0 END) AS confirmed_bookings,
                   SUM(CASE WHEN status = :optionStatus THEN 1 ELSE 0 END) AS option_bookings,
                   SUM(CASE WHEN status = :rejectedStatus THEN 1 ELSE 0 END) AS rejected_bookings,
                   SUM(CASE WHEN status NOT IN (:knownConfirmed, :knownOption, :knownRejected) THEN 1 ELSE 0 END) AS other_bookings,
                   SUM(is_active) AS active_bookings,
                   COALESCE(SUM(students), 0) AS total_students,
                   COALESCE(SUM(CASE WHEN is_active = 1 THEN students ELSE 0 END), 0) AS planned_students,
                   COALESCE(SUM(CASE WHEN status = :studentConfirmed THEN students ELSE 0 END), 0) AS confirmed_students,
                   COALESCE(SUM(CASE WHEN status = :studentOption THEN students ELSE 0 END), 0) AS option_students,
                   COALESCE(SUM(CASE WHEN status = :studentRejected THEN students ELSE 0 END), 0) AS rejected_students,
                   COALESCE(SUM(CASE WHEN is_active = 1 AND onderwijs_sector = :primarySector THEN students ELSE 0 END), 0) AS primary_students,
                   COALESCE(SUM(CASE WHEN is_active = 1 AND onderwijs_sector = :lowerSecondarySector THEN students ELSE 0 END), 0) AS lower_secondary_students,
                   COALESCE(SUM(CASE WHEN is_active = 1 AND onderwijs_sector = :upperSecondarySector THEN students ELSE 0 END), 0) AS upper_secondary_students,
                   COUNT(DISTINCT schoolnaam) AS unique_schools,
                   COUNT(DISTINCT bezoekdatum) AS visit_days,
                   SUM(CASE WHEN is_active = 1 AND programma = :dayProgram THEN 1 ELSE 0 END) AS day_program_bookings,
                   SUM(CASE WHEN is_active = 1 AND programma = :morningProgram THEN 1 ELSE 0 END) AS morning_program_bookings,
                   COALESCE(SUM(CASE WHEN is_active = 1 AND programma = :studentDayProgram THEN students ELSE 0 END), 0) AS day_program_students,
                   COALESCE(SUM(CASE WHEN is_active = 1 AND programma = :studentMorningProgram THEN students ELSE 0 END), 0) AS morning_program_students,
                   COALESCE(AVG(CASE WHEN is_active = 1 AND programma = :averageDayProgram THEN students END), 0) AS average_day_students,
                   COALESCE(AVG(CASE WHEN is_active = 1 AND programma = :averageMorningProgram THEN students END), 0) AS average_morning_students,
                   COALESCE(AVG(CASE WHEN is_active = 1 THEN students END), 0) AS average_active_students,
                   COALESCE((SELECT MAX(day_students) FROM daily), 0) AS maximum_students_one_day
            FROM selected
            SQL;
        $activeStatusParameters = [];
        foreach (array_keys(BookingPolicy::ACTIVE_STATUSES) as $index) {
            $activeStatusParameters[] = ':activeStatus' . $index;
        }
        $sql = sprintf(
            $sql,
            implode(', ', $activeStatusParameters),
            self::PERIOD_WHERE,
        );

        try {
            $statement = $this->pdo->prepare($sql);
            $parameters = [
                ':startDate' => $criteria->effectiveStartDate,
                ':endDate' => $criteria->effectiveEndDate,
                ':confirmedStatus' => BookingPolicy::STATUS_CONFIRMED,
                ':optionStatus' => BookingPolicy::STATUS_OPTION,
                ':rejectedStatus' => BookingPolicy::STATUS_REJECTED,
                ':knownConfirmed' => BookingPolicy::STATUS_CONFIRMED,
                ':knownOption' => BookingPolicy::STATUS_OPTION,
                ':knownRejected' => BookingPolicy::STATUS_REJECTED,
                ':studentConfirmed' => BookingPolicy::STATUS_CONFIRMED,
                ':studentOption' => BookingPolicy::STATUS_OPTION,
                ':studentRejected' => BookingPolicy::STATUS_REJECTED,
                ':primarySector' => 'primairOnderwijs',
                ':lowerSecondarySector' => 'voortgezetOnderbouw',
                ':upperSecondarySector' => 'voortgezetBovenbouw',
                ':dayProgram' => BookingPolicy::PROGRAM_DAY,
                ':morningProgram' => BookingPolicy::PROGRAM_MORNING,
                ':studentDayProgram' => BookingPolicy::PROGRAM_DAY,
                ':studentMorningProgram' => BookingPolicy::PROGRAM_MORNING,
                ':averageDayProgram' => BookingPolicy::PROGRAM_DAY,
                ':averageMorningProgram' => BookingPolicy::PROGRAM_MORNING,
            ];
            foreach (BookingPolicy::ACTIVE_STATUSES as $index => $status) {
                $parameters[':activeStatus' . $index] = $status;
            }
            $statement->execute($parameters);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->normalizeSummary($row) : $this->emptySummary();
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingExportSqlRepository::summarize]: ' . $exception->getMessage());
            throw new RuntimeException('Exportsamenvatting kon niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return array<string, int|float> */
    public function summarizeComposition(BookingExportCriteria $criteria): array
    {
        if (!$criteria->hasOverlap()) {
            return $this->emptyComposition();
        }
        $activeStatusParameters = [];
        foreach (array_keys(BookingPolicy::ACTIVE_STATUSES) as $index) {
            $activeStatusParameters[] = ':compositionActiveStatus' . $index;
        }
        $sql = <<<'SQL'
            SELECT COUNT(*) AS composition_bookings,
                   SUM(CASE WHEN onderwijs_sector IN (:lowerSecondary, :upperSecondary) THEN 1 ELSE 0 END) AS vo_composition_bookings,
                   SUM(CASE WHEN onderwijs_sector IN (:oneLowerSecondary, :oneUpperSecondary) AND level_count = 1 THEN 1 ELSE 0 END) AS vo_one_level,
                   SUM(CASE WHEN onderwijs_sector IN (:multiLowerSecondary, :multiUpperSecondary) AND level_count >= 2 THEN 1 ELSE 0 END) AS vo_multiple_levels,
                   SUM(CASE WHEN group_count = 1 THEN 1 ELSE 0 END) AS one_group,
                   SUM(CASE WHEN group_count = 2 THEN 1 ELSE 0 END) AS two_groups,
                   SUM(CASE WHEN group_count >= 3 THEN 1 ELSE 0 END) AS three_or_more_groups,
                   COALESCE(AVG(group_count), 0) AS average_groups
            FROM (
                SELECT selections.aanvraag_id, a.onderwijs_sector,
                       COUNT(DISTINCT selections.level_key) AS level_count,
                       COUNT(DISTINCT selections.level_key, selections.group_key) AS group_count
                FROM aanvraag_onderwijs_selecties selections
                INNER JOIN aanvragen a ON a.id = selections.aanvraag_id
                WHERE %s AND a.status IN (%s)
                GROUP BY selections.aanvraag_id, a.onderwijs_sector
            ) composition
            SQL;
        try {
            $statement = $this->pdo->prepare(sprintf(
                $sql,
                self::ALIASED_PERIOD_WHERE,
                implode(', ', $activeStatusParameters),
            ));
            $parameters = [
                ...$this->dateParameters($criteria),
                'lowerSecondary' => 'voortgezetOnderbouw',
                'upperSecondary' => 'voortgezetBovenbouw',
                'oneLowerSecondary' => 'voortgezetOnderbouw',
                'oneUpperSecondary' => 'voortgezetBovenbouw',
                'multiLowerSecondary' => 'voortgezetOnderbouw',
                'multiUpperSecondary' => 'voortgezetBovenbouw',
            ];
            foreach (BookingPolicy::ACTIVE_STATUSES as $index => $status) {
                $parameters['compositionActiveStatus' . $index] = $status;
            }
            $statement->execute($parameters);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->normalizeComposition($row) : $this->emptyComposition();
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingExportSqlRepository::summarizeComposition]: ' . $exception->getMessage());
            throw new RuntimeException('Selectiesamenvatting kon niet worden opgehaald.', 0, $exception);
        }
    }

    /** @return list<array{module_key: string, bookings: int}> */
    public function summarizeChoiceModules(BookingExportCriteria $criteria): array
    {
        if (!$criteria->hasOverlap()) return [];
        $activeStatusParameters = [];
        foreach (array_keys(BookingPolicy::ACTIVE_STATUSES) as $index) {
            $activeStatusParameters[] = ':moduleActiveStatus' . $index;
        }
        $sql = 'SELECT keuzemodule_key AS module_key, COUNT(*) AS bookings '
            . 'FROM aanvragen WHERE ' . self::PERIOD_WHERE
            . ' AND status IN (' . implode(', ', $activeStatusParameters) . ')'
            . ' AND programma = :moduleProgram'
            . " AND keuzemodule_key IS NOT NULL AND TRIM(keuzemodule_key) <> ''"
            . ' GROUP BY keuzemodule_key ORDER BY bookings DESC, keuzemodule_key ASC';
        try {
            $statement = $this->pdo->prepare($sql);
            $parameters = [...$this->dateParameters($criteria), 'moduleProgram' => BookingPolicy::PROGRAM_DAY];
            foreach (BookingPolicy::ACTIVE_STATUSES as $index => $status) {
                $parameters['moduleActiveStatus' . $index] = $status;
            }
            $statement->execute($parameters);
            return array_map(
                static fn (array $row): array => [
                    'module_key' => (string) ($row['module_key'] ?? ''),
                    'bookings' => (int) ($row['bookings'] ?? 0),
                ],
                $statement->fetchAll(PDO::FETCH_ASSOC),
            );
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingExportSqlRepository::summarizeChoiceModules]: ' . $exception->getMessage());
            throw new RuntimeException('Keuzemodulesamenvatting kon niet worden opgehaald.', 0, $exception);
        }
    }

    public function count(BookingExportCriteria $criteria): int
    {
        if (!$criteria->hasOverlap()) return 0;
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM aanvragen WHERE ' . self::PERIOD_WHERE,
            );
            $statement->execute($this->dateParameters($criteria));
            return (int) $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new RuntimeException('Export kon niet worden geteld.', 0, $exception);
        }
    }

    /** @return list<int> */
    public function findIds(BookingExportCriteria $criteria): array
    {
        if (!$criteria->hasOverlap()) return [];
        $statement = $this->pdo->prepare(
            'SELECT id FROM aanvragen WHERE ' . self::PERIOD_WHERE . ' '
            . 'ORDER BY bezoekdatum ASC, id ASC',
        );
        $statement->execute($this->dateParameters($criteria));
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function openExportCursor(BookingExportCriteria $criteria): PDOStatement
    {
        if (!$criteria->hasOverlap()) {
            throw new RuntimeException('Een export zonder overlappende periode kan niet worden gestart.');
        }
        $sql = <<<'SQL'
            SELECT a.id, a.status, a.bezoekdatum, a.schoolnaam, a.land, a.adres,
                   a.postcode, a.plaats, a.school_telefoonnummer,
                   a.contactpersoon_telefoonnummer, a.contactpersoon_voornaam,
                   a.contactpersoon_achternaam, a.email, a.hoe_kent_u_geofort,
                   a.opmerkingen, a.cjpPasGebruik, a.cjpContactpersoonNaam,
                   a.cjpPasnummer, a.onderwijs_sector, a.programma,
                   a.keuzemodule_key, a.aantal_leerlingen, a.aantal_begeleiders,
                   a.remise_break, a.kazerne_break, a.fortgracht_break,
                   a.glas_limonade, a.waterijsje, a.remise_lunch, a.eigen_picknick,
                   selections.level_labels, selections.group_labels,
                   selections.groups_per_level
            FROM aanvragen a
            LEFT JOIN (
                SELECT level_rows.aanvraag_id,
                       GROUP_CONCAT(level_rows.level_label ORDER BY level_rows.level_position SEPARATOR ' | ') AS level_labels,
                       GROUP_CONCAT(level_rows.groups_pipe ORDER BY level_rows.level_position SEPARATOR ' | ') AS group_labels,
                       GROUP_CONCAT(
                           CONCAT(level_rows.level_label, ': ', level_rows.groups_comma)
                           ORDER BY level_rows.level_position SEPARATOR ' | '
                       ) AS groups_per_level
                FROM (
                    SELECT aanvraag_id, level_key, MAX(level_label) AS level_label,
                           MIN(level_position) AS level_position,
                           GROUP_CONCAT(DISTINCT group_label ORDER BY group_position SEPARATOR ' | ') AS groups_pipe,
                           GROUP_CONCAT(DISTINCT group_label ORDER BY group_position SEPARATOR ', ') AS groups_comma
                    FROM aanvraag_onderwijs_selecties
                    GROUP BY aanvraag_id, level_key
                ) level_rows
                GROUP BY level_rows.aanvraag_id
            ) selections ON selections.aanvraag_id = a.id
            WHERE %s
            ORDER BY a.bezoekdatum ASC, a.id ASC
            SQL;
        $sql = sprintf($sql, self::ALIASED_PERIOD_WHERE);
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute($this->dateParameters($criteria));
            return $statement;
        } catch (PDOException $exception) {
            error_log('[SQL ERROR][BookingExportSqlRepository::openExportCursor]: ' . $exception->getMessage());
            throw new RuntimeException('Export kon niet worden voorbereid.', 0, $exception);
        }
    }

    /** @return array{startDate: string, endDate: string} */
    private function dateParameters(BookingExportCriteria $criteria): array
    {
        return [
            'startDate' => (string) $criteria->effectiveStartDate,
            'endDate' => (string) $criteria->effectiveEndDate,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, int|float> */
    private function normalizeSummary(array $row): array
    {
        $integers = [
            'total_bookings', 'confirmed_bookings', 'option_bookings', 'rejected_bookings',
            'other_bookings', 'active_bookings', 'total_students', 'planned_students', 'confirmed_students',
            'option_students', 'rejected_students', 'primary_students',
            'lower_secondary_students', 'upper_secondary_students', 'unique_schools',
            'visit_days', 'day_program_bookings', 'morning_program_bookings',
            'day_program_students', 'morning_program_students', 'maximum_students_one_day',
        ];
        $summary = [];
        foreach ($integers as $key) $summary[$key] = (int) ($row[$key] ?? 0);
        foreach (['average_active_students', 'average_day_students', 'average_morning_students'] as $key) {
            $summary[$key] = round((float) ($row[$key] ?? 0), 1);
        }
        return $summary;
    }

    /** @param array<string, mixed> $row @return array<string, int|float> */
    private function normalizeComposition(array $row): array
    {
        $composition = [];
        foreach ([
            'composition_bookings', 'vo_composition_bookings', 'vo_one_level',
            'vo_multiple_levels', 'one_group', 'two_groups', 'three_or_more_groups',
        ] as $key) {
            $composition[$key] = (int) ($row[$key] ?? 0);
        }
        $composition['average_groups'] = round((float) ($row['average_groups'] ?? 0), 1);
        return $composition;
    }

    /** @return array<string, int|float> */
    private function emptySummary(): array
    {
        return [
            'total_bookings' => 0, 'confirmed_bookings' => 0, 'option_bookings' => 0,
            'rejected_bookings' => 0, 'other_bookings' => 0, 'active_bookings' => 0,
            'total_students' => 0,
            'planned_students' => 0, 'confirmed_students' => 0, 'option_students' => 0,
            'rejected_students' => 0, 'primary_students' => 0,
            'lower_secondary_students' => 0, 'upper_secondary_students' => 0,
            'unique_schools' => 0, 'visit_days' => 0, 'day_program_bookings' => 0,
            'morning_program_bookings' => 0, 'day_program_students' => 0,
            'morning_program_students' => 0, 'average_active_students' => 0.0,
            'average_day_students' => 0.0, 'average_morning_students' => 0.0,
            'maximum_students_one_day' => 0,
        ];
    }

    /** @return array<string, int|float> */
    private function emptyComposition(): array
    {
        return [
            'composition_bookings' => 0, 'vo_composition_bookings' => 0,
            'vo_one_level' => 0, 'vo_multiple_levels' => 0, 'one_group' => 0,
            'two_groups' => 0, 'three_or_more_groups' => 0, 'average_groups' => 0.0,
        ];
    }
}
