<?php

declare(strict_types=1);

namespace GeoFort\Services\Migration;

use PDO;
use RuntimeException;

final class LegacyDatabaseConfigGuard
{
    public static function assertSeparated(string $sourceHost,string $sourcePort,string $sourceName,string $targetHost,string $targetPort,string $targetName):void
    {
        $source=strtolower(trim($sourceHost).'|'.trim($sourcePort).'|'.trim($sourceName));$target=strtolower(trim($targetHost).'|'.trim($targetPort).'|'.trim($targetName));
        if(hash_equals($source,$target))throw new RuntimeException('Bron en doel mogen niet dezelfde database zijn.');
    }

    public static function assertDistinctConnections(PDO $source, PDO $target): void
    {
        self::assertDistinctIdentities(self::readIdentity($source), self::readIdentity($target));
    }

    /** @param array<string, string> $source @param array<string, string> $target */
    public static function assertDistinctIdentities(array $source, array $target): void
    {
        $sameDatabase = strcasecmp($source['database'], $target['database']) === 0;
        $sameUuid = $source['server_uuid'] !== '' && $target['server_uuid'] !== '' && hash_equals(strtolower($source['server_uuid']), strtolower($target['server_uuid']));
        $sameHostPort = strcasecmp($source['hostname'], $target['hostname']) === 0 && $source['port'] === $target['port'];
        if ($sameDatabase && ($sameUuid || $sameHostPort)) throw new RuntimeException('Bron en doel zijn feitelijk hetzelfde schema op dezelfde databaseserver.');
    }

    /** @return array<string, string> */
    private static function readIdentity(PDO $pdo): array
    {
        $row = $pdo->query('SELECT DATABASE() AS database_name, @@hostname AS server_hostname, @@port AS server_port')->fetch();
        if (!is_array($row) || trim((string) ($row['database_name'] ?? '')) === '') throw new RuntimeException('Database-identiteit kon niet read-only worden vastgesteld.');
        $uuid = '';
        try { $uuid = trim((string) $pdo->query('SELECT @@server_uuid')->fetchColumn()); } catch (\Throwable $ignored) { /* MariaDB heeft niet altijd server_uuid. */ }
        return ['database'=>trim((string)$row['database_name']),'hostname'=>trim((string)$row['server_hostname']),'port'=>(string)$row['server_port'],'server_uuid'=>$uuid];
    }
}
