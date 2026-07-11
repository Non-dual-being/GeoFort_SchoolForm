<?php
declare(strict_types=1);

namespace GeoFort\Support;

final class FlashStore
{
    private const TYPES = ['info', 'success', 'error'];

    public static function add(string $key, string $message, string $type = 'info'): void
    {
        if (!in_array($type, self::TYPES, true)) {
            $type = 'info';
        }

        $_SESSION['_flash'][$key][] = ['message' => $message, 'type' => $type];
    }

    public static function get(string $key): array
    {
        $messages = $_SESSION['_flash'][$key] ?? [];
        return is_array($messages) ? $messages : [];
    }

    public static function pull(string $key): array
    {
        $messages = self::get($key);
        unset($_SESSION['_flash'][$key]);
        return $messages;
    }

    public static function all(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        return is_array($messages) ? $messages : [];
    }

    public static function clear(?string $key = null): void
    {
        if ($key === null) {
            unset($_SESSION['_flash']);
            return;
        }
        unset($_SESSION['_flash'][$key]);
    }
}
