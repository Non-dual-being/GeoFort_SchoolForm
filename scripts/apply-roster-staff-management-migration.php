<?php

declare(strict_types=1);

// Default: read-only preflight. Use the existing environment configuration, never CLI credentials.
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root)->load();
$options = getopt('', ['execute', 'database:', 'fresh-install', 'backup-file:', 'backup-sha256:']);
$env = static fn (string $key, string $default = ''): string => $_ENV[$key] ?? $_SERVER[$key] ?? (($value = getenv($key)) !== false ? (string) $value : $default);
$database = $env('DB_NAME');
if (($options['database'] ?? '') !== $database || !preg_match('/^[A-Za-z0-9_]+$/D', $database)) throw new RuntimeException('Supply --database with the exact configured database name.');
$host = $env('DB_HOST'); $port = $env('DB_PORT', '3306');
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $env('DB_USER'), $env('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$migration = new GeoFort\Services\Migration\RosterStaffManagementMigration($pdo, $root);
$state = $migration->inspect();
echo json_encode(['target' => $host . ':' . $port . '/' . $database, 'preflight' => $state], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
if (!isset($options['execute'])) exit($state['schemaState'] === 'partial' ? 1 : 0);
$backup = $options['backup-file'] ?? ''; $hash = $options['backup-sha256'] ?? '';
if (!is_string($backup) || !is_file($backup) || filesize($backup) < 1 || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !hash_equals($hash, hash_file('sha256', $backup))) throw new RuntimeException('A verified schema+data backup (--backup-file, --backup-sha256) is mandatory before execution.');
$result = $migration->apply(isset($options['fresh-install']));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
