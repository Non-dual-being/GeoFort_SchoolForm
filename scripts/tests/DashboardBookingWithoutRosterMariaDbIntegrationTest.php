<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/Support/DisposableBookingMariaDb.php';

use GeoFort\Services\Dashboard\Booking\DashboardBookingFilterParser;
use GeoFort\Services\Dashboard\Booking\DashboardBookingListService;
use GeoFort\Services\Sql\DashboardBookingSqlService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = DisposableBookingMariaDb::create('booking_without_roster');

try {
    $pdo = $database->pdo;
    $root = dirname(__DIR__, 2);

    $pdo->exec(
        (string) file_get_contents(
            $root . '/database/sql/2026-09-21_create_roster_planning_foundation.sql',
        ),
    );

    $planningModeExists = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'roster_plans'
           AND COLUMN_NAME = 'planning_mode'",
    )->fetchColumn();

    if ($planningModeExists === 0) {
        $pdo->exec(
            (string) file_get_contents(
                $root . '/database/sql/2026-09-28_add_roster_planning_mode.sql',
            ),
        );
    }

    $adminExists = (int) $pdo->query(
        'SELECT COUNT(*) FROM admin_users WHERE id = 1',
    )->fetchColumn();
    $assert($adminExists === 1, 'Disposable database mist admin fixture id=1.');

    $insertBooking = $pdo->prepare(<<<'SQL'
        INSERT INTO aanvragen (
            status,
            schoolnaam,
            land,
            adres,
            postcode,
            plaats,
            school_telefoonnummer,
            contactpersoon_telefoonnummer,
            contactpersoon_voornaam,
            contactpersoon_achternaam,
            email,
            bezoekdatum,
            cjpPasGebruik,
            onderwijs_sector,
            programma,
            keuzemodule_key,
            aantal_leerlingen,
            aantal_begeleiders,
            voorwaarden_akkoord
        ) VALUES (
            'Definitief',
            :school,
            'Nederland',
            'Testweg 1',
            '4200 AA',
            'Gorinchem',
            '0100000000',
            '0612345678',
            'Test',
            'Planner',
            :email,
            :visit_date,
            'nee',
            'primairOnderwijs',
            'dag',
            'Minecraft-Klimaatspeurtocht',
            36,
            4,
            1
        )
        SQL);

    $bookingIds = [];

    for ($index = 1; $index <= 23; $index++) {
        $day = str_pad((string) $index, 2, '0', STR_PAD_LEFT);

        $insertBooking->execute([
            ':school' => "Without roster school {$index}",
            ':email' => "without-roster-{$index}@example.test",
            ':visit_date' => "2026-10-{$day}",
        ]);

        $bookingIds[$index] = (int) $pdo->lastInsertId();
    }

    $insertPlan = $pdo->prepare(<<<'SQL'
        INSERT INTO roster_plans (
            booking_id,
            planning_mode,
            visit_date,
            status,
            revision,
            source_booking_fingerprint,
            created_by_admin_id,
            updated_by_admin_id
        ) VALUES (
            :booking_id,
            :planning_mode,
            :visit_date,
            'concept',
            1,
            NULL,
            1,
            1
        )
        SQL);

    $insertPlan->execute([
        ':booking_id' => $bookingIds[1],
        ':planning_mode' => 'booking_locked',
        ':visit_date' => '2026-10-01',
    ]);
    $bookingLockedId = $bookingIds[1];

    $insertPlan->execute([
        ':booking_id' => $bookingIds[2],
        ':planning_mode' => 'custom',
        ':visit_date' => '2026-10-02',
    ]);
    $customLinkedId = $bookingIds[2];

    $parser = new DashboardBookingFilterParser();
    $sql = new DashboardBookingSqlService($pdo);
    $list = new DashboardBookingListService($sql);

    $baseFilters = $parser->parse([
        'page' => '1',
        'sort' => 'asc',
    ]);

    $assert(
        $sql->countBookings($baseFilters) === 23,
        'Baseline moet alle 23 testaanvragen bevatten.',
    );

    $withoutRosterFilters = null;
    $acceptedQueryValue = null;

    foreach (['1', 'true', 'yes'] as $candidate) {
        try {
            $parsed = $parser->parse([
                'page' => '1',
                'sort' => 'asc',
                'withoutRoster' => $candidate,
            ]);
        } catch (Throwable) {
            continue;
        }

        if (
            property_exists($parsed, 'withoutRoster')
            && $parsed->withoutRoster === true
        ) {
            $withoutRosterFilters = $parsed;
            $acceptedQueryValue = $candidate;
            break;
        }
    }

    $assert(
        $withoutRosterFilters !== null,
        'Parser kon withoutRoster niet als true activeren.',
    );

    $count = $sql->countBookings($withoutRosterFilters);
    $assert(
        $count === 21,
        "withoutRoster count verwacht 21, kreeg {$count}.",
    );

    $allFilteredRows = $sql->findBookings(
        $withoutRosterFilters,
        100,
        0,
    );
    $filteredIds = array_map(
        static fn (array $row): int => (int) $row['id'],
        $allFilteredRows,
    );

    $assert(
        count($filteredIds) === 21,
        'findBookings moet dezelfde 21 aanvragen teruggeven als countBookings.',
    );
    $assert(
        !in_array($bookingLockedId, $filteredIds, true),
        'Booking met booking_locked rooster werd niet uitgesloten.',
    );
    $assert(
        !in_array($customLinkedId, $filteredIds, true),
        'Booking met custom gekoppeld rooster werd niet uitgesloten.',
    );
    $assert(
        in_array($bookingIds[3], $filteredIds, true),
        'Booking zonder rooster ontbreekt ten onrechte.',
    );

    $page1 = $list->getPage($withoutRosterFilters);
    $page2Filters = $parser->parse([
        'page' => '2',
        'sort' => 'asc',
        'withoutRoster' => $acceptedQueryValue,
    ]);
    $page2 = $list->getPage($page2Filters);

    $assert($page1->totalItems === 21, 'Pagina 1 totalItems moet 21 zijn.');
    $assert($page1->totalPages === 2, '21 resultaten bij 20 per pagina moeten 2 paginas geven.');
    $assert($page1->currentPage === 1, 'Pagina 1 currentPage klopt niet.');
    $assert(count($page1->items) === 20, 'Pagina 1 moet 20 items bevatten.');
    $assert($page1->from === 1 && $page1->to === 20, 'Pagina 1 from/to klopt niet.');

    $assert($page2->totalItems === 21, 'Pagina 2 totalItems wijkt af van pagina 1.');
    $assert($page2->totalPages === 2, 'Pagina 2 totalPages klopt niet.');
    $assert($page2->currentPage === 2, 'Pagina 2 currentPage klopt niet.');
    $assert(count($page2->items) === 1, 'Pagina 2 moet precies 1 item bevatten.');
    $assert($page2->from === 21 && $page2->to === 21, 'Pagina 2 from/to klopt niet.');

    $pageIds = [];
    foreach ([$page1, $page2] as $page) {
        foreach ($page->items as $item) {
            $pageIds[] = $item->id;
        }
    }

    $assert(
        count($pageIds) === 21 && count(array_unique($pageIds)) === 21,
        'Paginering bevat dubbelen of mist resultaten.',
    );
    $assert(
        !in_array($bookingLockedId, $pageIds, true)
        && !in_array($customLinkedId, $pageIds, true),
        'Gekoppelde bookings lekken via paginering alsnog door.',
    );

    fwrite(
        STDOUT,
        "OK: withoutRoster MariaDB behavior passed "
        . "(23 bookings, 2 linked plans, 21 visible, pages 20+1).\n",
    );
} finally {
    $database->drop();
}