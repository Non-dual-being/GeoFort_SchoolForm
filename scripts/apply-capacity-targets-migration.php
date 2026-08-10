<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use GeoFort\Services\Sql\CapacityTargetSchemaInspector;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

$env = static function (string $name, ?string $fallback = null): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if (($value === false || $value === '') && $fallback !== null) return $fallback;
    if (!is_string($value) || $value === '') throw new RuntimeException("Omgevingsvariabele {$name} ontbreekt.");
    return $value;
};

try {
    $database = $env('DB_NAME');
    $password = (string) ($_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? getenv('DB_PASS') ?: $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '');
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST'), $env('DB_PORT', '3306'), $database),
        $env('DB_USER'),
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $selected = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($selected === '' || !hash_equals($database, $selected)) {
        throw new RuntimeException('De geselecteerde database komt niet overeen met DB_NAME; migratie afgebroken.');
    }

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $isMariaDb = stripos($version, 'mariadb') !== false;
    $numericVersion = preg_match('/\d+\.\d+\.\d+/', $version, $matches) === 1 ? $matches[0] : '0.0.0';
    $minimum = $isMariaDb ? '10.2.1' : '8.0.16';
    if (version_compare($numericVersion, $minimum, '<')) {
        throw new RuntimeException("Databaseversie {$numericVersion} ondersteunt de vereiste afgedwongen CHECK-constraints niet (minimum {$minimum}).");
    }

    $inspector = new CapacityTargetSchemaInspector($pdo);
    $exists = $inspector->tableExists();
    $execute = in_array('--execute', $argv, true);
    $repairedLegacyUnsigned = false;
    if (!$exists && !$execute) {
        fwrite(STDOUT, "Preflight geslaagd voor database {$selected} op {$version}. Gebruik --execute om de migratie toe te passen.\n");
        exit(0);
    }
    if (!$exists) {
        $sql = file_get_contents($root . '/database/sql/2026-08-07_create_capacity_targets.sql');
        if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('Het migratiebestand kon niet worden gelezen.');
        $pdo->exec($sql);
    } elseif ($execute && $inspector->usesLegacyUnsignedTargetColumns()) {
        $pdo->exec(
            'ALTER TABLE capacity_targets
             MODIFY students_per_available_day SMALLINT NOT NULL,
             MODIFY bookings_per_available_day DECIMAL(2,1) NOT NULL',
        );
        $repairedLegacyUnsigned = true;
    }

    $issues = $inspector->issues();
    if ($issues !== []) {
        fwrite(STDERR, "SCHEMA_MISMATCH: capacity_targets bestaat, maar wijkt af. Er is niets gewijzigd.\n- " . implode("\n- ", $issues) . "\n");
        exit(2);
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM capacity_targets')->fetchColumn();
    $result = !$exists
        ? 'migratie toegepast en schema gevalideerd'
        : ($repairedLegacyUnsigned ? 'eerdere UNSIGNED-variant veilig hersteld en schema gevalideerd' : 'bestaand schema gevalideerd; niets gewijzigd');
    fwrite(STDOUT, "Capacity targets: {$result}. Database={$selected}; versie={$version}; rijen={$count}.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migratie afgebroken: ' . $exception->getMessage() . "\n");
    exit(1);
}
