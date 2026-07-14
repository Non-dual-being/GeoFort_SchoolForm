<?php

declare(strict_types=1);

use GeoFort\Booking\BookingPolicy;
use GeoFort\Booking\BookingProgramConfig;
use GeoFort\Database\Connector;
use GeoFort\Services\Migration\LegacyBookingMapper;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dit script mag alleen via CLI worden uitgevoerd.\n");
    exit(1);
}

const SOURCE_DATABASE = 'school_db';
const TARGET_DATABASE = 'onderwijsboeking_v2';

/** @return never */
function fail(string $message, int $code = 1): void
{
    fwrite(STDERR, "FOUT: {$message}\n");
    exit($code);
}

/** @return array{execute: bool, replace: bool, confirmation: ?string} */
function parseOptions(array $arguments): array
{
    $allowed = ['--dry-run', '--execute', '--replace-target'];
    $result = ['execute' => false, 'replace' => false, 'confirmation' => null];
    $dryRunWasExplicit = false;
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--execute') $result['execute'] = true;
        elseif ($argument === '--replace-target') $result['replace'] = true;
        elseif ($argument === '--dry-run') $dryRunWasExplicit = true;
        elseif (str_starts_with($argument, '--confirm-replace=')) $result['confirmation'] = substr($argument, 18);
        elseif (!in_array($argument, $allowed, true)) fail("Onbekende optie: {$argument}", 2);
    }
    if ($dryRunWasExplicit && $result['execute']) fail('--dry-run en --execute mogen niet worden gecombineerd.', 2);
    if (($result['replace'] || $result['confirmation'] !== null) && !$result['execute']) {
        fail('--replace-target en --confirm-replace hebben alleen betekenis samen met --execute.', 2);
    }
    if ($result['execute'] && (!$result['replace'] || $result['confirmation'] !== TARGET_DATABASE)) {
        fail('Execute vereist --replace-target én --confirm-replace=' . TARGET_DATABASE . '.', 2);
    }
    return $result;
}

/** @param array<string, int> $counts */
function printCounts(string $title, array $counts): void
{
    fwrite(STDOUT, "\n{$title}:\n");
    ksort($counts);
    if ($counts === []) fwrite(STDOUT, "  (geen)\n");
    foreach ($counts as $key => $count) fwrite(STDOUT, '  ' . ($key === '' ? '[leeg]' : $key) . ": {$count}\n");
}

/** @param list<array{id: int, message: string}> $items */
function printIssues(string $title, array $items): void
{
    fwrite(STDOUT, "\n{$title} (" . count($items) . "):\n");
    if ($items === []) fwrite(STDOUT, "  (geen)\n");
    foreach ($items as $item) fwrite(STDOUT, "  ID {$item['id']}: {$item['message']}\n");
}

/** @param list<array{id: int, message: string}> $items */
function countMatching(array $items, string $needle): int
{
    return count(array_filter($items, static fn (array $item): bool => stripos($item['message'], $needle) !== false));
}

/** @return int|null */
function characterCapacity(string $dataType, mixed $reportedLength): ?int
{
    if ($reportedLength !== null) return (int) $reportedLength;
    return match (strtolower($dataType)) {
        'tinytext' => 255,
        'text' => 65_535,
        'mediumtext' => 16_777_215,
        'longtext' => 4_294_967_295,
        default => null,
    };
}

