<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$mode = $argv[1] ?? '';
if (!in_array($mode, ['inspect', 'migrate', 'rollback-check'], true)) {
    fwrite(STDERR, "Usage: php four-module-schema.php inspect|rollback-check|migrate [backup.sql.gz SHA256]\n"); exit(2);
}
try {
    $root = dirname(__DIR__, 2);
    require __DIR__ . '/FourModuleSchema.php';
    $container = require $root . '/bootstrap.php';
    $schema = new FourModuleSchema($container['db'][\GeoFort\Database\Connector::class], $root);
    if ($mode === 'migrate') {
        $backup = $argv[2] ?? ''; $sha = $argv[3] ?? '';
        if (getenv('FOUR_MODULE_MAINTENANCE_CONFIRMED') !== 'YES' || !is_file($backup) || filesize($backup) < 100
            || !preg_match('/^[a-f0-9]{64}$/', $sha) || !hash_equals($sha, hash_file('sha256', $backup))) {
            throw new RuntimeException('Migration requires confirmed maintenance and a verified, nonempty backup file with SHA256.');
        }
        $state = $schema->migrate();
    } else $state = $schema->inspect();
    echo json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    if ($mode === 'rollback-check' && !$state['codeRollbackAllowed']) exit(3);
} catch (Throwable $e) { fwrite(STDERR, 'STOP: ' . $e->getMessage() . PHP_EOL); exit(1); }
