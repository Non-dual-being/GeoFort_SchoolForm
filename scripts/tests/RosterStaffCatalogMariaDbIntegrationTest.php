<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Services\Dashboard\Roster\RosterStaffConfigService;
use GeoFort\Services\Sql\RosterStaffSqlRepository;

$disposable = DisposableBookingMariaDb::create('roster_staff_catalog');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

try {
    $root = dirname(__DIR__, 2);
    $pdo->exec((string) file_get_contents(
        $root . '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
    ));
    $pdo->exec((string) file_get_contents(
        $root . '/database/sql/2026-09-22_create_roster_staff_catalog.sql',
    ));
    $pdo->exec((string) file_get_contents(
        $root . '/database/sql/2026-09-22_extend_roster_staff_roles.sql',
    ));

    $members = (int) $pdo->query('SELECT COUNT(*) FROM roster_staff_members')->fetchColumn();
    $preferences = (int) $pdo->query(
        'SELECT COUNT(*) FROM roster_staff_module_preferences',
    )->fetchColumn();

    $assert($members === 20, 'Personeelsseed moet 20 medewerkers bevatten inclusief Gab en Nelleke.');
    $assert($preferences === 86, 'Personeelsvoorkeuren bevatten niet de verwachte 86 regels.');

    $service = new RosterStaffConfigService(new RosterStaffSqlRepository($pdo));
    $catalog = $service->catalog();

    $byName = [];
    foreach ($catalog as $member) {
        $byName[$member['name']] = $member;
    }

    $assert(isset($byName['Gab Franken']), 'Gab Franken ontbreekt.');
    $assert($byName['Gab Franken']['employmentType'] === 'volunteer', 'Gab moet vrijwilliger zijn.');
    $assert($byName['Gab Franken']['canGuide'] === true, 'Gab moet begeleider kunnen zijn.');
    $assert($byName['Gab Franken']['canCook'] === true, 'Gab moet kok kunnen zijn via VI.');
    $assert(
        array_column($byName['Gab Franken']['preferences'], 'moduleKey') === [
            'Voedsel-Innovatie',
            'Stop-de-Klimaat-Klok',
            'Klimaat-Experience',
            'Dynamische-Globe',
            'Earth-Watch',
        ],
        'Gab voorkeurvolgorde klopt niet.',
    );

    $assert(isset($byName['Nelleke de With']), 'Nelleke de With ontbreekt.');
    $assert($byName['Nelleke de With']['canGuide'] === false, 'Nelleke mag niet begeleiden.');
    $assert($byName['Nelleke de With']['canCook'] === true, 'Nelleke moet kok kunnen zijn.');
    $assert($byName['Nelleke de With']['preferences'] === [], 'Nelleke mag geen lesmodulevaardigheden hebben.');

    foreach ([
        'Frank Duijnhouwer',
        'Frank van Kessel',
        'Piet Visser',
        'Ries Euser',
        'Wiert Ruben',
        'Gab Franken',
    ] as $volunteer) {
        $assert(
            ($byName[$volunteer]['employmentType'] ?? null) === 'volunteer',
            "{$volunteer} moet als vrijwilliger zijn gemarkeerd.",
        );
    }

    fwrite(STDOUT, "OK: roster staff catalog MariaDB integration passed.\n");
} finally {
    $disposable->drop();
}