/** @param array<string, list<string>> $requiredColumns */
function assertDatabasePermissions(PDO $pdo, array $requiredColumns): void
{
    $checks = [
        ['SELECT', SOURCE_DATABASE . '.aanvragen', 'EXPLAIN SELECT id FROM ' . SOURCE_DATABASE . '.aanvragen WHERE 1=0'],
        ['SELECT', TARGET_DATABASE . '.aanvragen', 'EXPLAIN SELECT id FROM ' . TARGET_DATABASE . '.aanvragen WHERE 1=0'],
        ['SELECT', TARGET_DATABASE . '.aanvraag_onderwijs_selecties', 'EXPLAIN SELECT aanvraag_id FROM ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties WHERE 1=0'],
        ['INSERT', TARGET_DATABASE . '.aanvragen', 'EXPLAIN INSERT INTO ' . TARGET_DATABASE . '.aanvragen (`' . implode('`,`', $requiredColumns['aanvragen']) . '`) SELECT ' . implode(',', array_fill(0, count($requiredColumns['aanvragen']), 'NULL')) . ' WHERE 1=0'],
        ['INSERT', TARGET_DATABASE . '.aanvraag_onderwijs_selecties', 'EXPLAIN INSERT INTO ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties (`' . implode('`,`', $requiredColumns['aanvraag_onderwijs_selecties']) . '`) SELECT ' . implode(',', array_fill(0, count($requiredColumns['aanvraag_onderwijs_selecties']), 'NULL')) . ' WHERE 1=0'],
        ['DELETE', TARGET_DATABASE . '.aanvragen', 'EXPLAIN DELETE FROM ' . TARGET_DATABASE . '.aanvragen WHERE 1=0'],
        ['DELETE', TARGET_DATABASE . '.aanvraag_onderwijs_selecties', 'EXPLAIN DELETE FROM ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties WHERE 1=0'],
        ['DELETE', TARGET_DATABASE . '.form_submit_log', 'EXPLAIN DELETE FROM ' . TARGET_DATABASE . '.form_submit_log WHERE 1=0'],
    ];
    foreach ($checks as [$operation, $table, $sql]) {
        try {
            $pdo->query($sql)->closeCursor();
        } catch (Throwable $e) {
            throw new RuntimeException("Databasepermissie ontbreekt of kan niet worden bevestigd: {$operation} op {$table}.", 0, $e);
        }
    }
}

/** @param list<array<string, mixed>> $rows @return array<string, int|float> */
function totals(array $rows): array
{
    $columns = ['aantal_leerlingen', 'aantal_begeleiders', 'remise_break', 'kazerne_break', 'fortgracht_break', 'glas_limonade', 'waterijsje', 'remise_lunch', 'eigen_picknick'];
    $result = array_fill_keys($columns, 0);
    foreach ($rows as $row) foreach ($columns as $column) $result[$column] += (int) ($row[$column] ?? 0);
    return $result;
}

