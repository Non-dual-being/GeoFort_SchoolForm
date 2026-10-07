<?php

declare(strict_types=1);
require_once __DIR__ . '/RosterLifecycleFixtures.php';

use GeoFort\Services\Dashboard\Roster\{RosterSessionResetService, RosterAutoGenerator, RosterStaffOptimizer, RosterPlanException};

final class RosterResetRecordingPdo extends PDO
{
    public array $statements = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements[] = $query;
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        return parent::exec($statement);
    }

    public function writes(): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool =>
            preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $sql) === 1));
    }
}

final class RosterSessionResetFixtures
{
    public static function connection(string $database): RosterResetRecordingPdo
    {
        $host = getenv('STATUS_TEST_DB_HOST') ?: '127.0.0.1';
        if (getenv('STATUS_TEST_DB_CONFIRM') !== 'YES_DISPOSABLE'
            || !in_array($host, ['127.0.0.1', 'localhost'], true)
            || preg_match('/^geofort_reset[a-z0-9_]*_disposable_test_[a-f0-9]{8}$/D', $database) !== 1) {
            throw new RuntimeException('Only local disposable reset fixtures are allowed.');
        }
        $port = getenv('STATUS_TEST_DB_PORT') ?: '3307';
        return new RosterResetRecordingPdo("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            getenv('STATUS_TEST_DB_USER') ?: 'root', getenv('STATUS_TEST_DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }

    public static function services(PDO $pdo): array
    {
        $s = RosterLifecycleFixtures::services($pdo);
        $s['reset'] = new RosterSessionResetService($pdo, $s['plans'], $s['sessions']);
        $s['generator'] = new RosterAutoGenerator($pdo, $s['bookings'], $s['plans'], $s['sessions'], $s['config'], $s['templates'], $s['staff'], new RosterStaffOptimizer($s['staff']));
        return $s;
    }

    /** Full value comparison, including timestamps, audit, booking data and binary PDFs. */
    public static function state(PDO $pdo, bool $preservedOnly = false): array
    {
        $state = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (!preg_match('/^[a-z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe fixture table.');
            if ($preservedOnly && in_array($table, ['roster_sessions', 'roster_session_groups', 'roster_session_staff', 'roster_session_group_staff'], true)) continue;
            $hashes = [];
            foreach ($pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($preservedOnly && $table === 'roster_plans') unset($row['revision'], $row['updated_by_admin_id'], $row['updated_at']);
                $hashes[] = hash('sha256', serialize($row));
            }
            sort($hashes, SORT_STRING);
            $state[$table] = ['rows' => count($hashes), 'hash' => hash('sha256', implode("\n", $hashes))];
        }
        ksort($state);
        return $state;
    }

    public static function reject(callable $call, string $code): void
    {
        try { $call(); } catch (RosterPlanException $e) {
            RosterLifecycleFixtures::assert($e->publicCode === $code && in_array($e->httpStatus, [404, 409, 422], true), 'Expected ' . $code . ', got ' . $e->publicCode);
            return;
        }
        throw new RuntimeException('Missing rejection ' . $code);
    }

    public static function booking(PDO $pdo, string $date): int
    {
        $pdo->prepare("INSERT INTO aanvragen(status,schoolnaam,land,adres,postcode,plaats,school_telefoonnummer,contactpersoon_telefoonnummer,contactpersoon_voornaam,contactpersoon_achternaam,email,bezoekdatum,cjpPasGebruik,onderwijs_sector,programma,keuzemodule_key,aantal_leerlingen,aantal_begeleiders,voorwaarden_akkoord,eigen_picknick)
            VALUES('In optie','Ronde D testschoool','Nederland','Dijk 1','1234 AB','Plaats','0183123456','0612345678','Test','Planner','school@example.test',?,'nee','primairOnderwijs','dag','Earth-Watch',40,8,1,1)")->execute([$date]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO aanvraag_onderwijs_selecties(aanvraag_id,sector_key,level_key,level_position,group_key,group_position) VALUES (?,'primairOnderwijs','regulier',1,'groep7',1)")->execute([$id]);
        return $id;
    }
}

