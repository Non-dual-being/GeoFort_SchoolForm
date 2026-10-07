<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use LogicException;
use PDO;
use RuntimeException;

/** Shared lock order: catalog singleton, day, plan, booking, personnel/assignments. */
final class RosterStaffCatalogGuard
{
    public static function lock(PDO $pdo): int
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('The personnel catalog lock requires a transaction.');
        }
        $revision = $pdo->query('SELECT revision FROM roster_staff_catalog_state WHERE id = 1 FOR UPDATE')->fetchColumn();
        if ($revision === false) throw new RuntimeException('Personnel catalog schema/bootstrap is missing.');
        return (int) $revision;
    }

    public static function revision(PDO $pdo): int
    {
        $revision = $pdo->query('SELECT revision FROM roster_staff_catalog_state WHERE id = 1')->fetchColumn();
        if ($revision === false) throw new RuntimeException('Personnel catalog schema/bootstrap is missing.');
        return (int) $revision;
    }

    public static function expect(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw new RosterPlanException('STALE_CATALOG', 409, 'De personeelscatalogus is gewijzigd. Laad de actuele gegevens en controleer de wijziging opnieuw.');
        }
    }
}