/** @param list<array<string, mixed>> $bookings @param list<array<string, mixed>> $selections */
function executeMigration(PDO $pdo, array $bookings, array $selections, array $sourceStatusCounts): void
{
    $bookingColumns = array_keys($bookings[0]);
    $selectionColumns = array_keys($selections[0]);
    $bookingSql = 'INSERT INTO ' . TARGET_DATABASE . '.aanvragen (`' . implode('`, `', $bookingColumns) . '`) VALUES (:' . implode(', :', $bookingColumns) . ')';
    $selectionSql = 'INSERT INTO ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties (`' . implode('`, `', $selectionColumns) . '`) VALUES (:' . implode(', :', $selectionColumns) . ')';

    if (!$pdo->inTransaction()) throw new RuntimeException('Execute vereist een actieve consistente-snapshottransactie.');
    try {
        $pdo->exec('DELETE FROM ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties');
        $pdo->exec('DELETE FROM ' . TARGET_DATABASE . '.aanvragen');
        $pdo->exec('DELETE FROM ' . TARGET_DATABASE . '.form_submit_log');
        $insertBooking = $pdo->prepare($bookingSql);
        foreach ($bookings as $booking) $insertBooking->execute($booking);
        $insertSelection = $pdo->prepare($selectionSql);
        foreach ($selections as $selection) $insertSelection->execute($selection);

        $targetCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . TARGET_DATABASE . '.aanvragen')->fetchColumn();
        if ($targetCount !== count($bookings)) throw new RuntimeException("Doelaantal {$targetCount} wijkt af van bron " . count($bookings));
        $targetSelectionCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties')->fetchColumn();
        if ($targetSelectionCount !== count($selections)) throw new RuntimeException('Aantal onderwijsselecties wijkt af.');

        $statusRows = $pdo->query('SELECT status, COUNT(*) AS aantal FROM ' . TARGET_DATABASE . '.aanvragen GROUP BY status')->fetchAll();
        $targetStatuses = [];
        foreach ($statusRows as $row) $targetStatuses[(string) $row['status']] = (int) $row['aantal'];
        ksort($targetStatuses); ksort($sourceStatusCounts);
        if ($targetStatuses !== $sourceStatusCounts) throw new RuntimeException('Statusaantallen wijken af.');

        $targetTotals = $pdo->query('SELECT SUM(aantal_leerlingen) aantal_leerlingen, SUM(aantal_begeleiders) aantal_begeleiders, SUM(remise_break) remise_break, SUM(kazerne_break) kazerne_break, SUM(fortgracht_break) fortgracht_break, SUM(glas_limonade) glas_limonade, SUM(waterijsje) waterijsje, SUM(remise_lunch) remise_lunch, SUM(eigen_picknick) eigen_picknick FROM ' . TARGET_DATABASE . '.aanvragen')->fetch();
        foreach (totals($bookings) as $column => $expected) if ((int) $targetTotals[$column] !== $expected) throw new RuntimeException("Totaal {$column} wijkt af.");

        $orphanCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties s LEFT JOIN ' . TARGET_DATABASE . '.aanvragen a ON a.id=s.aanvraag_id WHERE a.id IS NULL')->fetchColumn();
        if ($orphanCount !== 0) throw new RuntimeException("{$orphanCount} orphan onderwijsselecties gevonden.");
        $duplicateCount = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT aanvraag_id, level_key, group_key FROM ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties GROUP BY aanvraag_id, level_key, group_key HAVING COUNT(*) > 1) d')->fetchColumn();
        if ($duplicateCount !== 0) throw new RuntimeException("{$duplicateCount} dubbele onderwijsselecties gevonden.");
        $withoutSelection = (int) $pdo->query('SELECT COUNT(*) FROM ' . TARGET_DATABASE . '.aanvragen a LEFT JOIN ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties s ON s.aanvraag_id=a.id WHERE s.aanvraag_id IS NULL')->fetchColumn();
        if ($withoutSelection !== 0) throw new RuntimeException("{$withoutSelection} aanvragen zonder onderwijsselectie gevonden.");
        $maxTarget = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . TARGET_DATABASE . '.aanvragen')->fetchColumn();
        $maxSource = max(array_column($bookings, 'id'));
        if ($maxTarget !== $maxSource) throw new RuntimeException('MAX(id) wijkt af.');
        $maxSelection = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM ' . TARGET_DATABASE . '.aanvraag_onderwijs_selecties')->fetchColumn();
        $autoStatement = $pdo->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table');
        foreach (['aanvragen' => $maxTarget, 'aanvraag_onderwijs_selecties' => $maxSelection] as $table => $maximum) {
            $autoStatement->execute(['schema' => TARGET_DATABASE, 'table' => $table]);
            $next = (int) $autoStatement->fetchColumn();
            if ($next <= $maximum) throw new RuntimeException("AUTO_INCREMENT van {$table} staat niet boven MAX(id).");
        }
        $invalidSectorCount = (int) $pdo->query("SELECT COUNT(*) FROM " . TARGET_DATABASE . ".aanvragen WHERE onderwijs_sector NOT IN ('primairOnderwijs','voortgezetOnderbouw','voortgezetBovenbouw')")->fetchColumn();
        if ($invalidSectorCount !== 0) throw new RuntimeException('Ongeldige onderwijs_sectorwaarden gevonden.');
        $invalidProgramCount = (int) $pdo->query("SELECT COUNT(*) FROM " . TARGET_DATABASE . ".aanvragen WHERE programma NOT IN ('dag','ochtend')")->fetchColumn();
        if ($invalidProgramCount !== 0) throw new RuntimeException('Ongeldige programmawaarden gevonden.');

        fwrite(STDOUT, "\nSteekproef (maximaal vijf, gespreid per sector):\n");
        $samples = $pdo->query("SELECT id, onderwijs_sector, schoolnaam, programma, aantal_leerlingen FROM " . TARGET_DATABASE . ".aanvragen WHERE id IN (SELECT MIN(id) FROM " . TARGET_DATABASE . ".aanvragen GROUP BY onderwijs_sector) UNION SELECT id, onderwijs_sector, schoolnaam, programma, aantal_leerlingen FROM " . TARGET_DATABASE . ".aanvragen WHERE id IN (SELECT MAX(id) FROM " . TARGET_DATABASE . ".aanvragen GROUP BY onderwijs_sector) ORDER BY onderwijs_sector, id LIMIT 5")->fetchAll();
        foreach ($samples as $sample) fwrite(STDOUT, "  ID {$sample['id']} | {$sample['onderwijs_sector']} | {$sample['schoolnaam']} | {$sample['programma']} | leerlingen={$sample['aantal_leerlingen']}\n");

        $pdo->commit();
        fwrite(STDOUT, "\nEindrapport:\n  gemigreerd: {$targetCount}\n  overgeslagen: 0\n  waarschuwingen: zie preflight\n  fouten: 0\n  transactiestatus: COMMIT\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw new RuntimeException('Migratie teruggedraaid: ' . $e->getMessage(), 0, $e);
    }
}

