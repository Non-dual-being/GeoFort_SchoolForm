<?php

declare(strict_types=1);

namespace GeoFort\Services\Dashboard\Roster;

use InvalidArgumentException;

final class RosterFinalSnapshot
{
    public const FORMAT_VERSION = 1;

    /** Canonical JSON: object keys sorted; ordered domain lists retain their order. */
    public static function canonicalJson(array $content): string
    {
        return json_encode(self::canonical($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(array $content): string
    {
        return hash('sha256', self::canonicalJson($content));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            foreach ($value as &$child) $child = self::canonical($child);
            unset($child);
            return $value;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) return $value;
        throw new InvalidArgumentException('Snapshot bevat een niet-ondersteund gegevenstype.');
    }
}
