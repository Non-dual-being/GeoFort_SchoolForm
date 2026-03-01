<?php
declare(strict_types=1);

namespace GeoFort\Services\Http;

final class Request 
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $post,
        public readonly string $rawBody
    ) {}

    public static function fromGlobals(): self {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')){
                $name = str_replace('_', '-', strolower(substr($k, 5))
                );
                $headers[$name] = $v;
            }
        }

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $path,
            $headers,
            $_GET ?? [],
            $_POST ?? [],
            file_get_contents('php://input') ?: ''
        );

    }

    public function acceptsJson(): bool
    {
        $accept = strtolower($this->headers['accept'] ?? '');
        return str_contains($accept, 'application/json');
    }
}
?>