<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GeoFort\Services\Dashboard\Booking\Export\BookingExportCriteriaFactory;
use GeoFort\Services\Sql\BookingExportSqlRepository;

$env = [];
foreach (['HOST', 'PORT', 'NAME', 'USER'] as $key) {
    $value = getenv('STATUS_TEST_DB_' . $key);
    if ($value === false) {
        fwrite(STDERR, "SKIP: STATUS_TEST_DB_{$key} ontbreekt.\n");
        exit(0);
    }
    $env[$key] = (string) $value;
}
if (
    getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
    || preg_match('/(test|tmp|scratch|disposable)/i', $env['NAME']) !== 1
    || strtolower((string) getenv('APP_ENV')) === 'production'
) {
    fwrite(STDERR, "FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n");
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['HOST'], $env['PORT'], $env['NAME']),
    $env['USER'],
    (string) (getenv('STATUS_TEST_DB_PASSWORD') ?: ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$cleanup = static function () use ($pdo): void {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $pdo->exec('DROP TABLE IF EXISTS aanvraag_onderwijs_selecties');
        $pdo->exec('DROP TABLE IF EXISTS aanvragen');
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
};
$cleanup();
$exit = 0;

try {
    $pdo->exec(<<<'SQL'
        CREATE TABLE aanvragen (
            id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20) NOT NULL,
            schoolnaam VARCHAR(255) NOT NULL, land VARCHAR(32) NOT NULL,
            adres VARCHAR(255) NOT NULL, postcode VARCHAR(16) NOT NULL,
            plaats VARCHAR(120) NOT NULL, school_telefoonnummer VARCHAR(25) NOT NULL,
            contactpersoon_telefoonnummer VARCHAR(25) NOT NULL,
            contactpersoon_voornaam VARCHAR(255) NOT NULL,
            contactpersoon_achternaam VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL,
            bezoekdatum DATE NULL, hoe_kent_u_geofort VARCHAR(120), opmerkingen TEXT,
            cjpPasGebruik VARCHAR(3) NOT NULL, cjpContactpersoonNaam VARCHAR(80),
            cjpPasnummer VARCHAR(9), onderwijs_sector VARCHAR(40) NOT NULL,
            programma VARCHAR(20) NOT NULL, keuzemodule_key VARCHAR(120),
            aantal_leerlingen INT, aantal_begeleiders INT, remise_break INT NOT NULL DEFAULT 0,
            kazerne_break INT NOT NULL DEFAULT 0, fortgracht_break INT NOT NULL DEFAULT 0,
            glas_limonade INT NOT NULL DEFAULT 0, waterijsje INT NOT NULL DEFAULT 0,
            remise_lunch INT NOT NULL DEFAULT 0, eigen_picknick TINYINT NOT NULL DEFAULT 0,
            KEY idx_date_status (bezoekdatum, status)
        ) ENGINE=InnoDB
        SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE aanvraag_onderwijs_selecties (
            id INT AUTO_INCREMENT PRIMARY KEY, aanvraag_id INT NOT NULL,
            sector_key VARCHAR(40), sector_label VARCHAR(120), level_key VARCHAR(80),
            level_label VARCHAR(160), level_position INT, group_key VARCHAR(80),
            group_label VARCHAR(160), group_position INT,
            FOREIGN KEY (aanvraag_id) REFERENCES aanvragen(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
        SQL);

    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO aanvragen (
            status, schoolnaam, land, adres, postcode, plaats,
            school_telefoonnummer, contactpersoon_telefoonnummer,
            contactpersoon_voornaam, contactpersoon_achternaam, email,
            bezoekdatum, cjpPasGebruik, onderwijs_sector, programma,
            keuzemodule_key, aantal_leerlingen, aantal_begeleiders
        ) VALUES (
            :status, :school, 'Nederland', 'Dijk 1', '1234 AB', :city,
            '010', '061', 'Ada', 'Planner', :email, :visitDate, 'nee',
            :sector, :program, :module, :students, 4
        )
        SQL);
    $selection = $pdo->prepare(<<<'SQL'
        INSERT INTO aanvraag_onderwijs_selecties (
            aanvraag_id, sector_key, sector_label, level_key, level_label,
            level_position, group_key, group_label, group_position
        ) VALUES (:id, :sector, :sectorLabel, :levelKey,
                  :levelLabel, :levelPosition, :groupKey, :groupLabel, :groupPosition)
        SQL);

    $expectedIds = [];
    $expectedStudents = 0;
    $expectedPlannedStudents = 0;
    $expectedStatus = ['Definitief' => 0, 'In optie' => 0, 'Afgewezen' => 0];
    $expectedSectorStudents = ['primairOnderwijs' => 0, 'voortgezetOnderbouw' => 0, 'voortgezetBovenbouw' => 0];
    $expectedPrograms = ['dag' => 0, 'ochtend' => 0];
    $expectedModules = [];
    $expectedActive = 0;
    $expectedActiveVo = 0;
    $expectedVoOneLevel = 0;
    $expectedVoMultipleLevels = 0;
    $expectedGroups = ['one' => 0, 'two' => 0, 'threeOrMore' => 0];
    $expectedGroupTotal = 0;
    $expectedSelectionLabels = [];
    for ($index = 1; $index <= 250; $index++) {
        $matching = $index <= 125;
        $visitDate = $matching ? '2026-09-' . str_pad((string) (($index % 20) + 1), 2, '0', STR_PAD_LEFT) : '2026-10-01';
        $status = ['Definitief', 'In optie', 'Afgewezen'][$index % 3];
        $sector = ['primairOnderwijs', 'voortgezetOnderbouw', 'voortgezetBovenbouw'][$index % 3];
        $students = 20 + ($index % 21);
        $program = $index % 2 === 0 ? 'dag' : 'ochtend';
        $moduleKeys = ['Earth-Watch', 'Klimparcours', 'Stop-de-Klimaat-Klok'];
        $module = $matching && $index % 2 === 0 ? $moduleKeys[$index % count($moduleKeys)] : null;
        $insert->execute([
            ':status' => $status,
            ':school' => $matching ? "Exportschool {$index}" : "Andere school {$index}",
            ':city' => $matching ? 'Leerdam' : 'Utrecht',
            ':email' => "planner{$index}@example.test",
            ':visitDate' => $visitDate,
            ':sector' => $sector,
            ':program' => $program,
            ':module' => $module,
            ':students' => $students,
        ]);
        $id = (int) $pdo->lastInsertId();
        $selectionRows = $sector === 'primairOnderwijs'
            ? [['regulier', 'Regulier', 1, 'groep5', 'Groep 5', 1]]
            : ($index % 2 === 0
                ? [
                    ['regulier', 'Regulier', 1, 'groep5', 'Groep 5', 1],
                    ['regulier', 'Regulier', 1, 'groep6', 'Groep 6', 2],
                    ['verdieping', 'Verdieping', 2, 'groep7', 'Groep 7', 1],
                ]
                : [
                    ['regulier', 'Regulier', 1, 'groep5', 'Groep 5', 1],
                    ['regulier', 'Regulier', 1, 'groep6', 'Groep 6', 2],
                ]);
        $selectionRows[] = $selectionRows[0]; // Bewijst dat dubbele childrecords niet meetellen.
        foreach ($selectionRows as [$levelKey, $levelLabel, $levelPosition, $groupKey, $groupLabel, $groupPosition]) {
            $selection->execute([
                ':id' => $id, ':sector' => $sector, ':sectorLabel' => $sector,
                ':levelKey' => $levelKey, ':levelLabel' => $levelLabel,
                ':levelPosition' => $levelPosition, ':groupKey' => $groupKey,
                ':groupLabel' => $groupLabel, ':groupPosition' => $groupPosition,
            ]);
        }
        if ($matching && $visitDate >= '2026-09-05' && $visitDate <= '2026-09-15') {
            $expectedSelectionLabels[$id] = $sector === 'primairOnderwijs'
                ? ['Regulier', 'Groep 5', 'Regulier: Groep 5']
                : ($index % 2 === 0
                    ? ['Regulier | Verdieping', 'Groep 5 | Groep 6 | Groep 7', 'Regulier: Groep 5, Groep 6 | Verdieping: Groep 7']
                    : ['Regulier', 'Groep 5 | Groep 6', 'Regulier: Groep 5, Groep 6']);
            $expectedIds[] = $id;
            $expectedStudents += $students;
            if ($status !== 'Afgewezen') {
                $expectedPlannedStudents += $students;
                $expectedActive++;
                $expectedSectorStudents[$sector] += $students;
                $expectedPrograms[$program]++;
                $groupCount = $sector === 'primairOnderwijs' ? 1 : ($index % 2 === 0 ? 3 : 2);
                $expectedGroupTotal += $groupCount;
                $expectedGroups[$groupCount === 1 ? 'one' : ($groupCount === 2 ? 'two' : 'threeOrMore')]++;
                if ($sector !== 'primairOnderwijs') {
                    $expectedActiveVo++;
                    if ($index % 2 === 0) $expectedVoMultipleLevels++;
                    else $expectedVoOneLevel++;
                }
                if ($module !== null) {
                    $expectedModules[$module] = ($expectedModules[$module] ?? 0) + 1;
                }
            }
            $expectedStatus[$status]++;
        }
    }
    $pdo->exec("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,aantal_leerlingen,aantal_begeleiders) VALUES('In optie','Zonder datum','Nederland','Dijk 1','1234 AB','Leerdam','010','061','Ada','Planner','null@example.test',NULL,'nee','primairOnderwijs','dag',99,4)");

    $repository = new BookingExportSqlRepository($pdo);
    $bounds = $repository->findDateBounds();
    if ($bounds->minDate !== '2026-09-01' || $bounds->maxDate !== '2026-10-01') {
        throw new RuntimeException('Metadata-grenzen zijn onjuist of NULL-datum telt mee.');
    }
    $criteria = (new BookingExportCriteriaFactory())->create(
        ['startDate' => '2026-09-05', 'endDate' => '2026-09-15'],
        $bounds,
    );
    $summary = $repository->summarize($criteria);
    $summaryIds = $repository->findIds($criteria);
    $count = $repository->count($criteria);
    $statement = $repository->openExportCursor($criteria);
    $exportIds = [];
    while (($row = $statement->fetch()) !== false) {
        $exportIds[] = (int) $row['id'];
        $labels = $expectedSelectionLabels[(int) $row['id']] ?? null;
        if (
            $labels === null
            || $row['level_labels'] !== $labels[0]
            || $row['group_labels'] !== $labels[1]
            || $row['groups_per_level'] !== $labels[2]
        ) {
            throw new RuntimeException('Volledige gekoppelde onderwijslabels ontbreken.');
        }
    }

    if ($count < 50) throw new RuntimeException('Grote fixture-set selecteert te weinig rijen.');
    if ($expectedIds !== $summaryIds || $summaryIds !== $exportIds) throw new RuntimeException('Summary en CSV selecteren andere booking-ID’s.');
    if (count($exportIds) !== $count || (int) $summary['total_bookings'] !== $count) throw new RuntimeException('Exportcount wijkt af of is gepagineerd.');
    if ((int) $summary['total_students'] !== $expectedStudents) throw new RuntimeException('Leerlingtotaal is vermenigvuldigd door onderwijsselecties.');
    if ((int) $summary['planned_students'] !== $expectedPlannedStudents) throw new RuntimeException('Geplande leerlingen gebruiken verkeerde statussen.');
    if (
        (int) $summary['confirmed_bookings'] !== $expectedStatus['Definitief']
        || (int) $summary['option_bookings'] !== $expectedStatus['In optie']
        || (int) $summary['rejected_bookings'] !== $expectedStatus['Afgewezen']
    ) throw new RuntimeException('Statusverdeling is onjuist.');
    if (
        (int) $summary['primary_students'] !== $expectedSectorStudents['primairOnderwijs']
        || (int) $summary['lower_secondary_students'] !== $expectedSectorStudents['voortgezetOnderbouw']
        || (int) $summary['upper_secondary_students'] !== $expectedSectorStudents['voortgezetBovenbouw']
    ) throw new RuntimeException('Sectorverdeling is onjuist.');
    if (
        (int) $summary['day_program_bookings'] !== $expectedPrograms['dag']
        || (int) $summary['morning_program_bookings'] !== $expectedPrograms['ochtend']
        || (int) $summary['active_bookings'] !== $expectedActive
    ) throw new RuntimeException('Actieve programmaverdeling is onjuist.');
    $selectionSummary = $repository->summarizeComposition($criteria);
    if (
        $selectionSummary['composition_bookings'] !== $expectedActive
        || $selectionSummary['vo_composition_bookings'] !== $expectedActiveVo
        || $selectionSummary['vo_one_level'] !== $expectedVoOneLevel
        || $selectionSummary['vo_multiple_levels'] !== $expectedVoMultipleLevels
        || $selectionSummary['one_group'] !== $expectedGroups['one']
        || $selectionSummary['two_groups'] !== $expectedGroups['two']
        || $selectionSummary['three_or_more_groups'] !== $expectedGroups['threeOrMore']
        || (float) $selectionSummary['average_groups'] !== round($expectedGroupTotal / $expectedActive, 1)
    ) throw new RuntimeException('Managementverdeling is onjuist of telt childduplicaten mee.');
    $moduleSummary = $repository->summarizeChoiceModules($criteria);
    $actualModules = [];
    foreach ($moduleSummary as $moduleRow) $actualModules[$moduleRow['module_key']] = $moduleRow['bookings'];
    ksort($actualModules);
    ksort($expectedModules);
    if ($actualModules !== $expectedModules) {
        throw new RuntimeException('Keuzemoduleverdeling gebruikt niet alleen actieve dagprogramma’s.');
    }
    if ((int) $summary['visit_days'] !== 11 || (int) $summary['unique_schools'] !== $count) {
        throw new RuntimeException('Bezoekdagen of unieke scholen zijn onjuist.');
    }
    $pdo->exec('DELETE FROM aanvraag_onderwijs_selecties');
    $pdo->exec('DELETE FROM aanvragen');
    if (!$repository->findDateBounds()->isEmpty()) {
        throw new RuntimeException('Lege database geeft geen lege datumgrenzen.');
    }

    echo "Booking CSV export MariaDB integration tests passed ({$count} rows, one export query).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: {$exception->getMessage()}\n");
    $exit = 1;
} finally {
    $cleanup();
}

exit($exit);
