<?php

declare(strict_types=1);

use GeoFort\Services\Migration\RosterStaffBootstrapService;

final class RosterStaffManagementFixtures
{
    public static function schemaAndBootstrap(PDO $pdo): void
    {
        $root = dirname(__DIR__, 3);
        $pdo->exec((string) file_get_contents($root . '/database/sql/2026-10-05_add_roster_staff_management.sql'));
        (new RosterStaffBootstrapService($pdo, $root . '/database/seeds/roster_staff_v1.json'))->run(true);
    }
}
