<?php

declare(strict_types=1);

use GeoFort\Services\Sql\DashboardCalendarOverviewSqlRepository;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = [];
foreach (['HOST', 'PORT', 'NAME', 'USER'] as $key) {
    $value = getenv('STATUS_TEST_DB_' . $key);
    if ($value === false) { fwrite(STDOUT, "SKIP: STATUS_TEST_DB_{$key} ontbreekt.\n"); exit(0); }
    $env[$key] = (string) $value;
}
if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
    || preg_match('/(test|tmp|scratch|disposable)/i', $env['NAME']) !== 1
    || strtolower((string) getenv('APP_ENV')) === 'production') {
    fwrite(STDERR, "FAIL: integratietest weigert niet-aantoonbaar wegwerpbare database.\n"); exit(2);
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['HOST'], $env['PORT'], $env['NAME']),
    $env['USER'], (string) (getenv('STATUS_TEST_DB_PASSWORD') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$tables = ['booking_rule_overrides','booking_status_history','booking_change_history','aanvraag_onderwijs_selecties','aanvragen'];
$cleanup = static function () use ($pdo, $tables): void { foreach ($tables as $table) $pdo->exec("DROP TABLE IF EXISTS {$table}"); };
$cleanup();
try {
    $pdo->exec("CREATE TABLE aanvragen (id INT AUTO_INCREMENT PRIMARY KEY, bezoekdatum DATE NOT NULL, status VARCHAR(20) NOT NULL, programma VARCHAR(20) NOT NULL, aantal_leerlingen INT NULL, KEY idx_aanvragen_bezoekdatum_status(bezoekdatum,status)) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE aanvraag_onderwijs_selecties(id INT AUTO_INCREMENT PRIMARY KEY, aanvraag_id INT, payload VARCHAR(20)) ENGINE=InnoDB');
    foreach (['booking_rule_overrides','booking_status_history','booking_change_history'] as $table) $pdo->exec("CREATE TABLE {$table}(id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT, payload VARCHAR(20)) ENGINE=InnoDB");
    $insert = $pdo->prepare('INSERT INTO aanvragen(bezoekdatum,status,programma,aantal_leerlingen) VALUES(?,?,?,?)');
    foreach ([['2026-08-03','In optie','dag',40],['2026-08-03','In optie','dag',null],['2026-08-03','Definitief','ochtend',-4],['2026-08-04','Afgewezen','dag',0]] as $row) $insert->execute($row);
    foreach (range(1, 4) as $bookingId) {
        foreach (range(1, 3) as $child) {
            $pdo->prepare('INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,payload) VALUES(?,?)')->execute([$bookingId, "c{$child}"]);
            foreach (['booking_rule_overrides','booking_status_history','booking_change_history'] as $table) $pdo->prepare("INSERT INTO {$table}(booking_id,payload) VALUES(?,?)")->execute([$bookingId, "c{$child}"]);
        }
    }
    $cube = (new DashboardCalendarOverviewSqlRepository($pdo))->aggregateForRange('2026-08-01', '2026-08-31');
    if (count($cube['2026-08-03']) !== 2 || array_sum(array_column($cube['2026-08-03'], 'bookingCount')) !== 3) throw new RuntimeException('Childrecords vermenigvuldigen bookings.');
    $option = array_values(array_filter($cube['2026-08-03'], static fn (array $row): bool => $row['status'] === 'In optie'))[0];
    if ($option['studentCount'] !== 40 || $option['unknownStudentCount'] !== 1) throw new RuntimeException('Leerlingnormalisatie wijkt af.');
    $confirmed = array_values(array_filter($cube['2026-08-03'], static fn (array $row): bool => $row['status'] === 'Definitief'))[0];
    if ($confirmed['studentCount'] !== 0 || $confirmed['invalidStudentCount'] !== 1) throw new RuntimeException('Negatieve leerlingnormalisatie wijkt af.');
    $explain = $pdo->query("EXPLAIN SELECT bezoekdatum,status,programma,COUNT(*) FROM aanvragen WHERE bezoekdatum BETWEEN '2026-08-01' AND '2026-08-31' AND status IN ('In optie','Definitief','Afgewezen') AND programma IN ('dag','ochtend') GROUP BY bezoekdatum,status,programma")->fetch(PDO::FETCH_ASSOC);
    fwrite(STDOUT, 'EXPLAIN key=' . ($explain['key'] ?? 'NULL') . '; rows=' . ($explain['rows'] ?? '?') . "\n");
} finally { $cleanup(); }
fwrite(STDOUT, "Dashboard calendar overview MariaDB: OK\n");
