<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Booking\Stored\StoredBookingAssembler;
use GeoFort\Services\Dashboard\Roster\RosterAutoGenerator;
use GeoFort\Services\Dashboard\Roster\RosterGenerationTemplateProvider;
use GeoFort\Services\Dashboard\Roster\RosterPlanningConfig;
use GeoFort\Services\Dashboard\Roster\RosterStaffOptimizer;
use GeoFort\Services\Sql\RosterPlanSqlRepository;
use GeoFort\Services\Sql\RosterSessionSqlRepository;
use GeoFort\Services\Sql\RosterStaffSqlRepository;
use GeoFort\Services\Sql\StoredBookingSqlRepository;

$disposable = DisposableBookingMariaDb::create('d3c5_no_vi_compression');
$pdo = $disposable->pdo;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $root = dirname(__DIR__, 2);

    foreach ([
        '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
        '/database/sql/2026-09-21_create_roster_session_planning.sql',
        '/database/sql/2026-09-22_create_roster_staff_catalog.sql',
        '/database/sql/2026-09-22_create_roster_staff_assignments.sql',
        '/database/sql/2026-09-22_extend_roster_staff_roles.sql',
        '/database/sql/2026-09-25_create_roster_plan_staff_preferences.sql',
    ] as $migration) {
        $pdo->exec((string) file_get_contents($root . $migration));
    }
    require_once __DIR__ . '/Support/RosterStaffManagementFixtures.php';
    RosterStaffManagementFixtures::schemaAndBootstrap($pdo);

    $config = new RosterPlanningConfig();
    $templates = new RosterGenerationTemplateProvider();
    $bookings = new StoredBookingSqlRepository($pdo, new StoredBookingAssembler());
    $plans = new RosterPlanSqlRepository($pdo);
    $sessions = new RosterSessionSqlRepository($pdo);
    $staff = new RosterStaffSqlRepository($pdo);
    $optimizer = new RosterStaffOptimizer($staff);
    $generator = new RosterAutoGenerator(
        $pdo,
        $bookings,
        $plans,
        $sessions,
        $config,
        $templates,
        $staff,
        $optimizer,
    );

    $groups = [];
    for ($index = 1; $index <= 6; $index++) {
        $groups[] = ['id' => $index, 'label' => "Groep {$index}"];
    }

    $rounds = [
        ['label' => 'Ronde 1', 'startTime' => '10:15', 'endTime' => '11:00'],
        ['label' => 'Ronde 2', 'startTime' => '11:15', 'endTime' => '12:00'],
        ['label' => 'Ronde 3', 'startTime' => '12:00', 'endTime' => '12:45'],
    ];

    $modules = [];
    foreach ([
        'Stop-de-Klimaat-Klok',
        'Earth-Watch',
        'Dynamische-Globe',
    ] as $moduleKey) {
        $modules[] = [
            ...$config->moduleOption($moduleKey),
            'programModuleCount' => 3,
        ];
    }

    $method = new ReflectionMethod(
        RosterAutoGenerator::class,
        'buildLessonProposalCandidates',
    );

    $proposalForLevel = static function (
        int $level,
    ) use ($method, $generator, $groups, $rounds, $modules): array {
        $candidates = $method->invoke(
            $generator,
            $groups,
            $rounds,
            $modules,
            1,
            $level,
        );

        if (!is_array($candidates) || count($candidates) !== 1) {
            throw new RuntimeException(
                "Compressieniveau {$level} gaf geen eenduidige no-VI kandidaat.",
            );
        }

        return $candidates[0];
    };

    $requirementsByRound = static function (array $proposal): array {
        $result = [];
        foreach ($proposal as $session) {
            $start = (string) $session['startTime'];
            $result[$start] = ($result[$start] ?? 0)
                + (int) $session['minimumGeoFortStaff'];
        }
        ksort($result);
        return array_values($result);
    };

    $level1 = $proposalForLevel(1);
    $level2 = $proposalForLevel(2);
    $level3 = $proposalForLevel(3);

    $assert(
        $requirementsByRound($level1) === [6, 6, 6],
        'Niveau 1 moet DG delen, maar Stop en Earth nog niet.',
    );
    $assert(
        $requirementsByRound($level2) === [5, 5, 5],
        'Niveau 2 moet Stop dubbelgroep activeren.',
    );
    $assert(
        $requirementsByRound($level3) === [4, 4, 4],
        'Niveau 3 moet ook Earth Watch dubbelgroep activeren.',
    );

    foreach ($rounds as $round) {
        $sameRound = array_values(array_filter(
            $level3,
            static fn (array $session): bool =>
                $session['startTime'] === $round['startTime'],
        ));

        $assert(
            count($sameRound) === 3,
            'Op niveau 3 moeten per ronde precies drie fysieke sessies bestaan.',
        );

        $byModule = [];
        foreach ($sameRound as $session) {
            $byModule[$session['moduleKey']] = $session;
        }

        $assert(
            count($byModule['Stop-de-Klimaat-Klok']['groupIds'] ?? []) === 2,
            'Stop de Klok moet twee groepen in een gedeelde sessie krijgen.',
        );
        $assert(
            (int) ($byModule['Stop-de-Klimaat-Klok']['minimumGeoFortStaff'] ?? 0) === 1,
            'Stop de Klok dubbelgroep moet precies een begeleider vragen.',
        );
        $assert(
            count($byModule['Earth-Watch']['groupIds'] ?? []) === 2,
            'Earth Watch moet twee groepen in een gedeelde sessie krijgen.',
        );
        $assert(
            (int) ($byModule['Earth-Watch']['minimumGeoFortStaff'] ?? 0) === 1,
            'Earth Watch dubbelgroep moet precies een begeleider vragen.',
        );
        $assert(
            count($byModule['Dynamische-Globe']['groupIds'] ?? []) === 2,
            'Dynamische Globe moet twee groepen in een gedeelde sessie krijgen.',
        );
        $assert(
            (int) ($byModule['Dynamische-Globe']['minimumGeoFortStaff'] ?? 0) === 2,
            'Dynamische Globe dubbelgroep moet twee begeleiders blijven vragen.',
        );
    }

    $pdo->exec(<<<'SQL'
        INSERT INTO roster_staff_members
            (display_name, is_active, employment_type, can_guide, can_cook)
        VALUES
            ('ZZ Stop Guide', 1, 'volunteer', 1, 0),
            ('ZZ Earth Guide', 1, 'paid', 1, 0),
            ('ZZ DG Guide A', 1, 'paid', 1, 0),
            ('ZZ DG Guide B', 1, 'paid', 1, 0)
        SQL);

    $ids = [];
    foreach ([
        'ZZ Stop Guide',
        'ZZ Earth Guide',
        'ZZ DG Guide A',
        'ZZ DG Guide B',
    ] as $name) {
        $statement = $pdo->prepare(
            'SELECT id FROM roster_staff_members WHERE display_name = :name',
        );
        $statement->execute([':name' => $name]);
        $ids[$name] = (int) $statement->fetchColumn();
    }

    $insertPreference = $pdo->prepare(<<<'SQL'
        INSERT INTO roster_staff_module_preferences
            (staff_id, module_key, preference_rank)
        VALUES
            (:staff_id, :module_key, 1)
        SQL);

    $insertPreference->execute([
        ':staff_id' => $ids['ZZ Stop Guide'],
        ':module_key' => 'Stop-de-Klimaat-Klok',
    ]);
    $insertPreference->execute([
        ':staff_id' => $ids['ZZ Earth Guide'],
        ':module_key' => 'Earth-Watch',
    ]);
    foreach (['ZZ DG Guide A', 'ZZ DG Guide B'] as $name) {
        $insertPreference->execute([
            ':staff_id' => $ids[$name],
            ':module_key' => 'Dynamische-Globe',
        ]);
    }

    $fourStaff = array_values($ids);
    $optimizedFour = $optimizer->optimize(
        $level3,
        $fourStaff,
        true,
        null,
    );

    $assert(
        (int) $optimizedFour['unfilledRequiredAssignments'] === 0,
        'Vier geschikte begeleiders moeten het no-VI rooster volledig kunnen sluiten.',
    );

    $threeStaff = [
        $ids['ZZ Stop Guide'],
        $ids['ZZ Earth Guide'],
        $ids['ZZ DG Guide A'],
    ];
    $optimizedThree = $optimizer->optimize(
        $level3,
        $threeStaff,
        true,
        null,
    );

    $assert(
        (int) $optimizedThree['unfilledRequiredAssignments'] === 3,
        'Met drie begeleiders moet per ronde precies een verplichte plek open blijven.',
    );

    $openIndexes = array_values(array_map(
        'intval',
        $optimizedThree['unfilledRequiredSessionIndexes'],
    ));
    $assert(
        count($openIndexes) === 3,
        'Met drie begeleiders moeten drie DG-sessies over de dag een open plek hebben.',
    );

    foreach ($openIndexes as $sessionIndex) {
        $assert(
            ($level3[$sessionIndex]['moduleKey'] ?? null) === 'Dynamische-Globe',
            'De open plek met drie begeleiders hoort bij de tweede DG-begeleider.',
        );
    }

    fwrite(
        STDOUT,
        "OK: D3C5 no-VI compression + four-person staffing integration passed.\n",
    );
} finally {
    $disposable->drop();
}