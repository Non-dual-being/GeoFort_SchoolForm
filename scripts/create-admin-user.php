<?php
declare(strict_types=1);

use GeoFort\Services\Sql\AdminUsersSqlService;

if (PHP_SAPI !== 'cli') exit(1);
if ($argc !== 3) {
    fwrite(STDERR, "Gebruik: php scripts/create-admin-user.php admin@geofort.nl \"Naam beheerder\"\nGeef het wachtwoord niet als argument mee.\n");
    exit(1);
}
$email = strtolower(trim((string) $argv[1]));
$name = trim((string) $argv[2]);
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
    fwrite(STDERR, "Geef een geldig e-mailadres en een niet-lege naam op.\n");
    exit(1);
}

$readPassword = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $hidden = DIRECTORY_SEPARATOR !== '\\' && function_exists('shell_exec');
    if ($hidden) shell_exec('stty -echo');
    else fwrite(STDERR, "\nWaarschuwing: wachtwoordinvoer kan op dit platform niet betrouwbaar worden verborgen.\n");
    try {
        $value = fgets(STDIN);
    } finally {
        if ($hidden) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }
    return rtrim((string) $value, "\r\n");
};

$password = $readPassword('Wachtwoord: ');
$confirmation = $readPassword('Herhaal wachtwoord: ');
if ($password !== $confirmation || strlen($password) < 12) {
    fwrite(STDERR, "Wachtwoorden moeten gelijk zijn en minimaal 12 tekens bevatten.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
unset($password, $confirmation);
if (!is_string($hash)) {
    fwrite(STDERR, "Het wachtwoord kon niet veilig worden gehasht.\n");
    exit(1);
}
try {
    $container = require dirname(__DIR__) . '/bootstrap.php';
    $id = $container['sql'][AdminUsersSqlService::class]->createAdmin($email, $name, $hash);
    fwrite(STDOUT, "E-mail: {$email}\nNaam: {$name}\nID: {$id}\n");
} catch (Throwable $e) {
    error_log('[create-admin-user] ' . $e->getMessage());
    fwrite(STDERR, "Admin kon niet worden aangemaakt: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