$options = parseOptions($argv);
$mode = $options['execute'] ? 'EXECUTE/REPLACE' : 'DRY-RUN';
fwrite(STDOUT, "Legacy booking migration — {$mode}\n");

try {
    $container = require dirname(__DIR__) . '/bootstrap.php';
    /** @var PDO $pdo */
    $pdo = $container['db'][Connector::class] ?? throw new RuntimeException('PDO ontbreekt in bootstrap-container.');
    $configuredDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($configuredDatabase !== TARGET_DATABASE) throw new RuntimeException("DB_NAME moet exact " . TARGET_DATABASE . " zijn; actief: {$configuredDatabase}");
    $serverVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $currentUser = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    fwrite(STDOUT, "PDO serverversie: {$serverVersion}\nCURRENT_USER(): {$currentUser}\nBrondatabase: " . SOURCE_DATABASE . "\nDoeldatabase: " . TARGET_DATABASE . "\n");
    fwrite(STDOUT, "Operationele waarschuwing: plan voor de definitieve livecutover een korte schrijfstop op het oude formulier; bronrecords die na de execute-snapshot ontstaan, worden niet meegenomen.\n");
    $engineStatement = $pdo->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME IN (\'aanvragen\', \'aanvraag_onderwijs_selecties\', \'form_submit_log\')');
    $engineStatement->execute(['schema' => TARGET_DATABASE]);
    $engines = $engineStatement->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['aanvragen', 'aanvraag_onderwijs_selecties', 'form_submit_log'] as $table) {
        if (($engines[$table] ?? null) !== 'InnoDB') throw new RuntimeException("Doeltabel {$table} ontbreekt of is niet transactioneel InnoDB.");
    }
    $requiredColumns = [
        'aanvragen' => ['id','status','schoolnaam','land','adres','postcode','plaats','school_telefoonnummer','contactpersoon_telefoonnummer','contactpersoon_voornaam','contactpersoon_achternaam','email','bezoekdatum','hoe_kent_u_geofort','opmerkingen','cjpPasGebruik','cjpContactpersoonNaam','cjpPasnummer','onderwijs_sector','programma','keuzemodule_key','aantal_leerlingen','aantal_begeleiders','remise_break','kazerne_break','fortgracht_break','glas_limonade','waterijsje','remise_lunch','eigen_picknick','voorwaarden_akkoord','voorwaarden_akkoord_op'],
        'aanvraag_onderwijs_selecties' => ['aanvraag_id','sector_key','sector_label','level_key','level_label','level_position','group_key','group_label','group_position'],
    ];
    $columnStatement = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:schema AND TABLE_NAME=:table');
    $columnLengths = [];
    foreach ($requiredColumns as $table => $columns) {
        $columnStatement->execute(['schema' => TARGET_DATABASE, 'table' => $table]);
        $actual = [];
        foreach ($columnStatement->fetchAll() as $column) {
            $actual[(string) $column['COLUMN_NAME']] = characterCapacity((string) $column['DATA_TYPE'], $column['CHARACTER_MAXIMUM_LENGTH']);
        }
        $missing = array_diff($columns, array_keys($actual));
        if ($missing !== []) throw new RuntimeException("Doeltabel {$table} mist kolommen: " . implode(', ', $missing));
        if ($table === 'aanvragen' && (int) ($actual['keuzemodule_key'] ?? 0) < max(array_map('strlen', array_keys(BookingProgramConfig::MODULE_LABELS)))) {
            throw new RuntimeException('keuzemodule_key is te kort voor de langste geconfigureerde modulekey.');
        }
        $columnLengths[$table] = $actual;
    }

    $sourceColumns = ['id','status','schoolnaam','adres','postcode','plaats','school_telefoon','contact_telefoon','voornaam_contactpersoon','achternaam_contactpersoon','email','bezoekdatum','hoe_kent_u_geofort','cjp_korting','schooltype','programma_duur','niveau1','niveau2','niveau3','leeftijdsgroep1','leeftijdsgroep2','leeftijdsgroep3','leeftijdsgroep4','leeftijdsgroep5','keuze_module','aantal_leerlingen','aantal_begeleiders','remise_break','kazerne_break','fortgracht_break','waterijsje','glas_limonade','remise_lunch','eigen_picknick','opmerkingen'];
    $columnStatement->execute(['schema' => SOURCE_DATABASE, 'table' => 'aanvragen']);
    $actualSourceColumns = array_column($columnStatement->fetchAll(), 'COLUMN_NAME');
    $missingSourceColumns = array_diff($sourceColumns, $actualSourceColumns);
    if ($missingSourceColumns !== []) throw new RuntimeException('Brontabel mist kolommen: ' . implode(', ', $missingSourceColumns));

    assertDatabasePermissions($pdo, $requiredColumns);
    if ($options['execute']) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        if (!$pdo->inTransaction()) throw new RuntimeException('Consistente snapshottransactie kon niet worden gestart.');
    }
    $legacyRows = $pdo->query('SELECT * FROM ' . SOURCE_DATABASE . '.aanvragen ORDER BY id')->fetchAll();
    $mapper = new LegacyBookingMapper();
    $bookings = []; $selections = []; $errors = []; $warnings = [];
    $statusCounts = []; $sectorCounts = []; $programCounts = []; $choiceCounts = [];
    $possibleBelgian = []; $htmlEntityRecords = []; $longComments = []; $longDiscoveryValues = []; $seenIds = [];
    foreach ($legacyRows as $legacy) {
        $id = (int) $legacy['id'];
        if (isset($seenIds[$id])) $errors[] = ['id' => $id, 'message' => 'Dubbel legacy-ID.'];
        $seenIds[$id] = true;
        $mapped = $mapper->map($legacy);
        $bookings[] = $mapped['booking'];
        array_push($selections, ...$mapped['selections']);
        foreach ($mapped['errors'] as $message) $errors[] = ['id' => $id, 'message' => $message];
        foreach ($mapped['warnings'] as $message) $warnings[] = ['id' => $id, 'message' => $message];
        if (mb_strlen((string) ($mapped['booking']['opmerkingen'] ?? ''), 'UTF-8') > 600) $longComments[] = ['id' => $id, 'message' => 'Opmerkingen langer dan 600 tekens.'];
        if (mb_strlen((string) ($mapped['booking']['hoe_kent_u_geofort'] ?? ''), 'UTF-8') > 120) $longDiscoveryValues[] = ['id' => $id, 'message' => 'Discoverywaarde langer dan 120 tekens.'];
        foreach (LegacyBookingMapper::validateTargetCharacterCapacities($mapped['booking'], $columnLengths['aanvragen']) as $message) $errors[] = ['id' => $id, 'message' => $message];
        if ($mapped['possible_belgian']) $possibleBelgian[] = ['id' => $id, 'message' => $mapped['booking']['schoolnaam'] . ' — ' . $mapped['booking']['plaats']];
        if ($mapped['has_html_entities']) $htmlEntityRecords[] = ['id' => $id, 'message' => 'HTML-entiteiten worden gedecodeerd.'];
        $statusCounts[(string) $legacy['status']] = ($statusCounts[(string) $legacy['status']] ?? 0) + 1;
        $sectorKey = (string) ($mapped['booking']['onderwijs_sector'] ?? '[onbekend]'); $sectorCounts[$sectorKey] = ($sectorCounts[$sectorKey] ?? 0) + 1;
        $programKey = (string) $legacy['programma_duur']; $programCounts[$programKey] = ($programCounts[$programKey] ?? 0) + 1;
        $choiceKey = (string) ($mapped['booking']['keuzemodule_key'] ?? '[geen]'); $choiceCounts[$choiceKey] = ($choiceCounts[$choiceKey] ?? 0) + 1;
    }

    fwrite(STDOUT, "Bronaanvragen: " . count($legacyRows) . "\nHoofdaanvragen te inserten: " . count($bookings) . "\nOnderwijsselectierecords: " . count($selections) . "\n");
    printCounts('Per status', $statusCounts); printCounts('Per sector', $sectorCounts); printCounts('Per programma', $programCounts); printCounts('Per keuzemodule', $choiceCounts);
    printIssues('Mogelijke Belgische records', $possibleBelgian); printIssues('Records met HTML-entiteiten', $htmlEntityRecords);
    printIssues('Opmerkingen langer dan 600', $longComments); printIssues('Discoverywaarden langer dan 120', $longDiscoveryValues);
    printIssues('Waarschuwingen', $warnings); printIssues('Blokkerende fouten', $errors);
    fwrite(STDOUT, "\nControlecategorieën:\n");
    $categories = [
        'onbekende schooltypes' => 'schooltype', 'onbekende levels' => 'level', 'onbekende groepen' => 'groep',
        'ontbrekende levels' => 'ontbrekend niveau', 'levels zonder groepen' => 'zonder groepen',
        'opmerkingen > 600' => 'opmerkingen langer', 'hoe-kent-u-GeoFort > 120' => 'GeoFortwaarde langer',
        'dubbele IDs' => 'dubbel legacy-ID', 'dubbele level/groupcombinaties' => 'dubbele level/group',
        'aanvragen zonder leerlingen' => 'leerlingenaantal', 'aanvragen zonder begeleiders' => 'begeleidersaantal',
        'ongeldige status' => 'ongeldige status',
    ];
    foreach ($categories as $label => $needle) fwrite(STDOUT, "  {$label}: " . (countMatching($errors, $needle) + countMatching($warnings, $needle)) . "\n");
    fwrite(STDOUT, '  mogelijke Belgische records: ' . count($possibleBelgian) . "\n  records met HTML-entiteiten: " . count($htmlEntityRecords) . "\n");
    fwrite(STDOUT, "\nSamenvatting: waarschuwingen=" . count($warnings) . ', fouten=' . count($errors) . "\n");
    if ($errors !== []) {
        if ($options['execute']) throw new RuntimeException('Execute geblokkeerd door fouten in de consistente bronsnapshot.');
        fail('Execute geblokkeerd door preflightfouten.');
    }
    if (!$options['execute']) {
        fwrite(STDOUT, "Transactiestatus: GEEN MUTATIES (dry-run)\n");
        exit(0);
    }
    if ($bookings === [] || $selections === []) throw new RuntimeException('Lege bron of lege onderwijsselecties; replace wordt geweigerd.');
    executeMigration($pdo, $bookings, $selections, $statusCounts);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fail($e->getMessage());
}
